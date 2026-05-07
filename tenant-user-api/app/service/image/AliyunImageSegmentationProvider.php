<?php
namespace app\service\image;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;

class AliyunImageSegmentationProvider implements ImageProviderInterface
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
        // For background removal, 'prompt' is the image URL
        $imageUrl = $prompt;
        
        $accessKeyId = $config['access_key_id'] ?? '';
        $accessKeySecret = $config['access_key_secret'] ?? '';
        
        // Fallback: if AK/SK are empty, try to get them from api_key
        if (empty($accessKeyId) || empty($accessKeySecret)) {
            $apiKey = $config['api_key'] ?? '';
            if (!empty($apiKey) && strpos($apiKey, ':') !== false) {
                list($accessKeyId, $accessKeySecret) = explode(':', $apiKey, 2);
            } else {
                // If no colon, maybe api_key is just AK and we hope SK is in endpoint (hacky but possible for some users)
                $accessKeyId = $apiKey;
                // If endpoint is not a URL, it might be the SK
                $ep = $config['endpoint'] ?? '';
                if (!empty($ep) && strpos($ep, 'http') === false) {
                    $accessKeySecret = $ep;
                }
            }
        }

        $endpoint = $config['endpoint'] ?? 'imageseg.cn-shanghai.aliyuncs.com';
        // Clean up endpoint if it's a full URL
        if (strpos($endpoint, 'http') !== false) {
            $urlParts = parse_url($endpoint);
            $endpoint = $urlParts['host'] ?? 'imageseg.cn-shanghai.aliyuncs.com';
        }
        
        if (empty($accessKeyId) || empty($accessKeySecret)) {
            throw new \Exception('Aliyun AccessKeyId or AccessKeySecret is missing. Please configure them in "API Key" field as "AK:SK" format.');
        }

        $modelType = trim($config['model_type'] ?? 'imageseg');
        $identity = $config['model_identity'] ?? '';
        $modelId = $config['model_id'] ?? '';

        // Heuristic: if identity or model_id contains 'HD' or 'high', force HD mode if not explicitly set
        if ($modelType !== 'imageseg_hd') {
             if (stripos($identity, 'hd') !== false || stripos($identity, 'high') !== false || 
                 stripos($modelId, 'hd') !== false || stripos($modelId, 'high') !== false || 
                 $identity === 'imageHDseg' || $modelId === 'imageHDseg') {
                $modelType = 'imageseg_hd';
             }
        }

        if ($modelType === 'imageseg_hd') {
            return $this->generateHD($imageUrl, $accessKeyId, $accessKeySecret, $endpoint);
        }

        try {
            // Using Aliyun VIAPI (Visual Intelligence API) for SegmentCommonImage
            // API Reference: https://help.aliyun.com/zh/viapi/developer-reference/api-k8cs8t
            
            $params = [
                'Action' => 'SegmentCommonImage',
                'Version' => '2019-12-30',
                'Format' => 'JSON',
                'SignatureMethod' => 'HMAC-SHA1',
                'SignatureVersion' => '1.0',
                'AccessKeyId' => $accessKeyId,
                'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'SignatureNonce' => uniqid(),
                'ImageURL' => $imageUrl,
            ];

            // Calculate Signature
            $params['Signature'] = $this->computeSignature($params, $accessKeySecret);

            $response = $this->client->get('https://' . $endpoint . '/', [
                'query' => $params
            ]);

            $json = json_decode($response->getBody()->getContents(), true);
            
            if (isset($json['Data']['ImageURL'])) {
                return [
                    'images' => [
                        ['url' => $json['Data']['ImageURL']]
                    ],
                    'usage' => [
                        'provider' => 'aliyun_imageseg'
                    ]
                ];
            } else {
                 $msg = $json['Message'] ?? 'Unknown error from Aliyun API';
                 throw new \Exception('Aliyun ImageSeg Error: ' . $msg);
            }

        } catch (RequestException $e) {
            Log::error('Aliyun ImageSeg API Error: ' . $e->getMessage());
            if ($e->hasResponse()) {
                $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                $msg = $errorBody['Message'] ?? $e->getMessage();
                throw new \Exception('Aliyun ImageSeg API Error: ' . $msg);
            }
            throw $e;
        }
    }

    private function generateHD(string $imageUrl, string $accessKeyId, string $accessKeySecret, string $endpoint): array
    {
        try {
            // Step 1: Submit Task
            // API: https://help.aliyun.com/zh/viapi/developer-reference/api-universal-hd-split
            $params = [
                'Action' => 'SegmentHDCommonImage',
                'Version' => '2019-12-30',
                'Format' => 'JSON',
                'SignatureMethod' => 'HMAC-SHA1',
                'SignatureVersion' => '1.0',
                'AccessKeyId' => $accessKeyId,
                'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'SignatureNonce' => uniqid(),
                'ImageUrl' => $imageUrl,
            ];

            $params['Signature'] = $this->computeSignature($params, $accessKeySecret);

            $response = $this->client->get('https://' . $endpoint . '/', [
                'query' => $params
            ]);

            $json = json_decode($response->getBody()->getContents(), true);
            $requestId = $json['RequestId'] ?? '';
            $jobId = $json['Data']['JobId'] ?? $requestId;

            if (empty($jobId)) {
                $msg = $json['Message'] ?? 'Failed to submit HD matting task';
                throw new \Exception('Aliyun HD ImageSeg Error: ' . $msg);
            }

            // Step 2: Polling Result
            // API: GetAsyncJobResult
            $maxRetries = 300; // 300 seconds (5 minutes)
            $retryCount = 0;

            while ($retryCount < $maxRetries) {
                sleep(1);
                $retryCount++;

                $pollParams = [
                    'Action' => 'GetAsyncJobResult',
                    'Version' => '2019-12-30',
                    'Format' => 'JSON',
                    'SignatureMethod' => 'HMAC-SHA1',
                    'SignatureVersion' => '1.0',
                    'AccessKeyId' => $accessKeyId,
                    'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                    'SignatureNonce' => uniqid(),
                    'JobId' => $jobId,
                ];

                $pollParams['Signature'] = $this->computeSignature($pollParams, $accessKeySecret);

                $pollResponse = $this->client->get('https://' . $endpoint . '/', [
                    'query' => $pollParams
                ]);

                $pollJson = json_decode($pollResponse->getBody()->getContents(), true);
                // Alibaba Cloud VIAPI asynchronous task status: 
                // QUEUING, PROCESSING, PROCESS_SUCCESS, PROCESS_FAILED
                $status = $pollJson['Data']['Status'] ?? '';
                
                // Add logging for debugging
                Log::info("Aliyun HD ImageSeg Polling [JobId: {$jobId}]: Status = {$status}");

                if ($status === 'PROCESS_SUCCESS') {
                    $resultStr = $pollJson['Data']['Result'] ?? '';
                    Log::info("Aliyun HD ImageSeg Result JSON: " . $resultStr);
                    $resultJson = json_decode($resultStr, true);
                    
                    // Alibaba Cloud HD Matting might use 'ImageURL', 'ImageUrl' or 'imageUrl'
                    $finalUrl = $resultJson['ImageURL'] ?? ($resultJson['ImageUrl'] ?? ($resultJson['imageUrl'] ?? ''));
                    
                    if ($finalUrl) {
                        return [
                            'images' => [
                                ['url' => $finalUrl]
                            ],
                            'usage' => [
                                'provider' => 'aliyun_imageseg_hd',
                                'job_id' => $jobId
                            ]
                        ];
                    } else {
                        Log::warning("Aliyun HD ImageSeg success but ImageURL missing in Result: " . $resultStr);
                    }
                } elseif ($status === 'PROCESS_FAILED') {
                    $msg = $pollJson['Data']['ErrorMessage'] ?? 'Task failed';
                    throw new \Exception('Aliyun HD ImageSeg Task Failed: ' . $msg);
                }

                // If still QUEUED or PROCESSING, continue polling
            }

            throw new \Exception('Aliyun HD ImageSeg Task Timeout.');

        } catch (RequestException $e) {
            Log::error('Aliyun HD ImageSeg API Error: ' . $e->getMessage());
            if ($e->hasResponse()) {
                $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                $msg = $errorBody['Message'] ?? $e->getMessage();
                throw new \Exception('Aliyun HD ImageSeg API Error: ' . $msg);
            }
            throw $e;
        }
    }

    private function computeSignature($parameters, $accessKeySecret)
    {
        ksort($parameters);
        $canonicalizedQueryString = '';
        foreach ($parameters as $key => $value) {
            $canonicalizedQueryString .= '&' . $this->percentEncode($key) . '=' . $this->percentEncode($value);
        }
        $stringToSign = 'GET&%2F&' . $this->percentEncode(substr($canonicalizedQueryString, 1));
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
        return $signature;
    }

    private function percentEncode($str)
    {
        $res = urlencode($str);
        $res = str_replace('+', '%20', $res);
        $res = str_replace('*', '%2A', $res);
        $res = str_replace('%7E', '~', $res);
        return $res;
    }
}
