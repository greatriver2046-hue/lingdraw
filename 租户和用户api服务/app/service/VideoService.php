<?php
namespace app\service;

use app\model\ModelConfig;
use app\model\User;
use app\model\SaasInstance;
use app\model\TenantModelCallStat;
use app\service\video\VideoProviderInterface;
use app\service\video\SoraDuomiVideoProvider;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Filesystem;
use GuzzleHttp\Client;

class VideoService
{
    protected $providers = [
        'sora2' => SoraDuomiVideoProvider::class,
        'test-video' => \app\service\video\TestVideoProvider::class,
    ];

    /**
     * Validate if user has enough points
     */
    public function validatePoints(string $identity, array $options, $userId)
    {
        $modelConfig = $this->getModelConfig($identity);
        if (!$modelConfig) {
             throw new \Exception('No active configuration found for: ' . $identity);
        }

        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $cost = $this->calculateTotalCost($modelConfig, $options);
                $available = ($user->period_points ?? 0) + ($user->extra_points ?? 0);
                if ($cost > 0 && $available < $cost) {
                    throw new \Exception("您的点数不足，无法进行视频生成。本次生成需消耗 {$cost} 点。");
                }
            }
        }
    }

    public function generateAsync(string $prompt, array $options = [], $userId = null): array
    {
        Log::info("VideoService generateAsync called. Options: " . json_encode($options, JSON_UNESCAPED_UNICODE));
        $taskId = bin2hex(random_bytes(16));
        $payload = [
            'task_id' => $taskId,
            'prompt' => $prompt,
            'options' => $options,
            'user_id' => $userId,
        ];
        
        // Initialize task status in Redis/Cache to prevent 404 before Job starts
        $statusArr = [ 'status' => 'queued', 'updated_at' => time() ];
        $cfg = config('queue.connections.redis');
        $redis = class_exists('Redis') ? new \Redis() : null;
        $saved = false;
        
        if ($redis) {
            try {
                $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
                if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
                if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
                // Use video_task prefix for video tasks
                $redis->hMSet('video_task:' . $taskId, $statusArr);
                $saved = true;
            } catch (\Throwable $e) {}
        }
        
        if (!$saved) {
            Cache::set('video_task:' . $taskId, $statusArr, 3600);
        }

        \think\facade\Queue::push('app\\job\\VideoGenerateJob', $payload, 'default');
        
        return ['task_id' => $taskId, 'status' => 'queued'];
    }

    public function generate(string $prompt, array $options = [], $userId = null): array
    {
        $identity = $options['model_identity'] ?? 'sora2';
        
        Log::info("VideoService Generate: Requested identity={$identity}");

        $modelConfig = $this->getModelConfig($identity);
        if (!$modelConfig) {
            // Try fallback if identity is different from what's in DB (e.g. tool name vs model ID)
            // But user said model identifier is sora2.
            throw new \Exception('No active configuration found in model_configs for: ' . $identity);
        }
        
        Log::info("VideoService Generate: Found config ID={$modelConfig['id']} Identity={$modelConfig['model_identity']}");

        $taskId = $options['task_id'] ?? null;
        $deductedCost = 0;
        $cost = 0;
        $tenant = null;

        // Points deduction
        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $cost = (int)ceil((float)$this->calculateTotalCost($modelConfig, $options));
                if ($cost > 0) {
                    $desc = "Video generation with " . ($modelConfig['model_identity'] ?? 'unknown');
                    $breakdown = [];
                    $tenant = SaasInstance::find($user->tenant_id);
                    if ($tenant) {
                        if (!$tenant->updateQuota($cost)) {
                            throw new \Exception("租户剩余额度不足，无法进行视频生成。本次生成需消耗 {$cost} 点。");
                        }
                    }

                    if (!$user->deductPoints($cost, 'video_generation', $desc, $taskId, $breakdown)) {
                        if ($tenant) {
                            $tenant->updateQuota(-$cost);
                        }
                        throw new \Exception("您的点数不足，无法进行视频生成。本次生成需消耗 {$cost} 点。");
                    }
                    $deductedCost = $cost;
                    if ($taskId) {
                        Cache::set('video_task_cost:' . $taskId, [
                            'user_id' => $userId, 
                            'cost' => $cost,
                            'breakdown' => $breakdown
                        ], 7200);
                    }
                }
            }
        }

        if ($modelConfig instanceof ModelConfig) {
            $modelConfig->incrementCallCount();
        }

        if ($deductedCost > 0 && $user && $user->tenant_id) {
            TenantModelCallStat::addPointsForTenant($user->tenant_id, $modelConfig->id, $deductedCost);
        }

        $providerClass = $this->providers[$modelConfig['model_identity']] ?? null;
        if (!$providerClass) {
             // Fallback partial match
             foreach ($this->providers as $key => $class) {
                if (stripos($modelConfig['model_identity'], $key) !== false) {
                    $providerClass = $class;
                    Log::info("VideoService: Fuzzy matched {$modelConfig['model_identity']} to provider key {$key}");
                    break;
                }
            }
        }
        
        Log::info("VideoService: Selected Provider Class: " . ($providerClass ?: 'None'));
        
        if (!$providerClass) {
            // If user mapped 'sora2' to 'SoraDuomiVideoProvider' but DB has 'sora2', it should match first check.
            // If DB has 'openai-sora', we might need to update providers map.
            // Assuming DB has 'sora2'.
            // Refund before throw
            if ($taskId && $deductedCost > 0) {
                $this->refundPoints($taskId);
            }
            throw new \Exception('Video provider not supported: ' . $modelConfig['model_identity']);
        }

        try {
            /** @var VideoProviderInterface $provider */
            $provider = new $providerClass();
            $response = $provider->generate($prompt, $modelConfig->toArray(), $options);
            
            // If successful, remove refund record
            if ($taskId) {
                Cache::delete('video_task_cost:' . $taskId);
            }
        } catch (\Throwable $e) {
            if ($taskId && $deductedCost > 0) {
                $this->refundPoints($taskId);
            }
            throw $e;
        }
        
        // Handle result
        $result = ['videos' => []];
        
        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $item) {
                if (isset($item['url'])) {
                    $storedUrl = $this->storeVideo($item['url']);
                    $result['videos'][] = ['url' => $storedUrl ?: $item['url']];
                }
            }
        }
        
        return $result;
    }

    protected function getModelConfig($identity)
    {
        // Try 'active' first, then 1 (compatibility)
        $conf = ModelConfig::where('model_identity', $identity)->where('status', 'active')->find();
        if (!$conf) {
            $conf = ModelConfig::where('model_identity', $identity)->where('status', 1)->find();
        }
        return $conf;
    }
    
    protected function storeVideo($url)
    {
        try {
            $client = new Client(['timeout' => 300, 'verify' => false]);
            $resp = $client->get($url);
            if ($resp->getStatusCode() === 200) {
                $content = $resp->getBody()->getContents();
                if ($content) {
                    $ext = 'mp4';
                    $contentType = $resp->getHeaderLine('Content-Type');
                    if (stripos($contentType, 'video/mp4') !== false) $ext = 'mp4';
                    
                    // Try OSS upload first
                    $ossCfg = $this->getOssConfig();
                    if ($ossCfg) {
                        $ossUrl = $this->uploadToOss($content, $ext, $ossCfg);
                        if ($ossUrl) {
                            return $ossUrl;
                        }
                    }

                    // Fallback to local
                    $filename = uniqid('vid_') . '.' . $ext;
                    $path = 'generated/' . date('Ymd') . '/' . $filename;
                    
                    Filesystem::disk('public')->put($path, $content);
                    return '/storage/' . $path;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to store video $url: " . $e->getMessage());
        }
        return null;
    }

    protected function getOssConfig(): ?array
    {
        try {
            $row = \think\facade\Db::table('system_configs')->where('category', 'oss')->find();
            if (!$row) return null;
            if (isset($row['config'])) {
                $cfg = is_array($row['config']) ? $row['config'] : json_decode($row['config'], true);
                return is_array($cfg) ? $cfg : null;
            }
            $keys = ['access_key_id','access_key_secret','endpoint','bucket','domain','prefix','security_token','skip_ssl_verify','ca_bundle'];
            $cfg = [];
            foreach ($keys as $k) {
                if (isset($row[$k])) $cfg[$k] = $row[$k];
            }
            return !empty($cfg) ? $cfg : null;
        } catch (\Throwable $e) {
            Log::warning('getOssConfig failed: ' . $e->getMessage());
            return null;
        }
    }

    protected function uploadToOss(string $binary, string $ext, array $cfg): ?string
    {
        try {
            $ak = $cfg['access_key_id'] ?? null;
            $sk = $cfg['access_key_secret'] ?? null;
            $endpoint = $cfg['endpoint'] ?? null;
            $bucket = $cfg['bucket'] ?? null;
            if (!$ak || !$sk || !$endpoint || !$bucket) {
                return null;
            }

            $prefix = $cfg['prefix'] ?? 'generated';
            $dateDir = date('Ymd');
            $objectKey = trim($prefix, '/') . '/' . $dateDir . '/' . uniqid('vid_', true) . '.' . $ext;
            $endpointHost = preg_replace('#^https?://#', '', trim($endpoint));
            $endpointHost = rtrim($endpointHost, '/');
            
            if (stripos($endpointHost, 'oss-accesspoint.aliyuncs.com') !== false) {
                $host = $endpointHost;
                $url = 'https://' . $host . '/' . $bucket . '/' . $objectKey;
            } else {
                $host = $bucket . '.' . $endpointHost;
                $url = 'https://' . $host . '/' . $objectKey;
            }

            $contentType = 'video/' . ($ext ?: 'mp4');
            $date = gmdate('D, d M Y H:i:s \G\M\T');

            // OSS headers
            $ossHeaders = [
                'x-oss-object-acl' => 'public-read',
            ];
            if (!empty($cfg['security_token'])) {
                $ossHeaders['x-oss-security-token'] = $cfg['security_token'];
            }

            // Canonicalized OSS headers
            ksort($ossHeaders);
            $canonicalHeaders = '';
            foreach ($ossHeaders as $k => $v) {
                $canonicalHeaders .= strtolower($k) . ':' . $v . "\n";
            }

            $resource = '/' . $bucket . '/' . $objectKey;
            $stringToSign = "PUT\n\n{$contentType}\n{$date}\n" . $canonicalHeaders . $resource;
            $signature = base64_encode(hash_hmac('sha1', $stringToSign, $sk, true));
            $auth = 'OSS ' . $ak . ':' . $signature;

            $headers = [
                'Authorization' => $auth,
                'Date' => $date,
                'Content-Type' => $contentType,
                'Content-Length' => strlen($binary),
            ];
            // Merge OSS headers
            foreach ($ossHeaders as $k => $v) {
                $headers[$k] = $v;
            }

            $client = new Client(['timeout' => 120, 'verify' => false]);
            $resp = $client->put($url, [
                'headers' => $headers,
                'body' => $binary
            ]);

            if ($resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300) {
                if (!empty($cfg['domain'])) {
                    return rtrim($cfg['domain'], '/') . '/' . $objectKey;
                }
                return $url;
            }
            Log::warning("OSS upload failed: status=" . $resp->getStatusCode() . " body=" . $resp->getBody()->getContents());
            return null;
        } catch (\Throwable $e) {
            Log::warning('uploadToOss failed: ' . $e->getMessage());
            return null;
        }
    }

    protected function calculateTotalCost($modelConfig, $options)
    {
        $baseCost = isset($modelConfig['cost_per_request']) ? (float)$modelConfig['cost_per_request'] : 0;
        $totalCost = $baseCost;

        Log::info("VideoService CalculateCost Start: Base={$baseCost}, Options=" . json_encode($options));

        // Map option key from frontend to config field in model
        $configMap = [
            'size' => 'size_config',
            'duration' => 'duration_config',
            'quality' => 'quality_config',
            'resolution' => ['quality_config', 'size_config', 'resolution_config'], // Frontend sends 'resolution', check all possible fields
            'aspect_ratio' => 'aspect_ratio_config',
        ];

        foreach ($configMap as $optionKey => $configKeys) {
            if (isset($options[$optionKey])) {
                $selectedValue = $options[$optionKey];
                Log::info("VideoService Check Option: Key={$optionKey}, Value={$selectedValue}");
                
                // If configKeys is a string, convert to array for uniform handling
                if (is_string($configKeys)) {
                    $configKeys = [$configKeys];
                }
                
                $found = false;
                foreach ($configKeys as $configKey) {
                    $configList = $modelConfig[$configKey] ?? [];
                    Log::info("VideoService Check Config: Field={$configKey}, Count=" . (is_array($configList) || is_object($configList) ? count((array)$configList) : 'not_array'));
                    
                    // Decode if string (in case Model didn't auto-decode or raw array usage)
                    if (is_string($configList)) {
                        $configList = json_decode($configList, true);
                    }

                    // Config is expected to be a list of objects (from JSON)
                    if (is_array($configList) || is_object($configList)) {
                            foreach ($configList as $item) {
                                $item = (array)$item;
                                // Log::info("VideoService Item: " . json_encode($item));
                                $isMatch = false;
                                if (isset($item['value'])) {
                                    if ($item['value'] == $selectedValue) {
                                        $isMatch = true;
                                    } elseif ($optionKey === 'duration') {
                                        // Handle duration mismatch (e.g. 10 vs 10s)
                                        $valStr = (string)$item['value'];
                                        $selStr = (string)$selectedValue;
                                        if ($valStr === $selStr . 's' || $valStr === $selStr . 'S') {
                                            $isMatch = true;
                                        }
                                    }
                                }

                                if ($isMatch) {
                                    $price = 0;
                                    if (isset($item['price'])) {
                                        $price = (float)$item['price'];
                                    } elseif (isset($item['cost'])) {
                                        $price = (float)$item['cost'];
                                    }
                                    $totalCost += $price;
                                    $found = true;
                                    Log::info("VideoService Match Found: +{$price}, NewTotal={$totalCost}");
                                    break;
                                }
                            }
                        }
                    if ($found) break;
                }
                if (!$found) {
                    Log::info("VideoService No Match for {$optionKey}={$selectedValue}");
                }
            }
        }

        Log::info("VideoService CalculateCost End: Total={$totalCost}");
        return $totalCost;
    }

    public function refundPoints($taskId)
    {
        $key = 'video_task_cost:' . $taskId;
        // Use pull to atomically get and delete to prevent race conditions
        $data = Cache::pull($key);

        if ($data && isset($data['user_id'], $data['cost'])) {
            $userId = $data['user_id'];
            $cost = $data['cost'];
            
            $user = User::find($userId);
            if ($user) {
                if (isset($data['breakdown'])) {
                    $user->refundPoints(
                        $data['breakdown']['period'] ?? 0, 
                        $data['breakdown']['extra'] ?? 0, 
                        'video_refund', 
                        "Refund for task {$taskId}", 
                        $taskId
                    );
                } else {
                    // Fallback for old tasks or if breakdown missing
                    $user->changePoints($cost, 'video_refund', "Refund for task {$taskId}", $taskId);
                }
                
                Log::info("Refunded {$cost} points to user {$userId} for task {$taskId}");
                
                // Also revert tenant quota if possible?
                $tenant = SaasInstance::find($user->tenant_id);
                // Assuming updateQuota adds to used. We should subtract.
                // Since updateQuota($cost) does `used_quota += $cost` (usually), passing negative subtracts.
                if ($tenant) {
                    $tenant->updateQuota(-$cost);
                }

                return true;
            }
        }
        return false;
    }
}
