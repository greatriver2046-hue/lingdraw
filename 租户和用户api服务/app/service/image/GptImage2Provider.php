<?php
namespace app\service\image;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;
use app\model\SystemErrorLog;

class GptImage2Provider implements ImageProviderInterface
{
    protected $client;
    protected $logFile;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 300,
            'verify' => false,
        ]);
        // 使用独立的日志文件确保日志被写入
        $this->logFile = runtime_path() . 'log/gptimage2_' . date('Ymd') . '.log';
        if (!is_dir(dirname($this->logFile))) {
            @mkdir(dirname($this->logFile), 0755, true);
        }
        $this->writeLog('GptImage2Provider 初始化完成');
    }

    private function writeLog($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[{$timestamp}] {$message}\n";
        // 使用多种方式写入日志
        error_log($logLine, 3, $this->logFile);
        // 也尝试使用ThinkPHP的日志
        try {
            Log::info($message);
        } catch (\Throwable $e) {
            // 忽略ThinkPHP日志错误
        }
    }

    public function generate(string $prompt, array $config, array $options = []): array
    {
        $this->writeLog('=== GptImage2 generate() 被调用 ===');
        
        $apiKey = $config['api_key'] ?? '';
        $endpoint = $config['endpoint'] ?? '';
        $model = $config['model_id'] ?? 'gpt-image-2';
        
        $this->writeLog("Config - endpoint: {$endpoint}, model: {$model}");

        if (empty($endpoint)) {
             throw new \Exception('No endpoint/url configured for GptImage2 model.');
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];
        if (!empty($apiKey)) {
            $headers['Authorization'] = $apiKey;
        }

        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => $options['n'] ?? 1,
        ];

        $size = null;
        if (isset($options['size']) && is_string($options['size']) && trim($options['size']) !== '') {
            $size = trim($options['size']);
        } elseif (isset($options['width']) && isset($options['height'])) {
            $w = (int)$options['width'];
            $h = (int)$options['height'];
            if ($w > 0 && $h > 0) {
                $size = $w . 'x' . $h;
            }
        }
        if ($size) {
            $body['size'] = $size;
        }

        if (isset($options['reference_images']) && is_array($options['reference_images']) && count($options['reference_images']) > 0) {
            $body['image'] = $options['reference_images'][0];
        } elseif (isset($options['image'])) {
            $body['image'] = $options['image'];
        }

        $mapKeys = [
            'style',
            'response_format',
            'user',
            'quality',
        ];
        foreach ($mapKeys as $k) {
            if (array_key_exists($k, $options)) {
                $body[$k] = $options[$k];
            }
        }

        $this->writeLog("提交请求到: {$endpoint}");

        // 1. Submit Request
        $taskId = null;
        $submitResponse = null;
        try {
            $this->writeLog("发送POST请求...");
            $response = $this->client->post($endpoint, [
                'headers' => $headers,
                'json' => $body,
            ]);
            $content = $response->getBody()->getContents();
            $submitResponse = json_decode($content, true);
            
            $this->writeLog("API返回: {$content}");

            if (!is_array($submitResponse)) {
                $this->writeLog("ERROR: JSON解析失败");
                throw new \Exception('Invalid JSON response from GptImage2 API');
            }

            // 提取task_id
            $taskId = $submitResponse['id'] ?? null;
            $this->writeLog("从id字段提取: " . var_export($taskId, true));
            
            if (!$taskId && isset($submitResponse['task_id'])) {
                $taskId = $submitResponse['task_id'];
                $this->writeLog("从task_id字段提取: " . var_export($taskId, true));
            }
            if (!$taskId && isset($submitResponse['data']['task_id'])) {
                $taskId = $submitResponse['data']['task_id'];
                $this->writeLog("从data.task_id字段提取: " . var_export($taskId, true));
            }
            
            if (!$taskId || !is_string($taskId) || trim($taskId) === '') {
                $msg = $submitResponse['msg'] ?? $submitResponse['message'] ?? 'Unknown error';
                $this->writeLog("ERROR: 没有获取到task_id, 错误: {$msg}");
                throw new \Exception('Failed to get task_id from GptImage2 API: ' . $msg);
            }
            
            $taskId = trim($taskId);
            $this->writeLog("任务提交成功, task_id: {$taskId}");
            
        } catch (RequestException $e) {
            $this->writeLog("请求异常: " . $e->getMessage());
            $this->logError($e, $endpoint, $body);
            if ($e->hasResponse()) {
                $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                $msg = $errorBody['msg'] ?? ($errorBody['message'] ?? $e->getMessage());
                throw new \Exception('GptImage2 API Error: ' . $msg);
            }
            throw $e;
        }

        // 2. 构造状态URL
        $explicitStatusUrl = $submitResponse['status_url'] 
            ?? $submitResponse['check_url'] 
            ?? $submitResponse['result_url'] 
            ?? ($submitResponse['data']['status_url'] ?? null);

        if ($explicitStatusUrl && is_string($explicitStatusUrl) && trim($explicitStatusUrl) !== '') {
            $statusUrl = trim($explicitStatusUrl);
            $this->writeLog("使用显式状态URL: {$statusUrl}");
        } else {
            $parts = parse_url($endpoint);
            if ($parts === false) {
                throw new \Exception('Invalid endpoint URL: ' . $endpoint);
            }
            
            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'] ?? '';
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $path = rtrim($parts['path'] ?? '', '/');
            $pathParts = explode('/', $path);
            
            // 查找版本段
            $versionSegment = null;
            foreach ($pathParts as $part) {
                if (preg_match('/^v\d+$/', $part)) {
                    $versionSegment = $part;
                    break;
                }
            }
            
            if ($versionSegment) {
                $statusUrl = "{$scheme}://{$host}{$port}/{$versionSegment}/tasks/{$taskId}";
            } else {
                array_pop($pathParts);
                $basePath = implode('/', $pathParts);
                if ($basePath && $basePath !== '/') {
                    $statusUrl = "{$scheme}://{$host}{$port}/{$basePath}/tasks/{$taskId}";
                } else {
                    $statusUrl = "{$scheme}://{$host}{$port}/tasks/{$taskId}";
                }
            }
            
            $this->writeLog("构造状态URL: {$statusUrl}");
        }

        $this->writeLog("开始轮询 task: {$taskId}");

        // 3. 轮询
        $maxRetries = 180;
        $attempt = 0;
        $lastState = '';
        
        while ($attempt < $maxRetries) {
            $attempt++;
            $this->writeLog("轮询尝试 {$attempt}/{$maxRetries}, 等待20秒...");
            sleep(20);
            
            try {
                $this->writeLog("GET {$statusUrl}");
                $resp = $this->client->get($statusUrl, [
                    'headers' => $headers
                ]);
                $content = $resp->getBody()->getContents();
                $this->writeLog("状态响应: {$content}");
                
                $statusJson = json_decode($content, true);
                if (!is_array($statusJson)) {
                    $this->writeLog("ERROR: 状态响应JSON解析失败");
                    continue;
                }
                
                // 提取state
                $state = '';
                if (isset($statusJson['state']) && is_string($statusJson['state'])) {
                    $state = strtolower($statusJson['state']);
                } elseif (isset($statusJson['data']['state']) && is_string($statusJson['data']['state'])) {
                    $state = strtolower($statusJson['data']['state']);
                }
                
                $this->writeLog("当前状态: {$state}");
                
                if ($state !== $lastState) {
                    $this->writeLog("状态变化: {$lastState} -> {$state}");
                    $lastState = $state;
                }

                if (in_array($state, ['succeeded', 'success', 'completed'])) {
                    $data = $statusJson['data'] ?? [];
                    $images = $data['images'] ?? [];
                    
                    $this->writeLog("任务成功, 找到 " . count($images) . " 张图片");
                    
                    $resultImages = [];
                    foreach ($images as $img) {
                        if (is_array($img) && isset($img['url'])) {
                            $resultImages[] = ['url' => $img['url']];
                        } elseif (is_string($img)) {
                            $resultImages[] = ['url' => $img];
                        }
                    }
                    
                    $this->writeLog("返回 " . count($resultImages) . " 张图片");
                    return ['data' => $resultImages];
                } 
                elseif (in_array($state, ['failed', 'error'])) {
                    $data = $statusJson['data'] ?? [];
                    $errMsg = $statusJson['message'] 
                        ?? ($statusJson['msg'] 
                        ?? ($data['msg'] ?? ($data['description'] ?? 'Unknown error')));
                    $this->writeLog("任务失败: {$errMsg}");
                    throw new \Exception('Task failed: ' . $errMsg);
                }
                
                $this->writeLog("仍在处理中 (状态: {$state})");
                
            } catch (RequestException $e) {
                $errorMsg = 'HTTP请求失败: ' . $e->getMessage();
                $this->writeLog("ERROR: {$errorMsg}");
                throw new \Exception($errorMsg);
            } catch (\Throwable $e) {
                $this->writeLog("ERROR: 意外错误: " . $e->getMessage());
                throw $e;
            }
        }
        
        throw new \Exception('超时等待图片生成');
    }

    private function logError($e, $context, $payload) {
        try {
             SystemErrorLog::create([
                'tenant_id' => request()->tenantId ?? null,
                'user_id' => request()->userId ?? null,
                'category' => 'model',
                'message' => mb_substr($e->getMessage(), 0, 500),
                'context' => $context,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $ex) {}
    }
}
