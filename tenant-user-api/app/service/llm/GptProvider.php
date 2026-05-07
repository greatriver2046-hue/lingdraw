<?php
namespace app\service\llm;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;

class GptProvider implements LlmProviderInterface
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
        $endpoint = $config['endpoint'] ?? 'https://api.openai.com/v1/chat/completions';
        $model = $config['model_id']; // e.g. gpt-5, gpt-4o

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'stream' => $options['stream'] ?? false,
        ];

        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }

        if (isset($options['functions'])) {
            $body['functions'] = $options['functions'];
        }
        
        if (isset($options['tools'])) {
             $body['tools'] = $options['tools'];
        }
        if (isset($options['tool_choice'])) {
             $body['tool_choice'] = $options['tool_choice'];
        }
        if (isset($options['thinking'])) {
             $body['thinking'] = $options['thinking'];
        }
        if (isset($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        try {
            $rid = uniqid('llm_', true);
            Log::channel('agent_llm')->write('llm_http_request:' . $this->trimLogValue([
                'rid' => $rid,
                'provider' => 'gpt',
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
                    'provider' => 'gpt',
                    'endpoint' => $endpoint,
                    'status' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                    'stream' => true,
                ]), 'info');
                return $response->getBody();
            }

            $raw = $response->getBody()->getContents();
            Log::channel('agent_llm')->write('llm_http_response:' . $this->trimLogValue([
                'rid' => $rid,
                'provider' => 'gpt',
                'endpoint' => $endpoint,
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => $raw,
            ]), 'info');
            return json_decode($raw, true);

        } catch (RequestException $e) {
            Log::error('GPT API Error: ' . $e->getMessage());
            if ($e->hasResponse()) {
                 // Handle 429 Rate Limit specially if needed
                if ($e->getResponse()->getStatusCode() === 429) {
                    throw new \Exception('GPT API Rate Limit Exceeded');
                }
                $resp = $e->getResponse();
                $raw = $resp->getBody()->getContents();
                Log::channel('agent_llm')->write('llm_http_error:' . $this->trimLogValue([
                    'provider' => 'gpt',
                    'endpoint' => $endpoint,
                    'status' => $resp->getStatusCode(),
                    'headers' => $resp->getHeaders(),
                    'body' => $raw,
                    'error' => $e->getMessage(),
                ]), 'error');
                $errorBody = json_decode($raw, true);
                throw new \Exception('GPT API Error: ' . ($errorBody['error']['message'] ?? $e->getMessage()));
            }
            Log::channel('agent_llm')->write('llm_http_error:' . $this->trimLogValue([
                'provider' => 'gpt',
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]), 'error');
            throw $e;
        }
    }
}
