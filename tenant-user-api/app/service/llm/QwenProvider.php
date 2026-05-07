<?php
namespace app\service\llm;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;

class QwenProvider implements LlmProviderInterface
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
        $apiKey = $config['api_key'] ?? '';
        $endpoint = $config['endpoint'] ?? 'https://dashscope.aliyuncs.com/compatible/v1/chat/completions';
        $model = $config['model_id'] ?? 'qwen3-max';

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

        if (isset($options['tools'])) {
            $body['tools'] = $options['tools'];
        }
        if (isset($options['tool_choice'])) {
            $body['tool_choice'] = $options['tool_choice'];
        }
        if (isset($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        $timeout = isset($options['timeout']) ? (float)$options['timeout'] : 300.0;
        $connectTimeout = isset($options['connect_timeout']) ? (float)$options['connect_timeout'] : 15.0;
        $retries = isset($options['retry']) ? max(0, (int)$options['retry']) : 0;
        $delayMs = isset($options['retry_delay_ms']) ? max(0, (int)$options['retry_delay_ms']) : 500;

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $rid = uniqid('llm_', true);
                Log::channel('agent_llm')->write('llm_http_request:' . $this->trimLogValue([
                    'rid' => $rid,
                    'provider' => 'qwen',
                    'endpoint' => $endpoint,
                    'method' => 'POST',
                    'headers' => $this->sanitizeHeaders($headers),
                    'json' => $body,
                    'guzzle' => [
                        'stream' => $options['stream'] ?? false,
                        'timeout' => $timeout,
                        'connect_timeout' => $connectTimeout,
                        'attempt' => $attempt,
                        'retries' => $retries,
                    ],
                ]), 'info');

                $response = $this->client->post($endpoint, [
                    'headers' => $headers,
                    'json' => $body,
                    'stream' => $options['stream'] ?? false,
                    'timeout' => $timeout,
                    'connect_timeout' => $connectTimeout,
                ]);

                if ($options['stream'] ?? false) {
                    Log::channel('agent_llm')->write('llm_http_response:' . $this->trimLogValue([
                        'rid' => $rid,
                        'provider' => 'qwen',
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
                    'provider' => 'qwen',
                    'endpoint' => $endpoint,
                    'status' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                    'body' => $raw,
                ]), 'info');
                return json_decode($raw, true);
            } catch (RequestException $e) {
                $isTimeout = false;
                $ctx = method_exists($e, 'getHandlerContext') ? $e->getHandlerContext() : [];
                if (is_array($ctx) && isset($ctx['errno']) && (int)$ctx['errno'] === 28) {
                    $isTimeout = true;
                }
                if (!$isTimeout && strpos($e->getMessage(), 'cURL error 28') !== false) {
                    $isTimeout = true;
                }

                if ($isTimeout && $attempt <= (1 + $retries)) {
                    if ($delayMs > 0) {
                        usleep($delayMs * 1000);
                    }
                    continue;
                }

                Log::error('Qwen API Error: ' . $e->getMessage());
                if ($e->hasResponse()) {
                    $resp = $e->getResponse();
                    $raw = $resp->getBody()->getContents();
                    Log::channel('agent_llm')->write('llm_http_error:' . $this->trimLogValue([
                        'provider' => 'qwen',
                        'endpoint' => $endpoint,
                        'status' => $resp->getStatusCode(),
                        'headers' => $resp->getHeaders(),
                        'body' => $raw,
                        'error' => $e->getMessage(),
                    ]), 'error');
                    $errorBody = json_decode($raw, true);
                    $msg = $errorBody['error']['message'] ?? $e->getMessage();
                    throw new \Exception('Qwen API Error: ' . $msg);
                }
                Log::channel('agent_llm')->write('llm_http_error:' . $this->trimLogValue([
                    'provider' => 'qwen',
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]), 'error');
                throw $e;
            }
        }
    }
}
