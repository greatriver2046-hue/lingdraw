<?php
namespace app\service\image;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;
use app\model\SystemErrorLog;

class QwenImageEditProvider implements ImageProviderInterface
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 300, // Increased timeout for potential synchronous calls
            'verify' => false,
        ]);
    }

    public function generate(string $prompt, array $config, array $options = []): array
    {
        $apiKey = $config['api_key'] ?? '';
        if (empty($apiKey)) {
            throw new \Exception('API Key is required for Qwen Image Edit');
        }

        // Determine endpoint based on region (default to Beijing)
        // Beijing: https://dashscope.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation
        // Singapore: https://dashscope-intl.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation
        $endpoint = $config['endpoint'] ?? 'https://dashscope.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation';
        
        $model = $config['model_id'] ?? 'qwen-image-edit-max';
        // Allow overriding model from options
        if (isset($options['model']) && !empty($options['model'])) {
            $model = $options['model'];
        }

        // Extract input image
        $imageUrl = null;
        if (isset($options['image'])) {
            $imageUrl = $options['image'];
        } elseif (isset($options['image_url'])) {
            $imageUrl = $options['image_url'];
        } elseif (isset($options['init_image'])) {
            $imageUrl = $options['init_image'];
        } elseif (isset($options['reference_images']) && is_array($options['reference_images']) && count($options['reference_images']) > 0) {
            $imageUrl = $options['reference_images'][0];
        }

        if (empty($imageUrl)) {
            throw new \Exception('Input image is required for Qwen Image Edit');
        }

        // Construct messages
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['image' => $imageUrl],
                    ['text' => $prompt]
                ]
            ]
        ];

        // Parameters
        $parameters = [
            'n' => isset($options['n']) ? (int)$options['n'] : 1,
        ];

        // Size Resolution Logic
        $width = 0;
        $height = 0;

        // 1. Try to get dimensions from options
        if (isset($options['width']) && is_numeric($options['width'])) $width = (int)$options['width'];
        if (isset($options['height']) && is_numeric($options['height'])) $height = (int)$options['height'];

        // 2. Try to parse 'size' string if dimensions are missing or to override
        if (isset($options['size']) && is_string($options['size'])) {
            if (preg_match('/^(\d+)[x\*](\d+)$/i', $options['size'], $matches)) {
                $width = (int)$matches[1];
                $height = (int)$matches[2];
            }
        }

        // 3. Validate and Resize
        if ($width > 0 && $height > 0) {
            $minDim = 512;
            $maxDim = 2048;
            $ratio = $width / $height;

            // Step A: Downscale if too large
            if ($width > $maxDim || $height > $maxDim) {
                if ($width >= $height) {
                    $width = $maxDim;
                    $height = (int)round($width / $ratio);
                } else {
                    $height = $maxDim;
                    $width = (int)round($height * $ratio);
                }
            }

            // Step B: Upscale if too small (Priority: satisfy min dimension)
            if ($width < $minDim || $height < $minDim) {
                // If aspect ratio is extreme (> 4:1 or < 1:4), we cannot satisfy both constraints.
                // We prioritize max dimension constraint (usually stricter for APIs).
                // But Qwen says "must be between 512 and 2048".
                // Let's try to make the smaller side 512.
                if ($width <= $height) {
                    $width = $minDim;
                    $height = (int)round($width / $ratio);
                } else {
                    $height = $minDim;
                    $width = (int)round($height * $ratio);
                }
                
                // Re-check max dim after upscale (for extreme aspect ratios)
                if ($width > $maxDim) $width = $maxDim;
                if ($height > $maxDim) $height = $maxDim;
            }

            // Final clamp (Just in case)
            $width = max($minDim, min($maxDim, $width));
            $height = max($minDim, min($maxDim, $height));

            $parameters['size'] = $width . '*' . $height;
            Log::info("QwenImageEdit: Resolved size to {$parameters['size']} (Original: " . ($options['size'] ?? 'N/A') . ")");
        } elseif (isset($options['size'])) {
             // Fallback: just ensure format is correct
             $parameters['size'] = str_replace('x', '*', strtolower($options['size']));
        }

        if (isset($options['negative_prompt'])) {
            $parameters['negative_prompt'] = $options['negative_prompt'];
        }

        // Other parameters supported by Qwen Image Edit
        // prompt_extend: boolean (default true)
        // watermark: boolean (default false)
        if (isset($options['prompt_extend'])) {
            $parameters['prompt_extend'] = (bool)$options['prompt_extend'];
        }
        if (isset($options['watermark'])) {
            $parameters['watermark'] = (bool)$options['watermark'];
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
            // 'X-DashScope-Async' => 'enable', // Removed: Some accounts/models do not support forced async
        ];

        $body = [
            'model' => $model,
            'input' => [
                'messages' => $messages
            ],
            'parameters' => $parameters
        ];

        try {
            // Submit Task
            $response = $this->client->post($endpoint, [
                'headers' => $headers,
                'json' => $body
            ]);

            $json = json_decode($response->getBody()->getContents(), true);
            
            // Check for Task ID
            $taskId = $json['output']['task_id'] ?? ($json['task_id'] ?? null);
            
            if (!$taskId) {
                // Maybe it returned results directly (if not async)
                if (isset($json['output']['results'])) {
                    return $this->formatResult($json['output']['results']);
                }
                $msg = $json['message'] ?? ($json['code'] ?? 'Unknown error');
                throw new \Exception('Qwen Image Edit API Error: ' . $msg);
            }

            // Poll for result
            return $this->pollTask($taskId, $apiKey, $endpoint);

        } catch (RequestException $e) {
            $this->logError('QwenImageEdit API Error', $e->getMessage());
            Log::error('Qwen Image Edit API Error: ' . $e->getMessage());
            if ($e->hasResponse()) {
                $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                $msg = $errorBody['message'] ?? ($errorBody['code'] ?? $e->getMessage());
                $this->logError('QwenImageEdit API Error', $msg, $errorBody);
                throw new \Exception('Qwen Image Edit API Error: ' . $msg);
            }
            throw $e;
        }
    }

    protected function logError($category, $message, $payload = [])
    {
        try {
            SystemErrorLog::create([
                'category' => $category,
                'message' => mb_substr($message, 0, 500),
                'context' => request()->url(true),
                'payload' => !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => date('Y-m-d H:i:s'),
                'tenant_id' => request()->tenantId ?? null,
                'user_id' => request()->userId ?? null
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to log system error: ' . $e->getMessage());
        }
    }

    protected function pollTask($taskId, $apiKey, $endpointUrl = '')
    {
        $maxRetries = 120; // 120 seconds (2 minutes) timeout
        $retryCount = 0;
        
        // Determine base host from endpoint
        $host = 'dashscope.aliyuncs.com';
        if ($endpointUrl && strpos($endpointUrl, 'dashscope-intl.aliyuncs.com') !== false) {
            $host = 'dashscope-intl.aliyuncs.com';
        }
        
        $url = "https://{$host}/api/v1/tasks/{$taskId}";

        while ($retryCount < $maxRetries) {
            sleep(1); // Wait 1 second
            $retryCount++;

            try {
                $response = $this->client->get($url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey
                    ]
                ]);

                $json = json_decode($response->getBody()->getContents(), true);
                $status = $json['output']['task_status'] ?? '';

                if ($status === 'SUCCEEDED') {
                    if (isset($json['output']['results'])) {
                        return $this->formatResult($json['output']['results']);
                    }
                    // Some models might return 'images' directly?
                    // Usually DashScope returns results list
                    throw new \Exception('Task succeeded but no results found');
                } elseif ($status === 'FAILED') {
                    $msg = $json['output']['message'] ?? 'Task failed';
                    $this->logError('QwenImageEdit Task Failed', $msg, ['task_id' => $taskId, 'response' => $json]);
                    throw new \Exception('Qwen Image Edit Task Failed: ' . $msg);
                } elseif ($status === 'CANCELED') {
                     $this->logError('QwenImageEdit Task Canceled', 'Task was canceled', ['task_id' => $taskId]);
                     throw new \Exception('Qwen Image Edit Task Canceled');
                }

                // PENDING, RUNNING -> continue

            } catch (\Exception $e) {
                // If it's a network error during polling, we might want to retry or abort
                // For now, abort on consecutive errors or log
                Log::warning('Qwen Image Edit Polling Error: ' . $e->getMessage());
                if ($retryCount > 5 && $retryCount % 5 === 0) {
                     // Check if it's a fatal error?
                }
                if (!($e instanceof \Exception) || strpos($e->getMessage(), 'Qwen Image Edit Task') === false) {
                     // Log unexpected polling errors, but exclude the custom exceptions we just threw
                     $this->logError('QwenImageEdit Polling Error', $e->getMessage(), ['task_id' => $taskId]);
                }
                throw $e; // Re-throw to exit loop
            }
        }

        $this->logError('QwenImageEdit Timeout', 'Task polling timed out', ['task_id' => $taskId]);
        throw new \Exception('Qwen Image Edit Task Timeout');
    }

    protected function formatResult($results)
    {
        $images = [];
        foreach ($results as $item) {
            if (isset($item['url'])) {
                $images[] = ['url' => $item['url']];
            } elseif (isset($item['b64_image'])) {
                 $images[] = ['b64' => $item['b64_image']];
            }
        }
        return ['images' => $images];
    }
}
