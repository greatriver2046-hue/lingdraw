<?php
namespace app\service\image;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;

class OpenAIImageProvider implements ImageProviderInterface
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
        $endpoint = $config['endpoint'] ?? 'https://api.openai.com/v1/images/generations';
        $model = $config['model_id'] ?? 'gpt-image-1';

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => $options['n'] ?? 1,
        ];

        if (isset($options['size'])) {
            $body['size'] = $options['size'];
        }

        if (isset($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        if (isset($options['user'])) {
            $body['user'] = $options['user'];
        }

        try {
            $response = $this->client->post($endpoint, [
                'headers' => $headers,
                'json' => $body,
            ]);

            $json = json_decode($response->getBody()->getContents(), true);
            return is_array($json) ? $json : [];

        } catch (RequestException $e) {
            Log::error('OpenAI Image API Error: ' . $e->getMessage());
            if ($e->hasResponse()) {
                $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                $msg = $errorBody['error']['message'] ?? $e->getMessage();
                throw new \Exception('OpenAI Image API Error: ' . $msg);
            }
            throw $e;
        }
    }
}
