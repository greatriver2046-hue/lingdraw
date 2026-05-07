<?php
namespace app\service;

use app\model\ModelConfig;
use app\model\ImageGeneration;
use app\model\SystemErrorLog;
use app\model\TenantModelCallStat;
use think\facade\Db;
use think\facade\Filesystem;
use GuzzleHttp\Client;
use app\model\User;
use app\model\SaasInstance;
use app\service\image\OpenAIImageProvider;
use app\service\image\ImageProviderInterface;
use app\service\image\SeedreamImageProvider;
use app\service\image\NanoBananaProImageProvider;
use app\service\image\AntigravityImageProvider;
use app\service\image\AliyunImageSegmentationProvider;
use app\service\image\QwenImageEditProvider;
use app\service\image\GptImage2Provider;
use app\service\image\ApiyiGptImage2Provider;
use think\facade\Log;
use think\facade\Cache;

class ImageService
{
    protected $providers = [
        'openai_image' => OpenAIImageProvider::class,
        'openai' => OpenAIImageProvider::class,
        'seedream' => SeedreamImageProvider::class,
        'seedream3.0' => SeedreamImageProvider::class,
        'seedream4.0' => SeedreamImageProvider::class,
        'seedream4.5' => SeedreamImageProvider::class,
        'seedream5.0' => SeedreamImageProvider::class,
        'seedream5' => SeedreamImageProvider::class,
        'duomi-nano-banana-pro' => NanoBananaProImageProvider::class,
        'nanobananapro' => NanoBananaProImageProvider::class, // Keep for backward compatibility if needed
        'banana' => NanoBananaProImageProvider::class,
        'gemini-3-pro-image-antigravity' => AntigravityImageProvider::class,
        'imageseg' => AliyunImageSegmentationProvider::class,
        'imageseg_hd' => AliyunImageSegmentationProvider::class,
        'imageHDseg' => AliyunImageSegmentationProvider::class,
        'qwen-image-edit-max' => QwenImageEditProvider::class,
        'qwen-image-edit-plus' => QwenImageEditProvider::class,
        'gpt-image-2' => GptImage2Provider::class,
        'gpt-image-2-apiyi' => ApiyiGptImage2Provider::class,
        'gpt-image-2-all-apiyi' => ApiyiGptImage2Provider::class,
    ];

