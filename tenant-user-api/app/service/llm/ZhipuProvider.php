<?php
namespace app\service\llm;

class ZhipuProvider extends GptProvider
{
    public function chat(array $messages, array $config, array $options = [])
    {
        // Set default endpoint for Zhipu AI if not provided
        if (empty($config['endpoint'])) {
            $config['endpoint'] = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        }

        // Generate JWT token if API key is in Zhipu format (id.secret)
        if (strpos($config['api_key'], '.') !== false && strpos($config['api_key'], 'sk-') !== 0) {
            $config['api_key'] = $this->generateToken($config['api_key']);
        }

        // Disable thinking process output if requested (or default to disabled to hide from frontend)
        // User instruction: thinking.type = disabled
        if (!isset($options['thinking'])) {
            $options['thinking'] = ['type' => 'disabled'];
        }

        return parent::chat($messages, $config, $options);
    }

    protected function generateToken($apiKey, $expireSeconds = 300)
    {
        $parts = explode('.', $apiKey);
        if (count($parts) !== 2) {
            return $apiKey; // Return original if not valid format
        }
        
        list($id, $secret) = $parts;
        
        $now = time() * 1000;
        $payload = [
            'api_key' => $id,
            'exp' => $now + ($expireSeconds * 1000),
            'timestamp' => $now,
        ];
        
        $header = [
            'alg' => 'HS256',
            'sign_type' => 'SIGN',
        ];

        $base64UrlHeader = $this->base64UrlEncode(json_encode($header));
        $base64UrlPayload = $this->base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = $this->base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    protected function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
