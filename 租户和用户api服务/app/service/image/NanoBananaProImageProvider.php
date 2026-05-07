<?php
namespace app\service\image;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;
use app\model\SystemErrorLog;

class NanoBananaProImageProvider implements ImageProviderInterface
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 300,
            'verify' => false,
        ]);
    }

    public function generate(string $prompt, array $config, array $options = []): array
    {
        $apiKey = $config['api_key'] ?? '';
        $endpoint = $config['endpoint'] ?? $config['url'] ?? $config['apiUrl'] ?? '';
        if (empty($endpoint)) {
             throw new \Exception('No endpoint/url configured for NanoBananaPro model.');
        }

        $model = 'gemini-3-pro-image-preview';
        if (!empty($config['model_id'])) {
            $model = $config['model_id'];
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];
        if (!empty($apiKey)) {
            $headers['Authorization'] = $apiKey;
        }

        // Determine Task Type (Generation vs Editing)
        // Check if task_type is EDIT_SINGLE_IMAGE or if there are reference images
        $isEdit = false;
        if (isset($options['task_type']) && $options['task_type'] === 'EDIT_SINGLE_IMAGE') {
            $isEdit = true;
        } elseif (!empty($options['reference_images']) && is_array($options['reference_images'])) {
            // Implicit edit if images are provided
            $isEdit = true;
        }

        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'aspect_ratio' => $options['aspect_ratio'] ?? 'auto',
        ];

        if (isset($options['size'])) {
             $body['image_size'] = $options['size'];
        }

        // Adjust Endpoint and Body for Editing
        $requestEndpoint = $endpoint;
        if ($isEdit) {
            // Assume the edit endpoint is derived from the generation endpoint by replacing the last segment
            // e.g., .../nano-banana -> .../nano-banana-edit
            // OR if the user provided the base URL, we might need to handle it differently.
            // Based on user instruction: https://s.apifox.cn/b924931e-29c0-4127-b025-d68c90285060/api-346293340
            // Edit endpoint: /api/gemini/nano-banana-edit
            
            // Heuristic: replace 'nano-banana' with 'nano-banana-edit' if present, otherwise append '-edit'
            if (strpos($endpoint, 'nano-banana') !== false && strpos($endpoint, 'nano-banana-edit') === false) {
                 $requestEndpoint = str_replace('nano-banana', 'nano-banana-edit', $endpoint);
            } else {
                 // Fallback or if endpoint is just base
                 // Let's assume the config endpoint is the generation one.
                 // We will try to construct the edit endpoint.
                 $requestEndpoint = rtrim($endpoint, '/') . '-edit';
            }
            
            if (!empty($options['reference_images']) && is_array($options['reference_images'])) {
                $body['image_urls'] = array_values($options['reference_images']);
            }
        }

        // 1. Submit Request
        $taskId = null;
        try {
            $response = $this->client->post($requestEndpoint, [
                'headers' => $headers,
                'json' => $body,
            ]);
            $json = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($json['data']['task_id'])) {
                $msg = isset($json['msg']) ? $json['msg'] : 'Unknown error';
                $ex = new \Exception('Failed to get task_id from NanoBananaPro API: ' . $msg);
                $this->logError($ex, $requestEndpoint, $json);
                throw $ex;
            }
            $taskId = $json['data']['task_id'];
            
        } catch (RequestException $e) {
            $this->logError($e, $requestEndpoint, $body);
            if ($e->hasResponse()) {
                $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                $msg = $errorBody['msg'] ?? $e->getMessage();
                throw new \Exception('NanoBananaPro API Error: ' . $msg);
            }
            throw $e;
        }

        // 2. Poll Status
        // Construct status URL:
        // First check if the API returned a status URL
        $explicitStatusUrl = $json['data']['status_url'] ?? $json['data']['check_url'] ?? $json['data']['result_url'] ?? null;

        if ($explicitStatusUrl) {
            $statusUrl = $explicitStatusUrl;
        } else {
            // Fallback to constructing it based on user feedback
            // The status URL is typically the generation endpoint + / + taskId
            // e.g. .../nano-banana/{taskId}
            // However, for edit tasks, the requestEndpoint might be .../nano-banana-edit
            // But the status URL MUST be .../nano-banana/{taskId}
            
            $baseUrl = rtrim($requestEndpoint, '/');
            if (stripos($baseUrl, 'nano-banana-edit') !== false) {
                $baseUrl = str_ireplace('nano-banana-edit', 'nano-banana', $baseUrl);
            }
            $statusUrl = $baseUrl . '/' . $taskId;
        }
        
        Log::info("NanoBananaPro Debug: RequestEndpoint: $requestEndpoint, TaskID: $taskId, StatusUrl: $statusUrl");

        $maxRetries = 120; // 10 minutes (5s interval * 120 = 600s)
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            sleep(5);
            $attempt++;
            
            try {
                $resp = $this->client->get($statusUrl, [
                    'headers' => $headers
                ]);
                $content = $resp->getBody()->getContents();
                Log::info("NanoBananaPro Debug: Status response: " . $content);
                $statusJson = json_decode($content, true);
                
                // Try to find state/status in various locations
                $state = $statusJson['data']['state'] ?? $statusJson['data']['status'] ?? $statusJson['state'] ?? $statusJson['status'] ?? '';
                $state = strtolower($state); // Normalize to lowercase

                if (in_array($state, ['succeeded', 'success', 'completed', 'finished', 'done'])) {
                    // Try to find images in various locations
                    $images = $statusJson['data']['data']['images'] ?? $statusJson['data']['images'] ?? $statusJson['data']['output'] ?? $statusJson['images'] ?? [];
                    
                    $resultImages = [];
                    foreach ($images as $img) {
                        if (is_array($img) && isset($img['url'])) {
                            $resultImages[] = ['url' => $img['url']];
                        } elseif (is_string($img)) {
                            $resultImages[] = ['url' => $img];
                        }
                    }
                    
                    if (empty($resultImages)) {
                        // If success but no images, maybe it's in a different format?
                        Log::warning("NanoBananaPro: Task succeeded but no images found in standard paths. Content: " . $content);
                    }
                    
                    return ['data' => $resultImages];
                } elseif (in_array($state, ['failed', 'error', 'failure'])) {
                     $errMsg = $statusJson['data']['msg'] ?? $statusJson['msg'] ?? 'Unknown error';
                     $ex = new \Exception('Task failed: ' . $errMsg);
                     $this->logError($ex, $statusUrl, $statusJson);
                     throw $ex;
                }
                // If processing/running/queued, continue loop
                
            } catch (RequestException $e) {
                Log::warning('NanoBananaPro status check failed: ' . $e->getMessage());
                $this->logError($e, $statusUrl, ['phase' => 'polling']);
                // Throw error immediately instead of retrying
                throw new \Exception('Status check failed: ' . $e->getMessage());
            }
        }
        
        $ex = new \Exception('Timeout waiting for image generation');
        $this->logError($ex, $statusUrl ?? $requestEndpoint, ['timeout' => $maxRetries * 5]);
        throw $ex;
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
