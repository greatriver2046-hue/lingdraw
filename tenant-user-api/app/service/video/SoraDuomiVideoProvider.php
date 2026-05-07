<?php
namespace app\service\video;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;
use app\model\SystemErrorLog;

class SoraDuomiVideoProvider implements VideoProviderInterface
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
        $model = 'sora2';
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
        
        // Forward options transparently for downstream API
        $mapKeys = [
            'aspect_ratio',
            'duration',
            'resolution',
            'reference_mode',
            'first_frame',
            'last_frame',
            'reference_images',
            'image_urls', // Added support for image_urls
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
            
            Log::info("SoraDuomi Response: " . $content);

            if (isset($json['data']['task_id'])) {
                $taskId = $json['data']['task_id'];
            } elseif (isset($json['task_id'])) {
                $taskId = $json['task_id'];
            } elseif (isset($json['id'])) {
                $taskId = $json['id'];
            } else {
                $msg = isset($json['msg']) ? $json['msg'] : (isset($json['message']) ? $json['message'] : 'Unknown error');
                throw new \Exception('Failed to get task_id from SoraDuomi API. Response: ' . $content);
            }
            
        } catch (RequestException $e) {
            $this->logError($e, $endpoint, $body);
            if ($e->hasResponse()) {
                $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                $msg = $errorBody['msg'] ?? $e->getMessage();
                throw new \Exception('SoraDuomi API Error: ' . $msg);
            }
            throw $e;
        }

        // Poll Status
        // Construct status URL. Assuming similar pattern: replace last segment or append task/{id}
        // If endpoint is .../video/generation, status might be .../video/task/{id}
        
        // Let's try to derive base path similar to NanoBananaPro
        $parts = parse_url($endpoint);
        $path = $parts['path'] ?? '';
        $pathParts = explode('/', rtrim($path, '/'));
        array_pop($pathParts); // remove method name
        $basePath = implode('/', $pathParts);
        
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        
        $statusUrl = $scheme . $host . $port . $basePath . '/tasks/' . $taskId;
        
        Log::info("SoraDuomi Debug: RequestEndpoint: $endpoint, TaskID: $taskId, StatusUrl: $statusUrl");

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
                Log::info("SoraDuomi Debug: Status response: " . $content);
                $statusJson = json_decode($content, true);
                
                $state = $statusJson['state'] ?? ($statusJson['data']['state'] ?? '');
                
                if ($state === 'succeeded') {
                    // Video result structure
                    // Assuming data.data.videos or data.data.video_url
                    // NanoBananaPro: data.data.images[].url
                    
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
                Log::warning('SoraDuomi status check failed: ' . $e->getMessage());
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
