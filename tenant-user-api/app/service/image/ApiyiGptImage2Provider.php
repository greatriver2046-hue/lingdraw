<?php
namespace app\service\image;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use think\facade\Log;

class ApiyiGptImage2Provider implements ImageProviderInterface
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
        $apiKey = trim((string)($config['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \Exception('API Key is required for APIYI gpt-image-2');
        }

        $model = trim((string)($config['model_id'] ?? 'gpt-image-2'));
        $family = $this->detectModelFamily($config, $options, $model);
        $referenceImages = $this->extractReferenceImages($options);

        if (!empty($referenceImages)) {
            return $this->editImage($prompt, $config, $options, $referenceImages, $model, $apiKey, $family);
        }

        return $this->generateImage($prompt, $config, $options, $model, $apiKey, $family);
    }

    protected function generateImage(string $prompt, array $config, array $options, string $model, string $apiKey, string $family): array
    {
        $endpoint = $this->buildEndpoint($config['endpoint'] ?? '', '/images/generations');
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => $this->formatAuthorization($apiKey),
        ];

        $body = [
            'model' => $model,
            'prompt' => $prompt,
        ];

        if ($family === 'gpt-image-2-all') {
            if (array_key_exists('response_format', $options) && $options['response_format'] !== null && $options['response_format'] !== '') {
                $body['response_format'] = (string)$options['response_format'];
            }
        } else {
            $body['n'] = max(1, (int)($options['n'] ?? 1));

            $size = $this->resolveSize($options);
            if ($size !== null) {
                $body['size'] = $size;
            }

            foreach (['response_format', 'quality', 'output_format', 'output_compression', 'background', 'user'] as $key) {
                if (array_key_exists($key, $options) && $options[$key] !== null && $options[$key] !== '') {
                    $body[$key] = $options[$key];
                }
            }
        }

        try {
            $response = $this->client->post($endpoint, [
                'headers' => $headers,
                'json' => $body,
            ]);

            $json = json_decode($response->getBody()->getContents(), true);
            return is_array($json) ? $json : [];
        } catch (RequestException $e) {
            throw $this->convertRequestException($e, 'APIYI ' . $family . ' text-to-image');
        }
    }

    protected function editImage(string $prompt, array $config, array $options, array $referenceImages, string $model, string $apiKey, string $family): array
    {
        Log::info('ApiyiGptImage2Provider editImage called', [
            'family' => $family,
            'model' => $model,
            'referenceImagesCount' => count($referenceImages),
            'referenceImages' => $referenceImages,
        ]);

        $endpoint = $this->buildEndpoint($config['endpoint'] ?? '', '/images/edits');
        $headers = [
            'Authorization' => $this->formatAuthorization($apiKey),
        ];

        $multipart = [
            ['name' => 'model', 'contents' => $model],
            ['name' => 'prompt', 'contents' => $prompt],
        ];

        if ($family === 'gpt-image-2-all') {
            if (array_key_exists('response_format', $options) && $options['response_format'] !== null && $options['response_format'] !== '') {
                $multipart[] = ['name' => 'response_format', 'contents' => (string)$options['response_format']];
            }
        } else {
            $multipart[] = ['name' => 'n', 'contents' => (string)max(1, (int)($options['n'] ?? 1))];

            $size = $this->resolveSize($options);
            if ($size !== null) {
                $multipart[] = ['name' => 'size', 'contents' => $size];
            }

            foreach (['response_format', 'quality', 'output_format', 'output_compression', 'background', 'user'] as $key) {
                if (array_key_exists($key, $options) && $options[$key] !== null && $options[$key] !== '') {
                    $multipart[] = ['name' => $key, 'contents' => (string)$options[$key]];
                }
            }
        }

        $tempFiles = [];
        $processedCount = 0;
        $failedCount = 0;

        try {
            Log::info('ApiyiGptImage2Provider referenceImages count', ['count' => count($referenceImages)]);
            foreach ($referenceImages as $idx => $imageRef) {
                Log::info('ApiyiGptImage2Provider loop iteration', ['idx' => $idx, 'ref_type' => gettype($imageRef)]);
                if (!is_string($imageRef)) {
                    Log::error('ApiyiGptImage2Provider imageRef is not a string', ['type' => gettype($imageRef)]);
                    $failedCount++;
                    continue;
                }
                try {
                    $file = $this->createUploadFile($imageRef, 'image');
                    $isSuccess = $file !== null && is_array($file) && isset($file['path']) && isset($file['filename']);
                    Log::info('ApiyiGptImage2Provider createUploadFile result', [
                        'is_null' => is_null($file),
                        'is_array' => is_array($file),
                        'has_path' => is_array($file) ? isset($file['path']) : false,
                        'has_filename' => is_array($file) ? isset($file['filename']) : false,
                        'success' => $isSuccess,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('ApiyiGptImage2Provider createUploadFile exception', ['error' => $e->getMessage()]);
                    $failedCount++;
                    continue;
                }
                if (!$isSuccess) {
                    Log::info('ApiyiGptImage2Provider createUploadFile returned invalid result');
                    $failedCount++;
                    continue;
                }
                $tempFiles[] = $file['path'];
                $multipart[] = [
                    'name' => 'image[]',
                    'contents' => fopen($file['path'], 'rb'),
                    'filename' => $file['filename'],
                    'headers' => [
                        'Content-Type' => $file['mime'],
                    ],
                ];
                $processedCount++;
                Log::info('ApiyiGptImage2Provider image added to multipart', ['processedCount' => $processedCount, 'multipartCount' => count($multipart)]);
            }

            Log::info('ApiyiGptImage2Provider loop finished', ['processedCount' => $processedCount, 'failedCount' => $failedCount, 'multipartCount' => count($multipart)]);

            if (count($multipart) < 3) {
                Log::error('ApiyiGptImage2Provider not enough images', ['multipartCount' => count($multipart), 'baseCount' => 3]);
                throw new \Exception('At least one reference image is required for APIYI gpt-image-2 edit');
            }

            if ($family !== 'gpt-image-2-all') {
                $maskRef = $options['mask'] ?? ($options['mask_url'] ?? null);
                if (is_string($maskRef) && trim($maskRef) !== '') {
                    $maskFile = $this->createUploadFile($maskRef, 'mask');
                    if ($maskFile !== null) {
                        $tempFiles[] = $maskFile['path'];
                        $multipart[] = [
                            'name' => 'mask',
                            'contents' => fopen($maskFile['path'], 'rb'),
                            'filename' => $maskFile['filename'],
                            'headers' => [
                                'Content-Type' => $maskFile['mime'],
                            ],
                        ];
                    }
                }
            }

            $response = $this->client->post($endpoint, [
                'headers' => $headers,
                'multipart' => $multipart,
            ]);

            $json = json_decode($response->getBody()->getContents(), true);
            return is_array($json) ? $json : [];
        } catch (RequestException $e) {
            throw $this->convertRequestException($e, 'APIYI ' . $family . ' image-edit');
        } finally {
            foreach ($multipart as $part) {
                if (isset($part['contents']) && is_resource($part['contents'])) {
                    @fclose($part['contents']);
                }
            }
            foreach ($tempFiles as $path) {
                if (is_string($path) && $path !== '' && file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    protected function extractReferenceImages(array $options): array
    {
        Log::info('ApiyiGptImage2Provider extractReferenceImages called', [
            'options_keys' => array_keys($options),
            'reference_images' => $options['reference_images'] ?? null,
            'image_list' => $options['image_list'] ?? null,
        ]);

        $candidates = [];

        foreach (['reference_images', 'image_urls', 'image_list'] as $key) {
            if (!empty($options[$key]) && is_array($options[$key])) {
                $candidates = array_merge($candidates, $options[$key]);
            }
        }

        foreach (['image', 'image_url', 'init_image'] as $key) {
            if (!empty($options[$key]) && is_string($options[$key])) {
                $candidates[] = $options[$key];
            }
        }

        $clean = [];
        foreach ($candidates as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $clean[] = $item;
        }

        return array_slice(array_values(array_unique($clean)), 0, 5);
    }

    protected function detectModelFamily(array $config, array $options, string $model): string
    {
        $identity = trim((string)($options['model_identity'] ?? ($config['model_identity'] ?? '')));
        $providerCode = trim((string)($config['provider_code'] ?? ''));
        $haystack = strtolower($model . ' ' . $identity . ' ' . $providerCode);

        if (strpos($haystack, 'gpt-image-2-all') !== false) {
            return 'gpt-image-2-all';
        }

        return 'gpt-image-2';
    }

    protected function resolveSize(array $options): ?string
    {
        if (isset($options['size']) && is_string($options['size']) && trim($options['size']) !== '') {
            return trim($options['size']);
        }

        $width = isset($options['width']) ? (int)$options['width'] : 0;
        $height = isset($options['height']) ? (int)$options['height'] : 0;
        if ($width > 0 && $height > 0) {
            return $width . 'x' . $height;
        }

        return null;
    }

    protected function buildEndpoint($endpoint, string $path): string
    {
        $endpoint = trim((string)$endpoint);
        if ($endpoint === '') {
            return 'https://api.apiyi.com/v1' . $path;
        }

        if (preg_match('#^(https?://.+?)/images/(generations|edits)/?$#i', $endpoint, $m)) {
            return rtrim($m[1], '/') . $path;
        }

        if (preg_match('#/v1/?$#', $endpoint)) {
            return rtrim($endpoint, '/') . $path;
        }

        return rtrim($endpoint, '/') . $path;
    }

    protected function formatAuthorization(string $apiKey): string
    {
        if (stripos($apiKey, 'Bearer ') === 0) {
            return $apiKey;
        }

        return 'Bearer ' . $apiKey;
    }

    protected function createUploadFile(string $source, string $prefix): ?array
    {
        $source = trim($source);
        if ($source === '') {
            return null;
        }

        if (preg_match('#^data:([^;]+);base64,#i', $source, $m)) {
            $mime = strtolower(trim($m[1]));
            $binary = base64_decode(substr($source, strpos($source, ',') + 1), true);
            if ($binary === false) {
                throw new \Exception('Failed to decode base64 image data');
            }
            return $this->writeTempFile($binary, $prefix, $mime);
        }

        if (preg_match('#^https?://#i', $source)) {
            Log::info('ApiyiGptImage2Provider downloading image from URL', ['url' => $source]);
            try {
                $response = $this->client->get($source, ['http_errors' => true]);
                $body = $response->getBody();
                $binary = $body->getContents();
                Log::info('ApiyiGptImage2Provider downloaded image raw', [
                    'url' => $source,
                    'size' => strlen($binary),
                    'type' => gettype($binary),
                ]);
                if (!is_string($binary) || strlen($binary) === 0) {
                    throw new \Exception('Downloaded content is not a valid string');
                }
                $contentType = $response->getHeader('Content-Type');
                $mime = is_array($contentType) ? ($contentType[0] ?? 'image/png') : (is_string($contentType) ? $contentType : 'image/png');
                Log::info('ApiyiGptImage2Provider about to write temp file');
                return $this->writeTempFile($binary, $prefix, $mime, $source);
            } catch (\Throwable $e) {
                Log::error('ApiyiGptImage2Provider download failed', ['url' => $source, 'error' => $e->getMessage()]);
                throw $e;
            }
        }

        if (is_file($source)) {
            $binary = file_get_contents($source);
            if ($binary === false) {
                throw new \Exception('Failed to read local image file: ' . $source);
            }
            $mime = function_exists('mime_content_type') ? (mime_content_type($source) ?: 'image/png') : 'image/png';
            return $this->writeTempFile($binary, $prefix, $mime, basename($source));
        }

        throw new \Exception('Unsupported image source for upload');
    }

    protected function writeTempFile(string $binary, string $prefix, string $mime, string $nameHint = ''): array
    {
        Log::info('ApiyiGptImage2Provider writeTempFile called', [
            'prefix' => $prefix,
            'mime' => $mime,
            'binarySize' => strlen($binary),
        ]);
        $ext = $this->guessExtension($mime, $nameHint);
        $tmp = tempnam(sys_get_temp_dir(), $prefix . '_');
        if ($tmp === false) {
            throw new \Exception('Failed to create temporary file');
        }

        $target = $tmp . '.' . $ext;
        if (!@rename($tmp, $target)) {
            $target = $tmp;
        }

        $written = file_put_contents($target, $binary);
        Log::info('ApiyiGptImage2Provider writeTempFile written', ['target' => $target, 'written' => $written]);
        if ($written === false) {
            @unlink($target);
            throw new \Exception('Failed to write temporary image file');
        }

        Log::info('ApiyiGptImage2Provider writeTempFile success', ['path' => $target, 'filename' => basename($target)]);
        return [
            'path' => $target,
            'filename' => basename($target),
            'mime' => $mime ?: 'image/png',
        ];
    }

    protected function guessExtension(string $mime, string $nameHint = ''): string
    {
        $mime = strtolower(trim($mime));
        $map = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        if (isset($map[$mime])) {
            return $map[$mime];
        }

        $ext = strtolower(pathinfo(parse_url($nameHint, PHP_URL_PATH) ?: $nameHint, PATHINFO_EXTENSION));
        if ($ext !== '') {
            return $ext;
        }

        return 'png';
    }

    protected function convertRequestException(RequestException $e, string $prefix): \Exception
    {
        Log::error($prefix . ' error: ' . $e->getMessage());

        if ($e->hasResponse()) {
            $raw = $e->getResponse()->getBody()->getContents();
            $errorBody = json_decode($raw, true);
            $msg = $errorBody['error']['message']
                ?? $errorBody['message']
                ?? $errorBody['msg']
                ?? $raw
                ?? $e->getMessage();

            return new \Exception($prefix . ' error: ' . $msg, (int)$e->getCode(), $e);
        }

        return new \Exception($prefix . ' error: ' . $e->getMessage(), (int)$e->getCode(), $e);
    }
}
