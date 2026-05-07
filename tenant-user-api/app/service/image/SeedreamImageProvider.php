<?php
namespace app\service\image;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;
use app\model\SystemErrorLog;

class SeedreamImageProvider implements ImageProviderInterface
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
        $endpoint = $config['endpoint'] ?? '';
        $model = $config['model_id'] ?? '';

        $headers = [
            'Content-Type' => 'application/json',
        ];
        if (!empty($apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $body = [
            'model' => $model,
            'prompt' => $prompt,
            // Volcengine images API: references should be under 'image', count may use 'num_images'
            'watermark' => false,
            'logo_info' => [
                'add_logo' => false,
            ],
        ];

        if (isset($options['width']) && isset($options['height'])) {
            // OpenAI compatible endpoints expect 'size' as "WxH" string
            $body['size'] = $options['width'] . 'x' . $options['height'];
        } elseif (isset($options['size'])) {
            $body['size'] = $options['size'];
        }

        if (isset($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        if (!empty($options['reference_images']) && is_array($options['reference_images'])) {
            $body['image'] = array_values($options['reference_images']);
            if (!empty($options['sequential_image_generation'])) {
                $body['sequential_image_generation'] = $options['sequential_image_generation'];
            } else {
                $body['sequential_image_generation'] = (count($options['reference_images']) > 1) ? 'auto' : 'disabled';
            }
        } elseif (!empty($options['image'])) {
            $body['image'] = $options['image'];
        }
        if (isset($options['n'])) {
            $body['num_images'] = $options['n'];
        }
        if (isset($options['upscale_factor'])) {
            $body['upscale_factor'] = $options['upscale_factor'];
        }
        if (isset($options['optimize_prompt_mode'])) {
            $body['optimize_prompt_mode'] = $options['optimize_prompt_mode'];
        }

        $maxRetries = 0; // Disable retry
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                $response = $this->client->post($endpoint, [
                    'headers' => $headers,
                    'json' => $body,
                ]);

                $json = json_decode($response->getBody()->getContents(), true);
                return is_array($json) ? $json : [];

            } catch (RequestException $e) {
                $attempt++;
                $lastException = $e;
                if ($attempt <= $maxRetries) {
                    sleep(1);
                    continue;
                }
            }
        }

        if ($lastException) {
            $e = $lastException;
            Log::error('Seedream Image API Error: ' . $e->getMessage());
            try {
                SystemErrorLog::create([
                    'tenant_id' => request()->tenantId ?? null,
                    'user_id' => request()->userId ?? null,
                    'category' => 'model',
                    'message' => $e->getMessage(),
                    'context' => $endpoint,
                    'payload' => json_encode($body, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $ex) {}
            if ($e->hasResponse()) {
                $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                $msg = $errorBody['error']['message'] ?? $e->getMessage();
                throw new \Exception('Seedream Image API Error: ' . $msg);
            }
            throw $e;
        }
        return [];
    }
}
