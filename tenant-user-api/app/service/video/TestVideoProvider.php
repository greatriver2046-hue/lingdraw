<?php
namespace app\service\video;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;
use app\model\SystemErrorLog;

class TestVideoProvider implements VideoProviderInterface
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 60,
            'verify' => false,
        ]);
    }

    public function generate(string $prompt, array $config, array $options = []): array
    {
        $apiKey = $config['api_key'] ?? '';
        $endpoint = $config['endpoint'] ?? '';
        Log::info("TestVideoProvider: Using endpoint: {$endpoint}");
        $model = 'test-video';
        if (!empty($config['model_id'])) {
            $model = $config['model_id'];
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
        ];
        
        // Forward options transparently to test endpoint
        $mapKeys = [
            'aspect_ratio',
            'duration',
            'resolution',
            'reference_mode',
            'first_frame',
            'last_frame',
            'reference_images',
            'reference_video',
            'size',
            'width',
            'height',
        ];
        foreach ($mapKeys as $k) {
            if (array_key_exists($k, $options)) {
                $body[$k] = $options[$k];
            }
        }
        
        // Submit Request
        $taskId = null;
        try {
            $response = $this->client->post($endpoint, [
                'headers' => $headers,
                'json' => $body,
            ]);
            $content = $response->getBody()->getContents();
            $json = json_decode($content, true);
            
            Log::info("TestVideo Response: " . $content);

            if (isset($json['data']['task_id'])) {
                $taskId = $json['data']['task_id'];
            } elseif (isset($json['task_id'])) {
                $taskId = $json['task_id'];
            } elseif (isset($json['id'])) {
                $taskId = $json['id'];
            } else {
                $msg = isset($json['msg']) ? $json['msg'] : (isset($json['message']) ? $json['message'] : 'Unknown error');
                throw new \Exception('Failed to get task_id from TestVideo API. Response: ' . $content);
            }
            
        } catch (RequestException $e) {
            $this->logError($e, $endpoint, $body);
            if ($e->hasResponse()) {
                $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                $msg = $errorBody['msg'] ?? $e->getMessage();
                throw new \Exception('TestVideo API Error: ' . $msg);
            }
            throw $e;
        }

        // Poll Status
        $statusBaseUrl = 'http://127.0.0.1:8007/videotestapi/task.php';
        $statusUrl = $statusBaseUrl . '?task_id=' . $taskId;
        
        Log::info("TestVideo Debug: RequestEndpoint: $endpoint, TaskID: $taskId, StatusUrl: $statusUrl");

        $maxRetries = 180; // 60 minutes (aligned with job timeout)
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            sleep(20);
            $attempt++;
            
            try {
                $resp = $this->client->get($statusUrl, [
                    'headers' => $headers
                ]);
                $content = $resp->getBody()->getContents();
                Log::info("TestVideo Debug: Status response: " . $content);
                $statusJson = json_decode($content, true);
                
                $state = $statusJson['state'] ?? ($statusJson['data']['state'] ?? '');
                
                if ($state === 'succeeded' || $state === 'success') {
                    // Let's look for likely fields
                    $data = $statusJson['data'] ?? [];
                    
                    // Handle case where data might be wrapped in another data field
                    if (isset($data['data']) && is_array($data['data'])) {
                         $data = $data['data'];
                    }

                    $videos = [];
                    
                    if (isset($data['video_url'])) {
                        $videos[] = ['url' => $data['video_url']];
                    } elseif (isset($data['videos']) && is_array($data['videos'])) {
                        foreach ($data['videos'] as $v) {
                            if (isset($v['url'])) {
                                $videos[] = ['url' => $v['url']];
                            } elseif (is_string($v)) {
                                $videos[] = ['url' => $v];
                            }
                        }
                    }
                    
                    if (empty($videos) && isset($data['url'])) {
                         $videos[] = ['url' => $data['url']];
                    }

                    return ['data' => $videos];
                    
                } elseif ($state === 'failed' || $state === 'error') {
                     $errMsg = $statusJson['message'] ?? ($statusJson['msg'] ?? ($statusJson['data']['msg'] ?? 'Unknown error'));
                     throw new \Exception('Task failed: ' . $errMsg);
                }
                
            } catch (RequestException $e) {
                Log::warning('TestVideo status check failed: ' . $e->getMessage());
            }
        }
        
        throw new \Exception('Timeout waiting for video generation');
    }

    private function logError($e, $context, $payload) {
        try {
             SystemErrorLog::create([
                'tenant_id' => request()->tenantId ?? null,
                'user_id' => request()->userId ?? null,
                'category' => 'model',
                'message' => $e->getMessage(),
                'context' => $context,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $ex) {}
    }
}