    protected function isTruthyFlag($flag): bool
    {
        if (is_bool($flag)) return $flag;
        if (is_numeric($flag)) return ((int)$flag) === 1;
        if (is_string($flag)) return in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'y'], true);
        return false;
    }

    protected function parseSizeToWh($size): array
    {
        if (!is_string($size)) return [0, 0];
        $s = trim($size);
        if ($s === '') return [0, 0];

        if (strpos($s, 'x') !== false) {
            $parts = explode('x', $s);
            if (count($parts) === 2) {
                $w = (int)trim($parts[0]);
                $h = (int)trim($parts[1]);
                return [$w, $h];
            }
        }

        if (preg_match('/^(\d{3,5})\s*(\d{3,5})$/', $s, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }

        return [0, 0];
    }

    protected function ensurePoseEditMinPixels(array $options, int $minPixels = 3686400): array
    {
        if (!$this->isTruthyFlag($options['is_pose_edit'] ?? false)) return $options;
        if ($minPixels <= 0) return $options;

        $w = isset($options['width']) ? (int)$options['width'] : 0;
        $h = isset($options['height']) ? (int)$options['height'] : 0;

        if (($w <= 0 || $h <= 0) && isset($options['size'])) {
            [$pw, $ph] = $this->parseSizeToWh($options['size']);
            if ($pw > 0 && $ph > 0) {
                $w = $pw;
                $h = $ph;
            }
        }

        if ($w <= 0 || $h <= 0) return $options;

        $area = $w * $h;
        if ($area >= $minPixels) {
            $options['width'] = $w;
            $options['height'] = $h;
            $options['size'] = $w . 'x' . $h;
            if (isset($options['resolution']) && ($options['resolution'] === null || $options['resolution'] === '')) {
                $options['resolution'] = $options['size'];
            }
            return $options;
        }

        $scale = sqrt($minPixels / max(1, $area));
        $nw = (int)ceil($w * $scale);
        $nh = (int)ceil($h * $scale);

        $options['width'] = $nw;
        $options['height'] = $nh;
        $options['size'] = $nw . 'x' . $nh;
        if (!isset($options['resolution']) || $options['resolution'] === null || $options['resolution'] === '') {
            $options['resolution'] = $options['size'];
        } else {
            $options['resolution'] = $options['size'];
        }

        return $options;
    }

    public function compressImage(string $binary, int $targetSize = 102400)
    {
        if (strlen($binary) <= $targetSize) {
            return $binary;
        }

        try {
            $im = @imagecreatefromstring($binary);
            if (!$im) return $binary;

            $width = imagesx($im);
            $height = imagesy($im);
            $quality = 80;
            $scale = 1.0;
            
            // Heuristic: if it's huge, scale down immediately
            if (strlen($binary) > 2 * 1024 * 1024) { 
                 $scale = 0.5;
            }

            $tempStream = fopen('php://memory', 'r+');
            $attempt = 0;
            $finalBinary = $binary;
            $success = false;

            do {
                $attempt++;
                ftruncate($tempStream, 0);
                rewind($tempStream);

                $targetW = intval($width * $scale);
                $targetH = intval($height * $scale);
                
                // Create canvas
                if ($scale < 1.0) {
                    $canvas = imagecreatetruecolor($targetW, $targetH);
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
                    imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $transparent);
                    imagecopyresampled($canvas, $im, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
                    $processIm = $canvas;
                } else {
                    $processIm = $im;
                }

                // Convert to JPEG with white background for maximum compression
                // This sacrifices transparency for size
                $bg = imagecreatetruecolor(imagesx($processIm), imagesy($processIm));
                $white = imagecolorallocate($bg, 255, 255, 255);
                imagefilledrectangle($bg, 0, 0, imagesx($processIm), imagesy($processIm), $white);
                imagecopy($bg, $processIm, 0, 0, 0, 0, imagesx($processIm), imagesy($processIm));
                
                imagejpeg($bg, $tempStream, $quality);
                imagedestroy($bg);
                
                if ($scale < 1.0) {
                    imagedestroy($processIm);
                }

                $stats = fstat($tempStream);
                $currSize = $stats['size'];
                
                if ($currSize <= $targetSize) {
                    rewind($tempStream);
                    $finalBinary = stream_get_contents($tempStream);
                    $success = true;
                    break;
                }
                
                // Adjustment strategy
                if ($quality > 30) {
                    $quality -= 10;
                } else {
                    $scale *= 0.8; // Reduce dimensions
                    $quality = 80; // Reset quality
                }

            } while ($attempt < 10);

            fclose($tempStream);
            imagedestroy($im);
            
            return $success ? $finalBinary : $binary;

        } catch (\Throwable $e) {
            Log::warning('Compression failed: ' . $e->getMessage());
            return $binary;
        }
    }

    public function createThumbnail(string $imageUrl, int $width, int $height): ?string
    {
        try {
            $client = new Client(['timeout' => 30, 'verify' => false]);
            $response = $client->get($imageUrl);
            if ($response->getStatusCode() !== 200) return null;
            $binary = $response->getBody()->getContents();
            if (!$binary) return null;

            $im = @imagecreatefromstring($binary);
            if (!$im) return null;

            $origW = imagesx($im);
            $origH = imagesy($im);
            
            // Calculate square crop based on shortest side
            $size = min($origW, $origH);
            $srcX = (int)(($origW - $size) / 2);
            $srcY = (int)(($origH - $size) / 2);
            
            $thumb = imagecreatetruecolor($width, $height);
            // Preserve transparency
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
            imagefilledrectangle($thumb, 0, 0, $width, $height, $transparent);
            
            imagecopyresampled($thumb, $im, 0, 0, $srcX, $srcY, $width, $height, $size, $size);
            
            ob_start();
            imagepng($thumb);
            $thumbBinary = ob_get_clean();
            
            imagedestroy($im);
            imagedestroy($thumb);
            
            return $this->storeBinary($thumbBinary, 'png', 'thumbnails');
        } catch (\Throwable $e) {
            Log::warning('createThumbnail failed: ' . $e->getMessage());
            return null;
        }
    }


    public function generateAsync(string $prompt, array $options = [], $userId = null): array
    {
        try {
            $taskId = bin2hex(random_bytes(16));
            
            // Ensure options is fully sanitized (recursive array check if needed, but for now just catch errors)
            // Prevent recursive array to string conversion in Queue serialization if any
            
            $payload = [
                'task_id' => $taskId,
                'prompt' => $prompt,
                'options' => $options,
                'user_id' => $userId,
            ];
            
            // Initialize task status in Redis/Cache to prevent 404 before Job starts
            $statusArr = [ 'status' => 'queued', 'updated_at' => (string)time() ];
            $cfg = config('queue.connections.redis');
            $redis = class_exists('Redis') ? new \Redis() : null;
            $saved = false;
            
            if ($redis) {
                try {
                    $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
                    if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
                    if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
                    $redis->hMSet('image_task:' . $taskId, $statusArr);
                    $saved = true;
                } catch (\Throwable $e) {}
            }
            
            if (!$saved) {
                Cache::set('image_task:' . $taskId, $statusArr, 3600);
            }
    
            \think\facade\Queue::push('app\\job\\ImageGenerateJob', $payload, 'default');
            
            return ['task_id' => $taskId, 'status' => 'queued'];
        } catch (\Throwable $e) {
            Log::error("ImageService::generateAsync Error: " . $e->getMessage() . " File: " . $e->getFile() . " Line: " . $e->getLine());
            throw $e; // Re-throw to be caught by AgentService for full error reporting
        }
    }

    public function generate(string $prompt, array $options = [], $userId = null): array
    {
        // Normalize resolution to size/width/height if provided
        if (isset($options['resolution']) && $options['resolution']) {
            $options['size'] = $options['resolution'];
            $parts = explode('x', $options['resolution']);
            if (count($parts) === 2) {
                $options['width'] = (int)$parts[0];
                $options['height'] = (int)$parts[1];
            }
        }

        if (!empty($options['is_upscale'])) {
            $w = isset($options['width']) ? (int)$options['width'] : 0;
            $h = isset($options['height']) ? (int)$options['height'] : 0;
            if (($w <= 0 || $h <= 0) && !empty($options['size']) && is_string($options['size']) && strpos($options['size'], 'x') !== false) {
                $parts = explode('x', $options['size']);
                if (count($parts) === 2) {
                    $w = (int)$parts[0];
                    $h = (int)$parts[1];
                }
            }

            if ($w > 0 && $h > 0) {
                $maxSide = 4096;
                $maxDim = max($w, $h);
                if ($maxDim < $maxSide) {
                    $scale = $maxSide / $maxDim;
                    $w = (int)ceil($w * $scale);
                    $h = (int)ceil($h * $scale);
                }
                $options['width'] = $w;
                $options['height'] = $h;
                $options['size'] = $w . 'x' . $h;
                if (!isset($options['resolution']) || $options['resolution'] === null || $options['resolution'] === '') {
                    $options['resolution'] = $options['size'];
                }
            }
        }

        $startTime = microtime(true);
        $user = null;
        $deductedCost = 0;
        $deductionBreakdown = ['period' => 0, 'extra' => 0];
        $taskId = $options['task_id'] ?? null;

        $identity = $options['model_identity'] ?? null;
        if (is_string($identity) && trim($identity) === '') {
            $identity = null;
            unset($options['model_identity']);
        }

        if (!empty($options['is_upscale']) && !$identity) {
            try {
                $defaultModels = Db::table('system_configs')->where('category', 'default_models')->value('config');
                if ($defaultModels) {
                    $dmConfig = json_decode($defaultModels, true);
                    if (is_array($dmConfig) && !empty($dmConfig['upscale_model'])) {
                        $identity = $dmConfig['upscale_model'];
                        $options['model_identity'] = $identity;
                    }
                }
            } catch (\Throwable $e) {
            }

            if (!$identity) {
                throw new \Exception('未配置图片高清化模型，请联系管理员');
            }
        }
        $modelConfig = $this->getModelConfig($identity);
        if (!$modelConfig) {
            if ($identity) {
                throw new \Exception("Model config for '{$identity}' not found or not active.");
            }
            throw new \Exception('No active IMAGE configuration found in model_configs.');
        }

        $prompt = $this->applyPoseEditPrompt($prompt, $options);
        $useParamAdapt = $this->isTruthyFlag($options['use_param_adapt'] ?? ($options['use_image_param_adapt'] ?? false));
        if (!$useParamAdapt && $this->isTruthyFlag($options['is_pose_edit'] ?? false)) {
            $useParamAdapt = true;
        }
        if ($useParamAdapt) {
            $options = $this->applyImageParamAdapt($prompt, $modelConfig, $options);
        }
        $options = $this->ensurePoseEditMinPixels($options, 3686400);
        unset($options['use_param_adapt'], $options['use_image_param_adapt']);

        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $cost = (int)ceil((float)$this->calculateTotalCost($modelConfig, $options));
                
                Log::info("Deducting points for user {$userId}, cost: {$cost}");
                if ($cost > 0) {
                    $desc = "Image generation with " . ($modelConfig['model_identity'] ?? 'unknown');
                    if (isset($options['resolution'])) {
                        $desc .= " (" . $options['resolution'] . ")";
                    }
                    $tenant = SaasInstance::find($user->tenant_id);
                    if ($tenant) {
                        if (!$tenant->updateQuota($cost)) {
                            throw new \Exception("租户剩余额度不足，无法进行图片生成。每次调用需消耗 {$cost} 点。");
                        }
                    }

                    if (!$user->deductPoints($cost, 'image_generation', $desc, $taskId, $deductionBreakdown)) {
                        if (isset($tenant) && $tenant) {
                            $tenant->updateQuota(-$cost);
                        }
                        throw new \Exception("您的点数不足，无法进行图片生成。每次调用需消耗 {$cost} 点。");
                    }

                    $deductedCost = $cost;

                    if ($taskId) {
                        Cache::set('image_task_cost:' . $taskId, [
                            'user_id' => $userId,
                            'cost' => $cost,
                            'breakdown' => $deductionBreakdown
                        ], 7200);
                    }
                }
            }
        }

        try {
            $debugLogFile = runtime_path() . 'log/imageservice_' . date('Ymd') . '.log';
            $debugLog = function($msg) use ($debugLogFile) {
                $ts = date('Y-m-d H:i:s');
                @file_put_contents($debugLogFile, "[{$ts}] {$msg}\n", FILE_APPEND);
            };
            $debugLog("=== ImageService generate() START ===");
            $debugLog("model_identity=" . ($options['model_identity'] ?? 'null'));
            $debugLog("modelConfig identity=" . ($modelConfig['model_identity'] ?? 'null'));
            $debugLog("modelConfig provider_code=" . ($modelConfig['provider_code'] ?? 'null'));
            
            if ($modelConfig instanceof ModelConfig) {
                Log::info("Incrementing call count for image model {$modelConfig['model_identity']}");
                $modelConfig->incrementCallCount();
            }

            if ($deductedCost > 0 && $user && $user->tenant_id) {
                TenantModelCallStat::addPointsForTenant($user->tenant_id, $modelConfig->id, $deductedCost);
            }
    
            $providerKey = trim((string)($modelConfig['provider_code'] ?? ''));
            $debugLog("providerKey='{$providerKey}'");
            if ($providerKey !== '') {
                $providerClass = $this->providers[$providerKey] ?? null;
            } else {
                $providerClass = $this->providers[$modelConfig['model_identity']] ?? null;
            }
            $debugLog("providerClass=" . var_export($providerClass, true));
            if (!$providerClass) {
                foreach ($this->providers as $key => $class) {
                    if (stripos((string)$modelConfig['model_identity'], (string)$key) !== false) {
                        $providerClass = $class;
                        $debugLog("matched provider by stripos: {$key}");
                        break;
                    }
                }
            }
            if (!$providerClass) {
                throw new \Exception('Image provider not supported: ' . $modelConfig['model_identity']);
            }

            /** @var ImageProviderInterface $provider */
            $provider = new $providerClass();

            // Remove tool_meta from options before passing to provider to avoid array conversion errors
            $providerOptions = $options;
            if (isset($providerOptions['tool_meta'])) {
                unset($providerOptions['tool_meta']);
            }

            $response = $provider->generate($prompt, $modelConfig->toArray(), $providerOptions);

            $unified = $this->formatResponse($response, $modelConfig['model_identity']);

            // Convert any base64 images to URL by saving to public/uploads, and proxy external URLs to OSS/Local
            foreach ($unified['images'] as $idx => $img) {
                if (isset($img['b64'])) {
                    $url = $this->saveBase64AsUrl($img['b64'], 'png');
                    if ($url) {
                        $unified['images'][$idx] = ['url' => $url];
                    }
                } elseif (isset($img['url'])) {
                    // Attempt to download and store locally/OSS to improve access speed and persistence
                    try {
                        $client = new Client(['timeout' => 120, 'verify' => false]);
                        $resp = $client->get($img['url']);
                        if ($resp->getStatusCode() === 200) {
                            $content = $resp->getBody()->getContents();
                            if ($content) {
                                $ext = 'png';
                                $contentType = $resp->getHeaderLine('Content-Type');
                                if (stripos($contentType, 'image/jpeg') !== false) $ext = 'jpg';
                                elseif (stripos($contentType, 'image/png') !== false) $ext = 'png';
                                elseif (stripos($contentType, 'image/webp') !== false) $ext = 'webp';
                                else {
                                    $path = parse_url($img['url'], PHP_URL_PATH);
                                    if ($path) {
                                        $e = pathinfo($path, PATHINFO_EXTENSION);
                                        if ($e) $ext = $e;
                                    }
                                }
                                
                                // Use storeBinary which handles OSS or local storage
                                $newUrl = $this->storeBinary($content, $ext, 'generated');
                                if ($newUrl) {
                                    $unified['images'][$idx] = ['url' => $newUrl];
                                    
                                    // Log to image_assets
                                    try {
                                        Db::table('image_assets')->insert([
                                            'tenant_id' => $user ? $user->tenant_id : null,
                                            'user_id' => $user ? $user->id : null,
                                            'category' => 'image',
                                            'type' => 'ai_matting',
                                            'url' => $newUrl,
                                            'created_at' => date('Y-m-d H:i:s'),
                                        ]);
                                    } catch (\Throwable $ex) {}
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning("Failed to proxy external image {$img['url']}: " . $e->getMessage());
                    }
                }
            }

            // Save generation history (success)
            try {
                $firstImage = $unified['images'][0] ?? null;
                $sizeStr = $options['size'] ?? null;
                $width = $options['width'] ?? null;
                $height = $options['height'] ?? null;
                if (!$sizeStr && $width && $height) {
                    $sizeStr = $width . 'x' . $height;
                }

                ImageGeneration::create([
                    'tenant_id'      => $user ? $user->tenant_id : null,
                    'user_id'        => $user ? $user->id : null,
                    'prompt'         => $prompt,
                    'model_identity' => $modelConfig['model_identity'] ?? null,
                    'model_id'       => $modelConfig['model_id'] ?? null,
                    'width'          => $width,
                    'height'         => $height,
                    'size'           => $sizeStr,
                    'options'        => !empty($options) ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                    'image_url'      => isset($firstImage['url']) ? $firstImage['url'] : null,
                    'image_b64'      => null,
                    'status'         => 'success',
                    'error_msg'      => null,
                    'created_at'     => date('Y-m-d H:i:s'),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);

                // Log each generated image into image_assets
                try {
                    $tenantId = $user ? $user->tenant_id : null;
                    $userIdVal = $user ? $user->id : null;
                    foreach ($unified['images'] as $imgItem) {
                        $imgUrl = $imgItem['url'] ?? null;
                        if ($imgUrl) {
                            \think\facade\Db::table('image_assets')->insert([
                                'tenant_id' => $tenantId,
                                'user_id' => $userIdVal,
                                'category' => 'image',
                                'type' => 'ai_generated',
                                'url' => $imgUrl,
                                'created_at' => date('Y-m-d H:i:s'),
                            ]);
                        }
                    }
                } catch (\Throwable $ex) {}
            } catch (\Throwable $e) {
                Log::warning('ImageGeneration history save failed: ' . $e->getMessage());
            }

            $duration = round((microtime(true) - $startTime) * 1000);
            Log::info('Image generation done in ' . $duration . 'ms via ' . $modelConfig['model_identity']);

            if ($taskId) {
                Cache::delete('image_task_cost:' . $taskId);
            }

            return $unified;
        } catch (\Throwable $e) {
            if ($taskId && $deductedCost > 0) {
                $this->refundPoints($taskId);
            }
            throw $e;
        }
    }

    protected function calculateTotalCost($modelConfig, $options)
    {
        $baseCost = isset($modelConfig['cost_per_request']) ? (float)$modelConfig['cost_per_request'] : 0;
        $totalCost = $baseCost;

        // Ensure resolution option is set if size is provided, to allow resolution_config matching
        if (!isset($options['resolution']) && isset($options['size'])) {
            $options['resolution'] = $options['size'];
        }

        // Map option key from frontend to config field in model
        $configMap = [
            'resolution' => 'resolution_config',
            'size'       => 'size_config',
        ];

        // Extract width/height for 2k/4k judgment
        $width = isset($options['width']) ? (int)$options['width'] : 0;
        $height = isset($options['height']) ? (int)$options['height'] : 0;
        
        if ($width <= 0 && $height <= 0 && isset($options['size'])) {
             $parts = explode('x', $options['size']);
             if (count($parts) === 2) {
                 $width = (int)$parts[0];
                 $height = (int)$parts[1];
             }
        }

        // Determine label based on dimensions (Judge 2k/4k)
        $resolutionLabel = null;
        if ($width > 0 && $height > 0) {
            $maxDim = max($width, $height);
            if ($maxDim >= 3000) { // Adjusted 4k threshold (approx 3072+)
                $resolutionLabel = '4k';
            } elseif ($maxDim >= 1500) { // Adjusted 2k threshold (includes 1792, 1536 etc)
                $resolutionLabel = '2k';
            }
        }
        
        Log::info("ImageService Cost Calc: Base={$baseCost}, Size={$width}x{$height}, Label=" . ($resolutionLabel ?: 'none'));

        foreach ($configMap as $optionKey => $configKey) {
            if (!empty($modelConfig[$configKey])) {
                $configList = $modelConfig[$configKey];

                // Decode if string
                if (is_string($configList)) {
                    $configList = json_decode($configList, true);
                }
                
                if (is_array($configList) || is_object($configList)) {
                    $matched = false;
                    
                    // 1. Try exact match with option value (e.g. "1024x1024") OR swapped dimensions
                    if (isset($options[$optionKey])) {
                         $selectedValue = $options[$optionKey];
                         if (is_array($selectedValue)) $selectedValue = json_encode($selectedValue);
                         $selectedValue = (string)$selectedValue;

                         // Prepare swapped value if it looks like WxH
                         $swappedValue = null;
                         if (strpos($selectedValue, 'x') !== false) {
                             $parts = explode('x', $selectedValue);
                             if (count($parts) === 2) {
                                 $swappedValue = $parts[1] . 'x' . $parts[0];
                             }
                         }

                         foreach ($configList as $item) {
                            $item = (array)$item;
                            if (isset($item['value']) && ($item['value'] == $selectedValue || ($swappedValue && $item['value'] == $swappedValue))) {
                                $price = isset($item['price']) ? (float)$item['price'] : (isset($item['cost']) ? (float)$item['cost'] : 0);
                                $totalCost += $price;
                                $matched = true;
                                Log::info("ImageService: Exact/Swapped match found for {$selectedValue}, +{$price}");
                                break; 
                            }
                        }
                    }
                    
                    // 2. If no exact match, try the inferred label (2k/4k)
                    if (!$matched && $resolutionLabel) {
                         foreach ($configList as $item) {
                            $item = (array)$item;
                            // Check case-insensitive match for value or label
                            $itemValue = $item['value'] ?? '';
                            $itemLabel = $item['label'] ?? '';
                            
                            if (
                                (strcasecmp($itemValue, $resolutionLabel) === 0) ||
                                (strcasecmp($itemLabel, $resolutionLabel) === 0)
                            ) {
                                $price = isset($item['price']) ? (float)$item['price'] : (isset($item['cost']) ? (float)$item['cost'] : 0);
                                $totalCost += $price;
                                $matched = true;
                                Log::info("ImageService: Label match found for {$resolutionLabel}, +{$price}");
                                break; 
                            }
                        }
                    }
                    
                    if ($matched) break; 
                }
            }
        }

        return $totalCost;
    }

    public function matting(string $imageUrl, array $options = [], $userId = null): array
    {
        $startTime = microtime(true);
        $user = null;
        $deductedCost = 0;
        $deductionBreakdown = ['period' => 0, 'extra' => 0];

        // 1. Identify image dimensions to decide between normal and HD matting
        $isHd = false;
        try {
            $imageInfo = @getimagesize($imageUrl);
            if ($imageInfo) {
                $width = $imageInfo[0];
                $height = $imageInfo[1];
                if ($width > 2000 || $height > 2000) {
                    $isHd = true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to get image size for matting: " . $e->getMessage());
        }

        $identity = $options['model_identity'] ?? null;
        
        // Prioritize remove_bg_model from system config if identity is not provided
        // This overrides size-based selection if a specific global default is set
        if (!$identity) {
             try {
                 $defaultModels = \think\facade\Db::table('system_configs')->where('category', 'default_models')->value('config');
                 if ($defaultModels) {
                     $dm = json_decode($defaultModels, true);
                     if (!empty($dm['remove_bg_model'])) {
                         $identity = $dm['remove_bg_model'];
                     }
                 }
             } catch (\Throwable $e) {}
        }

        $targetType = $isHd ? 'imageseg_hd' : 'imageseg';
        Log::info("Auto-selecting matting model type: {$targetType} for image {$imageUrl}. Preferred Identity: " . ($identity ?: 'none'));

        $modelConfig = $this->getModelConfig($identity, $targetType);
        
        // If HD is needed but not configured, fallback to normal if possible (though it might fail at Aliyun side)
        if (!$modelConfig && $isHd) {
            Log::warning("HD matting requested but no active imageseg_hd config found. Falling back to imageseg.");
            $modelConfig = $this->getModelConfig($identity, 'imageseg');
        }

        if (!$modelConfig) {
            throw new \Exception("No active {$targetType} configuration found in model_configs.");
        }

        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $cost = (int)ceil((float)($modelConfig['cost_per_request'] ?? 0));
                if ($cost > 0) {
                    $desc = ($isHd ? "HD " : "") . "Image matting with " . ($modelConfig['model_identity'] ?? 'unknown');
                    $tenant = SaasInstance::find($user->tenant_id);
                    if ($tenant) {
                        if (!$tenant->updateQuota($cost)) {
                            throw new \Exception("租户剩余额度不足，无法进行抠图。每次调用需消耗 {$cost} 点。");
                        }
                    }

                    if (!$user->deductPoints($cost, 'image_matting', $desc, null, $deductionBreakdown)) {
                        if (isset($tenant) && $tenant) {
                            $tenant->updateQuota(-$cost);
                        }
                        throw new \Exception("您的点数不足，无法进行抠图。每次调用需消耗 {$cost} 点。");
                    }
                    $deductedCost = $cost;
                }
            }
        }

        try {
            if ($modelConfig instanceof ModelConfig) {
                $modelConfig->incrementCallCount();
            }

            if ($deductedCost > 0 && $user && $user->tenant_id) {
                TenantModelCallStat::addPointsForTenant($user->tenant_id, $modelConfig->id, $deductedCost);
            }

            $providerKey = trim((string)($modelConfig['provider_code'] ?? ''));
            if ($providerKey !== '') {
                $providerClass = $this->providers[$providerKey] ?? null;
            } else {
                $providerClass = $this->providers[$modelConfig['model_identity']] ?? null;
            }
            if (!$providerClass) {
                foreach ($this->providers as $key => $class) {
                    if (stripos($modelConfig['model_identity'], $key) !== false) {
                        $providerClass = $class;
                        break;
                    }
                }
            }
            
            // Fallback for backward compatibility or if type implies imageseg
            if (!$providerClass && ($modelConfig['model_type'] === 'imageseg' || $modelConfig['model_type'] === 'imageseg_hd')) {
                 $providerClass = $this->providers['imageseg'] ?? AliyunImageSegmentationProvider::class;
            }

            if (!$providerClass) {
                throw new \Exception('Image provider not supported for matting: ' . $modelConfig['model_identity']);
            }

            /** @var ImageProviderInterface $provider */
            $provider = new $providerClass();

            // Prepare config array and ensure model_type is present
            $providerConfig = $modelConfig->toArray();
            if (!isset($providerConfig['model_type']) && isset($modelConfig->model_type)) {
                $providerConfig['model_type'] = $modelConfig->model_type;
            }

            // Aliyun provider expects imageUrl in prompt
            $response = $provider->generate($imageUrl, $providerConfig, $options);
            
            // Format response to unified structure
            $unified = $this->formatResponse($response, $modelConfig['model_identity']);
            
            // Proxy external URLs (Aliyun OSS links expire)
            foreach ($unified['images'] as $idx => $img) {
                if (isset($img['url'])) {
                    try {
                        // Log::info("Proxying matting image: " . $img['url']);
                        $client = new Client(['timeout' => 60, 'verify' => false]);
                        $resp = $client->get($img['url']);
                        if ($resp->getStatusCode() === 200) {
                            $content = $resp->getBody()->getContents();
                            if ($content) {
                                $newUrl = $this->storeBinary($content, 'png', 'matting');
                                if ($newUrl) {
                                    $unified['images'][$idx]['url'] = $newUrl;
                                    
                                    // Log to image_assets
                                    try {
                                        Db::table('image_assets')->insert([
                                            'tenant_id' => $user ? $user->tenant_id : null,
                                            'user_id' => $user ? $user->id : null,
                                            'category' => 'image',
                                            'type' => 'ai_matting',
                                            'url' => $newUrl,
                                            'created_at' => date('Y-m-d H:i:s'),
                                        ]);
                                    } catch (\Throwable $ex) {}
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning("Failed to proxy matting image [{$img['url']}]: " . $e->getMessage());
                        // If proxy fails, keep original URL but log it
                    }
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000);
            Log::info('Image matting done in ' . $duration . 'ms using ' . ($modelConfig['model_identity'] ?? 'unknown'));

            // Save to generation history
            try {
                ImageGeneration::create([
                    'tenant_id'      => $user ? $user->tenant_id : null,
                    'user_id'        => $user ? $user->id : null,
                    'prompt'         => '[' . ($isHd ? 'HD ' : '') . 'Matting] ' . $imageUrl,
                    'model_identity' => $modelConfig['model_identity'] ?? null,
                    'model_id'       => $modelConfig['model_id'] ?? null,
                    'options'        => !empty($options) ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                    'image_url'      => $unified['images'][0]['url'] ?? null,
                    'status'         => 'success',
                    'created_at'     => date('Y-m-d H:i:s'),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                Log::warning('ImageGeneration history save failed for matting: ' . $e->getMessage());
            }

            return $unified;
        } catch (\Throwable $e) {
            if ($deductedCost > 0 && $user) {
                 $user->refundPoints(
                    $deductionBreakdown['period'], 
                    $deductionBreakdown['extra'], 
                    'image_matting_failed', 
                    "Matting failed: " . substr($e->getMessage(), 0, 50)
                );
                $tenant = SaasInstance::find($user->tenant_id);
                if ($tenant) $tenant->updateQuota(-$deductedCost);
            }
            throw $e;
        }
    }

    protected function getModelConfig($identity = null, $type = 'image')
    {
        if ($identity) {
            $config = ModelConfig::where('model_identity', $identity)
                ->where('status', 'active')
                ->find();
            
            // If not found by identity, try model_id
            if (!$config) {
                $config = ModelConfig::where('model_id', $identity)
                    ->where('status', 'active')
                    ->find();
            }

            if ($config) {
                return $config;
            }
            
            // If identity was specified but not found, return null immediately (no fallback)
            return null;
        }

        $dmConfig = [];
        // 1. Try default active model for the given type from system_configs
        try {
            $defaultModels = \think\facade\Db::table('system_configs')->where('category', 'default_models')->value('config');
            if ($defaultModels) {
                $dmConfig = json_decode($defaultModels, true);
                
                // Map frontend config keys to backend expected keys
                if (($type === 'imageseg' || $type === 'imageseg_hd') && !empty($dmConfig['remove_bg_model'])) {
                    // If backend specific keys are missing, use remove_bg_model
                    if (empty($dmConfig['default_imageseg_model'])) {
                        $dmConfig['default_imageseg_model'] = $dmConfig['remove_bg_model'];
                    }
                    if (empty($dmConfig['default_imageseg_hd_model'])) {
                         $dmConfig['default_imageseg_hd_model'] = $dmConfig['remove_bg_model'];
                    }
                }

                $configKey = 'default_' . $type . '_model';
                
                if (!empty($dmConfig[$configKey])) {
                     // Try finding by model_identity first
                     $config = ModelConfig::where('model_identity', $dmConfig[$configKey])
                        ->where('status', 'active')
                        ->find();
                     
                     // If not found, try model_id (just in case)
                     if (!$config) {
                        $config = ModelConfig::where('model_id', $dmConfig[$configKey])
                            ->where('status', 'active')
                            ->find();
                     }

                     if ($config) {
                         return $config;
                     }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 1.5 Try to find by is_default=1 (Legacy compatibility or if system_configs not used)
        try {
            $config = ModelConfig::where('model_type', $type)
                ->where('status', 'active')
                ->where('is_default', 1)
                ->find();
            if ($config) {
                return $config;
            }
        } catch (\Throwable $e) {
            // is_default column might not exist
        }

        // 1.6 Special fallback for imageseg: if no default imageseg found, try default imageseg_hd
        if ($type === 'imageseg') {
            // Try system_configs for imageseg_hd
            try {
                if (isset($dmConfig['default_imageseg_hd_model'])) {
                    $config = ModelConfig::where('model_identity', $dmConfig['default_imageseg_hd_model'])
                        ->where('status', 'active')
                        ->find();
                     if ($config) return $config;
                }
            } catch (\Throwable $e) {}

            // Try is_default for imageseg_hd
            try {
                $config = ModelConfig::where('model_type', 'imageseg_hd')
                    ->where('status', 'active')
                    ->where('is_default', 1)
                    ->find();
                if ($config) return $config;
            } catch (\Throwable $e) {}
        }

        // 2. Fallback: Get the first active model of this type
        return ModelConfig::where('model_type', $type)
            ->where('status', 'active')
            ->find();
    }

    protected function formatResponse($response, $identity): array
    {
        $images = [];
        $usage = [];

        if (stripos($identity, 'openai') !== false || stripos($identity, 'seedream') !== false || stripos($identity, 'nanobananapro') !== false || stripos($identity, 'duomi-nano-banana-pro') !== false || stripos($identity, 'gemini-3-pro-image-antigravity') !== false || stripos($identity, 'gpt-image-2') !== false) {
            if (isset($response['data']) && is_array($response['data'])) {
                foreach ($response['data'] as $item) {
                    if (isset($item['url'])) {
                        $images[] = ['url' => $item['url']];
                    } elseif (isset($item['b64_json'])) {
                        $images[] = ['b64' => $item['b64_json']];
                    }
                }
            } elseif (isset($response['images']) && is_array($response['images'])) {
                foreach ($response['images'] as $item) {
                    if (isset($item['url'])) {
                        $images[] = ['url' => $item['url']];
                    } elseif (isset($item['b64'])) {
                        $images[] = ['b64' => $item['b64']];
                    }
                }
            }
            $usage = [];
        } else {
            // Generic handler for other providers (like aliyun_imageseg)
            if (isset($response['images']) && is_array($response['images'])) {
                foreach ($response['images'] as $item) {
                    if (isset($item['url'])) {
                        $images[] = ['url' => $item['url']];
                    } elseif (isset($item['b64'])) {
                        $images[] = ['b64' => $item['b64']];
                    }
                }
            }
            $usage = [];
        }

        return [
            'images' => $images,
            'usage' => $usage,
            // 'raw' => $response, // Hide raw response to prevent model info leakage
        ];
    }

    protected function saveBase64AsUrl(string $b64, string $ext = 'png'): ?string
    {
        try {
            if (preg_match('#^data:image/([a-zA-Z0-9+.-]+);base64,#', $b64, $m)) {
                $mime = strtolower($m[1]);
                if ($mime === 'jpeg') $mime = 'jpg';
                if (in_array($mime, ['png', 'jpg', 'webp'])) {
                    $ext = $mime;
                }
            }
            $commaPos = strpos($b64, ',');
            if ($commaPos !== false) {
                $b64 = substr($b64, $commaPos + 1);
            }
            $binary = base64_decode($b64);
            if ($binary === false) {
                return null;
            }

            // Try OSS upload first
            $ossCfg = $this->getOssConfig();
            if ($ossCfg) {
                $url = $this->uploadToOss($binary, $ext, $ossCfg);
                if ($url) {
                    return $url;
                }
            }

            // Fallback to public disk
            $tmpName = uniqid('img_', true) . '.' . $ext;
            $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tmpName;
            file_put_contents($tmpPath, $binary);
            $prefix = config('filesystem.disks.public.url'); // '/storage'
            $domain = request()->domain();
            $path = Filesystem::disk('public')->putFile('generated', new \think\file\UploadedFile($tmpPath, $tmpName));
            if ($path) {
                return rtrim($domain, '/') . $prefix . '/' . str_replace('\\', '/', $path);
            }
            return null;
        } catch (\Throwable $e) {
            Log::warning('saveBase64AsUrl failed: ' . $e->getMessage());
            return null;
        }
    }

    protected function getOssConfig(): ?array
    {
        try {
            $row = Db::table('system_configs')->where('category', 'oss')->find();
            if (!$row) return null;
            if (isset($row['config'])) {
                $cfg = is_array($row['config']) ? $row['config'] : json_decode($row['config'], true);
                return is_array($cfg) ? $cfg : null;
            }
            // Map common columns if present
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
            $endpoint = $cfg['endpoint'] ?? null; // e.g., oss-cn-beijing.aliyuncs.com
            $bucket = $cfg['bucket'] ?? null;
            if (!$ak || !$sk || !$endpoint || !$bucket) {
                return null;
            }

            $prefix = $cfg['prefix'] ?? 'generated';
            $dateDir = date('Ymd');
            $objectKey = trim($prefix, '/') . '/' . $dateDir . '/' . uniqid('img_', true) . '.' . $ext;
            $endpointHost = preg_replace('#^https?://#', '', trim($endpoint));
            $endpointHost = rtrim($endpointHost, '/');
            if (stripos($endpointHost, 'oss-accesspoint.aliyuncs.com') !== false) {
                // Access Point endpoint: do NOT prefix bucket to host; put bucket in path
                $host = $endpointHost;
                $url = 'https://' . $host . '/' . $bucket . '/' . $objectKey;
            } else {
                // Standard regional endpoint: bucket as subdomain
                $host = $bucket . '.' . $endpointHost;
                $url = 'https://' . $host . '/' . $objectKey;
            }

            $contentType = 'image/' . ($ext ?: 'png');
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

            // Signature resource always includes bucket/objectKey
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

            // Force disable SSL verification as requested
            $client = new Client(['timeout' => 30, 'verify' => false]);
            $resp = $client->request('PUT', $url, [
                'headers' => $headers,
                'body' => $binary,
            ]);

            if ($resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300) {
                // If custom domain provided, prefer it
                if (!empty($cfg['domain'])) {
                    return rtrim($cfg['domain'], '/') . '/' . $objectKey;
                }
                return $url;
            }
            $this->logError('oss', 'OSS upload failed with status ' . $resp->getStatusCode(), [
                'url' => $url,
                'objectKey' => $objectKey,
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::warning('uploadToOss failed: ' . $e->getMessage());
            $this->logError('oss', $e->getMessage(), [ 'endpoint' => $endpoint, 'bucket' => $bucket ]);
            return null;
        }
    }

    protected function applyPoseEditPrompt(string $prompt, array $options): string
    {
        $flag = $options['is_pose_edit'] ?? false;
        $isPoseEdit = false;
        if (is_bool($flag)) $isPoseEdit = $flag;
        elseif (is_numeric($flag)) $isPoseEdit = ((int)$flag) === 1;
        elseif (is_string($flag)) $isPoseEdit = in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'y'], true);
        if (!$isPoseEdit) return $prompt;

        $tpl = $this->loadPoseEditPrompt();
        $tpl = trim($tpl);
        if ($tpl === '') {
            throw new \Exception('未配置人物动作修改提示词，请联系管理员配置');
        }

        $out = str_replace('{{USER_INPUT}}', '', $tpl);
        $out = trim($out);

        $taskId = isset($options['task_id']) && is_string($options['task_id']) ? trim($options['task_id']) : (isset($options['task_id']) ? (string)$options['task_id'] : '');
        Log::info('Pose edit prompt applied', [
            'task_id' => $taskId !== '' ? $taskId : null,
            'final_prompt_len' => mb_strlen($out, 'UTF-8'),
        ]);

        return $out;
    }

    protected function loadPoseEditPrompt(): string
    {
        try {
            $row = Db::table('system_prompts')
                ->whereNotNull('pose_edit_prompt')
                ->where('pose_edit_prompt', '<>', '')
                ->order('id', 'desc')
                ->find();
            if (is_array($row) && array_key_exists('pose_edit_prompt', $row)) {
                $v = $row['pose_edit_prompt'];
                $s = is_string($v) ? trim($v) : trim((string)$v);
                return $s;
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    protected function applyImageParamAdapt(string $prompt, $modelConfig, array $options): array
    {
        $adaptPrompt = $this->loadImageParamAdaptPrompt();
        if ($adaptPrompt === '') return $options;

        $modelIdentity = '';
        try {
            $modelIdentity = isset($modelConfig['model_identity']) ? (string)$modelConfig['model_identity'] : '';
        } catch (\Throwable $e) {
            $modelIdentity = '';
        }
        $modelIdentity = trim($modelIdentity);
        if ($modelIdentity === '') return $options;

        $input = [
            'model_identity' => $modelIdentity,
            'prompt' => $prompt,
            'options' => $options,
        ];
        $inputText = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($inputText)) $inputText = '';

        $userText = $inputText;

        try {
            $taskId = isset($options['task_id']) && is_string($options['task_id']) ? trim($options['task_id']) : (isset($options['task_id']) ? (string)$options['task_id'] : '');
            $promptLen = mb_strlen($prompt, 'UTF-8');
            Log::info('Image param adapt start', [
                'task_id' => $taskId !== '' ? $taskId : null,
                'model_identity' => $modelIdentity,
                'prompt_len' => $promptLen,
            ]);

            $llm = new LlmService();
            $resp = $llm->chat(
                [
                    ['role' => 'system', 'content' => $adaptPrompt],
                    ['role' => 'user', 'content' => $userText],
                ],
                [
                    'stream' => false,
                    'temperature' => 0.1,
                    'usage_type' => 'image_param_adapt',
                    'timeout' => 60,
                    'connect_timeout' => 15,
                    'retry' => 1,
                    'retry_delay_ms' => 800,
                    'response_format' => ['type' => 'json_object'],
                ],
                null
            );

            $content = isset($resp['content']) && is_string($resp['content']) ? trim($resp['content']) : '';
            $parsed = $this->extractJsonValue($content);
            if (!is_array($parsed)) {
                throw new \Exception('未得到有效JSON');
            }

            $allowedKeys = [
                'aspect_ratio' => 1,
                'size' => 1,
                'width' => 1,
                'height' => 1,
                'resolution' => 1,
            ];

            $unsetKeys = [];
            if (isset($parsed['unset_keys']) && is_array($parsed['unset_keys'])) {
                foreach ($parsed['unset_keys'] as $k) {
                    if (is_string($k) && trim($k) !== '') $unsetKeys[] = trim($k);
                }
            }

            $patch = null;
            if (isset($parsed['options']) && is_array($parsed['options'])) {
                $patch = $parsed['options'];
            } else {
                $patch = $parsed;
            }

            $newOptions = $options;
            foreach ($unsetKeys as $k) {
                if (isset($allowedKeys[$k]) && array_key_exists($k, $newOptions)) unset($newOptions[$k]);
            }

            if (is_array($patch)) {
                unset($patch['model_identity'], $patch['task_id'], $patch['tool_meta'], $patch['reference_images']);
                $filteredPatch = [];
                foreach ($patch as $k => $v) {
                    if (!is_string($k) || $k === '') continue;
                    if (!isset($allowedKeys[$k])) continue;
                    if (is_array($v) || is_object($v)) continue;
                    $filteredPatch[$k] = $v;
                }
                $newOptions = array_merge($newOptions, $filteredPatch);
            }

            if (array_key_exists('task_id', $options)) $newOptions['task_id'] = $options['task_id'];
            if (array_key_exists('model_identity', $options)) $newOptions['model_identity'] = $options['model_identity'];
            if (array_key_exists('tool_meta', $options)) $newOptions['tool_meta'] = $options['tool_meta'];
            if (array_key_exists('reference_images', $options)) $newOptions['reference_images'] = $options['reference_images'];

            return $newOptions;
        } catch (\Throwable $e) {
            Log::warning('Image param adapt failed: ' . $e->getMessage());
            return $options;
        }
    }

    protected function loadImageParamAdaptPrompt(): string
    {
        static $cols = null;
        if (!is_array($cols)) {
            $cols = [];
            try {
                $rows = Db::query("SHOW COLUMNS FROM `system_prompts`");
                if (is_array($rows)) {
                    foreach ($rows as $r) {
                        if (!is_array($r)) continue;
                        $field = $r['Field'] ?? $r['field'] ?? null;
                        $field = is_string($field) ? trim($field) : '';
                        if ($field !== '') $cols[$field] = 1;
                    }
                }
            } catch (\Throwable $e) {
                $cols = [];
            }
        }

        $fieldCandidates = [];
        if (isset($cols['image_model_param_adapt_prompt'])) $fieldCandidates[] = 'image_model_param_adapt_prompt';
        if (isset($cols['image_param_adapt_prompt'])) $fieldCandidates[] = 'image_param_adapt_prompt';
        if (!$fieldCandidates) return '';

        foreach ($fieldCandidates as $field) {
            try {
                $row = Db::table('system_prompts')
                    ->whereNotNull($field)
                    ->where($field, '<>', '')
                    ->order('id', 'desc')
                    ->find();
                if (is_array($row) && array_key_exists($field, $row)) {
                    $v = $row[$field];
                    $s = is_string($v) ? trim($v) : trim((string)$v);
                    if ($s !== '') {
                        Log::info('Image param adapt prompt loaded', ['field' => $field, 'id' => $row['id'] ?? null]);
                        return $s;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        return '';
    }

    protected function extractJsonValue(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') return null;

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/iu', $text, $m)) {
            $text = trim((string)$m[1]);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) return $decoded;

        $startArr = strpos($text, '[');
        $endArr = strrpos($text, ']');
        if ($startArr !== false && $endArr !== false && $endArr > $startArr) {
            $sub = substr($text, $startArr, $endArr - $startArr + 1);
            $decoded2 = json_decode($sub, true);
            if (is_array($decoded2)) return $decoded2;
        }

        $startObj = strpos($text, '{');
        $endObj = strrpos($text, '}');
        if ($startObj !== false && $endObj !== false && $endObj > $startObj) {
            $sub = substr($text, $startObj, $endObj - $startObj + 1);
            $decoded3 = json_decode($sub, true);
            if (is_array($decoded3)) return $decoded3;
        }

        return null;
    }

    public function storeBinary(string $binary, string $ext = 'png', string $prefix = 'generated'): ?string
    {
        try {
            $cfg = $this->getOssConfig();
            if ($cfg) {
                // Override prefix for this storage
                $cfg['prefix'] = $prefix;
                $url = $this->uploadToOss($binary, $ext, $cfg);
                if ($url) {
                    return $url;
                }
            }
            // Fallback: save to public disk
            $tmpName = uniqid('img_', true) . '.' . $ext;
            $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tmpName;
            file_put_contents($tmpPath, $binary);
            $publicPrefix = config('filesystem.disks.public.url'); // '/storage'
            $domain = '';
            try {
                $domain = (string)request()->domain();
            } catch (\Throwable $e) {
                $domain = '';
            }
            if (trim($domain) === '') {
                $domain = (string)config('app.app_host');
            }
            $path = Filesystem::disk('public')->putFile($prefix, new \think\file\UploadedFile($tmpPath, $tmpName));
            try { @unlink($tmpPath); } catch (\Throwable $e) {}
            if ($path) {
                $base = trim((string)$domain);
                $rel = rtrim((string)$publicPrefix, '/') . '/' . str_replace('\\', '/', $path);
                if ($base === '') return $rel;
                return rtrim($base, '/') . $rel;
            }
            $this->logError('upload', 'Store binary failed for public disk', [ 'prefix' => $prefix ]);
            return null;
        } catch (\Throwable $e) {
            Log::warning('storeBinary failed: ' . $e->getMessage());
            $this->logError('upload', $e->getMessage(), [ 'prefix' => $prefix ]);
            return null;
        }
    }

    protected function logError(string $category, string $message, array $payload = []): void
    {
        try {
            SystemErrorLog::create([
                'tenant_id' => request()->tenantId ?? null,
                'user_id' => request()->userId ?? null,
                'category' => $category,
                'message' => mb_substr($message, 0, 500),
                'context' => request()->url(true),
                'payload' => !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // swallow
        }
    }

    public function refundPoints($taskId)
    {
        $key = 'image_task_cost:' . $taskId;
        // Use pull to atomically get and delete to prevent race conditions (double refund)
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
                        'image_refund', 
                        "Refund for task {$taskId}", 
                        $taskId
                    );
                } else {
                    // Fallback
                    $user->changePoints($cost, 'image_refund', "Refund for task {$taskId}", $taskId);
                }
                
                // Rollback tenant quota
                try {
                    $tenant = SaasInstance::find($user->tenant_id);
                    if ($tenant) {
                        $tenant->updateQuota(-$cost);
                    }
                } catch (\Throwable $ex) {
                    Log::error("Failed to rollback tenant quota: " . $ex->getMessage());
                }

                return true;
            }
        }
        return false;
    }
}
