<?php
namespace app\service\image;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;
use app\model\SystemErrorLog;

class AntigravityImageProvider implements ImageProviderInterface
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 300, // Chat interfaces can be slow for image gen
            'verify' => false,
        ]);
    }

    public function generate(string $prompt, array $config, array $options = []): array
    {
        $apiKey = $config['api_key'] ?? '';
        $endpoint = $config['endpoint'] ?? $config['url'] ?? $config['apiUrl'] ?? '';
        
        if (empty($endpoint)) {
            throw new \Exception('No endpoint configured for Antigravity model.');
        }

        // Handle base_url case if endpoint doesn't end with /chat/completions
        if (stripos($endpoint, '/chat/completions') === false) {
             $endpoint = rtrim($endpoint, '/') . '/chat/completions';
        }

        $model = $config['model_id'] ?? 'gemini-3-pro-image';

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        // Construct Chat Completion Body
        $body = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'stream' => false
        ];

        $sizeStr = null;
        if (isset($options['size']) && is_string($options['size']) && trim($options['size']) !== '') {
            $sizeStr = trim($options['size']);
        } elseif (isset($options['width']) && isset($options['height'])) {
            $w = (int)$options['width'];
            $h = (int)$options['height'];
            if ($w > 0 && $h > 0) {
                $sizeStr = $w . 'x' . $h;
            }
        }
        if (!$sizeStr) {
            $sizeStr = '1024x1024';
        }

        $body['size'] = $sizeStr;
        $body['extra_body'] = [ 'size' => $sizeStr ];

        if (preg_match('/^\s*(\d{2,5})\s*x\s*(\d{2,5})\s*$/i', $sizeStr, $m)) {
            $w = (int)$m[1];
            $h = (int)$m[2];
            if ($w > 0 && $h > 0) {
                $a = $w; $b = $h;
                while ($b !== 0) { $t = $b; $b = $a % $t; $a = $t; }
                $g = max(1, $a);
                $rw = (int)round($w / $g);
                $rh = (int)round($h / $g);
                $suffix = null;
                if ($rw === 16 && $rh === 9) $suffix = '16-9';
                elseif ($rw === 9 && $rh === 16) $suffix = '9-16';
                elseif ($rw === 4 && $rh === 3) $suffix = '4-3';
                elseif ($rw === 3 && $rh === 4) $suffix = '3-4';
                elseif ($rw === 1 && $rh === 1) $suffix = '1-1';

                if ($suffix && strpos($model, '-') === false) {
                    $body['model'] = $model . '-' . $suffix;
                }
            }
        }

        try {
            Log::info("Antigravity Request: " . $endpoint . " Body: " . json_encode($body, JSON_UNESCAPED_UNICODE));
            
            $response = $this->client->post($endpoint, [
                'headers' => $headers,
                'json' => $body,
            ]);

            $content = $response->getBody()->getContents();
            $json = json_decode($content, true);

            Log::info("Antigravity Response: " . substr($content, 0, 1000));

            if (isset($json['error'])) {
                $msg = $json['error']['message'] ?? json_encode($json['error']);
                throw new \Exception('Antigravity API Error: ' . $msg);
            }

            $messageContent = $json['choices'][0]['message']['content'] ?? '';

            $contentText = '';
            if (is_string($messageContent)) {
                $contentText = $messageContent;
            } elseif (is_array($messageContent)) {
                $parts = [];
                foreach ($messageContent as $seg) {
                    if (is_string($seg)) {
                        $parts[] = $seg;
                    } elseif (is_array($seg)) {
                        if (isset($seg['text']) && is_string($seg['text'])) {
                            $parts[] = $seg['text'];
                        } elseif (isset($seg['content']) && is_string($seg['content'])) {
                            $parts[] = $seg['content'];
                        }
                    }
                }
                $contentText = implode("\n", $parts);
            }

            $contentText = trim((string)$contentText);
            if ($contentText === '') {
                throw new \Exception('Empty response content from Antigravity API');
            }

            $imageUrl = null;

            if (preg_match('/!\[[\\s\\S]*?\\]\\(([^)]+)\\)/', $contentText, $matches)) {
                $imageUrl = trim($matches[1]);
            }

            if (!$imageUrl) {
                if (preg_match('#data:image/[^;]+;base64,[A-Za-z0-9+/=\\r\\n]+#i', $contentText, $m)) {
                    $imageUrl = trim($m[0]);
                }
            }

            if (!$imageUrl) {
                if (preg_match('/https?:\\/\\/[^\\s\\)]+/', $contentText, $m)) {
                    $imageUrl = trim($m[0]);
                }
            }

            if (!$imageUrl) {
                throw new \Exception('Could not extract image URL from response: ' . mb_substr($contentText, 0, 500));
            }

            if (is_string($imageUrl) && preg_match('#^data:image/[^;]+;base64,#i', $imageUrl)) {
                return [
                    'data' => [
                        ['b64_json' => $imageUrl]
                    ]
                ];
            }

            return [
                'data' => [
                    ['url' => $imageUrl]
                ]
            ];

        } catch (RequestException $e) {
            $msg = $e->getMessage();
            if ($e->hasResponse()) {
                $msg .= ' Response: ' . $e->getResponse()->getBody()->getContents();
            }
            $this->logError($e, $endpoint, $body);
            throw new \Exception('Antigravity Request Failed: ' . $msg);
        } catch (\Throwable $e) {
            $this->logError($e, $endpoint, $body);
            throw $e;
        }
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
