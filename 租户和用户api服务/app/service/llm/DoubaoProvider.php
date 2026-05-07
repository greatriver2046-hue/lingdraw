<?php
namespace app\service\llm;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;

class DoubaoProvider implements LlmProviderInterface
{
    protected $client;

    protected function trimLogValue($value, int $maxLen = 8000): string
    {
        if (is_string($value)) {
            $text = $value;
        } else {
            $text = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if (!is_string($text)) $text = '';
        if (mb_strlen($text, 'UTF-8') > $maxLen) {
            $text = mb_substr($text, 0, $maxLen, 'UTF-8') . '…';
        }
        return $text;
    }

    protected function maskSecretValue($value): string
    {
        $s = is_string($value) ? $value : (string)$value;
        $s = trim($s);
        if ($s === '') return $s;
        $len = mb_strlen($s, 'UTF-8');
        if ($len <= 8) return '***';
        $prefix = mb_substr($s, 0, 4, 'UTF-8');
        $suffix = mb_substr($s, -4, null, 'UTF-8');
        return $prefix . '***' . $suffix;
    }

    protected function sanitizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $keyLower = is_string($k) ? strtolower($k) : (string)$k;
            $isSecret = in_array($keyLower, [
                'authorization',
                'proxy-authorization',
                'x-api-key',
                'api-key',
                'apikey',
                'x-token',
                'x-auth-token',
                'x-ark-api-key',
                'x-signature',
            ], true);
            if ($isSecret) {
                $out[$k] = $this->maskSecretValue($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function __construct()
    {
        $this->client = new Client([
            'timeout'  => 300,
            'connect_timeout' => 15,
            'verify'   => false,
        ]);
    }

    public function chat(array $messages, array $config, array $options = [])
    {
        $apiKey = $config['api_key'];
        $endpoint = $config['endpoint'] ?? 'https://ark.cn-beijing.volces.com/api/v3/chat/completions';
        $model = $config['model_id'];

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => $options['stream'] ?? false,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 1024,
        ];

        if (isset($options['tools'])) {
            $body['tools'] = $options['tools'];
        }
        if (isset($options['tool_choice'])) {
            $body['tool_choice'] = $options['tool_choice'];
        }
        if (isset($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }
        if (isset($options['thinking'])) {
            $body['thinking'] = $options['thinking'];
        } else {
            $body['thinking'] = ['type' => 'disabled'];
        }

        try {
            $rid = uniqid('llm_', true);
            Log::channel('agent_llm')->write('llm_http_request:' . $this->trimLogValue([
                'rid' => $rid,
                'provider' => 'doubao',
                'endpoint' => $endpoint,
                'method' => 'POST',
                'headers' => $this->sanitizeHeaders($headers),
                'json' => $body,
                'guzzle' => [
                    'stream' => $options['stream'] ?? false,
                ],
            ]), 'info');

            $response = $this->client->post($endpoint, [
                'headers' => $headers,
                'json' => $body,
                'stream' => $options['stream'] ?? false,
            ]);

            if ($options['stream'] ?? false) {
                Log::channel('agent_llm')->write('llm_http_response:' . $this->trimLogValue([
                    'rid' => $rid,
                    'provider' => 'doubao',
                    'endpoint' => $endpoint,
                    'status' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                    'stream' => true,
                ]), 'info');
                return $response->getBody(); // Return stream resource
            }

            $raw = $response->getBody()->getContents();
            Log::channel('agent_llm')->write('llm_http_response:' . $this->trimLogValue([
                'rid' => $rid,
                'provider' => 'doubao',
                'endpoint' => $endpoint,
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => $raw,
            ]), 'info');
            $contents = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::channel('agent_llm')->write('llm_http_error: JSON decode failed: ' . json_last_error_msg() . ' Raw: ' . $this->trimLogValue($raw), 'error');
                throw new \Exception('Doubao API Response JSON decode failed: ' . json_last_error_msg());
            }
            return $contents;

        } catch (RequestException $e) {
            // Basic retry logic could go here or be handled by a middleware
            $maskedKey = substr($apiKey, 0, 4) . '***' . substr($apiKey, -4);
            Log::error("Doubao API Error. Key: {$maskedKey}. Msg: " . $e->getMessage());
            
            if ($e->hasResponse()) {
                $resp = $e->getResponse();
                $raw = $resp->getBody()->getContents();
                Log::channel('agent_llm')->write('llm_http_error:' . $this->trimLogValue([
                    'provider' => 'doubao',
                    'endpoint' => $endpoint,
                    'status' => $resp->getStatusCode(),
                    'headers' => $resp->getHeaders(),
                    'body' => $raw,
                    'error' => $e->getMessage(),
                ]), 'error');
                $errorBody = json_decode($raw, true);
                // Extract specific error message from Doubao response
                $apiMsg = $errorBody['error']['message'] ?? $e->getMessage();
                throw new \Exception('Doubao API Error: ' . $apiMsg);
            }
            Log::channel('agent_llm')->write('llm_http_error:' . $this->trimLogValue([
                'provider' => 'doubao',
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]), 'error');
            throw $e;
        }
    }
}
