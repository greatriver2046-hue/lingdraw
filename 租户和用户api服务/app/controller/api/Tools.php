<?php
namespace app\controller\api;

use app\BaseController;
use app\service\ImageService;
use think\Request;
use think\facade\Log;
use app\model\SystemErrorLog;
use think\facade\Queue;
use think\facade\Cache;

class Tools extends BaseController
{
    protected $imageService;

    public function __construct(ImageService $imageService)
    {
        $this->imageService = $imageService;
    }

    public function generate_image_nanobananapro_antigravity(Request $request)
    {
        $params = $request->post();
        $prompt = $params['prompt'] ?? '';
        $imageName = $params['image_name'] ?? '';
        $sizeStr = $params['size'] ?? null;
        $width = isset($params['width']) ? (int)$params['width'] : null;
        $height = isset($params['height']) ? (int)$params['height'] : null;
        $taskType = $params['task_type'] ?? '';

        if (!$prompt || !$imageName || !$taskType) {
            try {
                SystemErrorLog::create([
                    'tenant_id' => $request->tenantId ?? null,
                    'user_id' => $request->userId ?? null,
                    'category' => 'tool',
                    'message' => 'Missing required fields',
                    'context' => 'generate_image_nanobananapro_antigravity',
                    'payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $ex) {}
            return json(['code' => 400, 'msg' => 'Missing required fields', 'data' => null]);
        }

        $options = [
                'model_identity' => 'gemini-3-pro-image-antigravity',
                'n' => 1,
                'task_type' => $taskType, // Pass task_type to options for provider
            ];
        if ($sizeStr) { $options['size'] = $sizeStr; }
        elseif (!is_null($width) && $width > 0 && !is_null($height) && $height > 0) { $options['size'] = $width . 'x' . $height; }
        else { $options['size'] = '1024x1024'; }

        try {
            $userId = $request->userId ?? null;

            $taskId = bin2hex(random_bytes(16));
            $payload = [
                'task_id' => $taskId,
                'prompt' => $prompt,
                'options' => $options,
                'user_id' => $userId,
                'tool_meta' => [
                    'image_name' => $imageName,
                    'task_type' => $taskType,
                    'resolution' => $options['size'],
                    'conversation_id' => isset($params['conversation_id']) ? (string)$params['conversation_id'] : ''
                ],
            ];
            
            $pushed = true;
            try {
                Queue::push('app\\job\\ImageGenerateJob', $payload, 'default');
            } catch (\Throwable $qe) {
                $pushed = false;
                $failArr = [ 'status' => 'failed', 'error' => 'queue_push_failed', 'updated_at' => time() ];
                Cache::set('image_task:' . $taskId, $failArr, 3600);
                return json(['code' => 500, 'msg' => '队列推送失败', 'data' => null]);
            }

            if ($pushed) {
                $statusArr = [ 'status' => 'queued', 'updated_at' => time() ];
                $cfg = config('queue.connections.redis');
                $redis = class_exists('Redis') ? new \Redis() : null;
                if ($redis) {
                    try {
                        $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
                        if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
                        if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
                        $redis->hMSet('image_task:' . $taskId, $statusArr);
                    } catch (\Throwable $e) {
                        Cache::set('image_task:' . $taskId, $statusArr, 3600);
                    }
                } else {
                    Cache::set('image_task:' . $taskId, $statusArr, 3600);
                }
                return json(['code' => 200, 'msg' => 'Queued', 'data' => [ 'task_id' => $taskId, 'status' => 'queued' ]]);
            }
            
            return json(['code' => 200, 'msg' => 'Success', 'data' => null]);

        } catch (\Exception $e) {
            Log::error('generate_image_nanobananapro_antigravity Error: ' . $e->getMessage());
            try {
                SystemErrorLog::create([
                    'tenant_id' => $request->tenantId ?? null,
                    'user_id' => $request->userId ?? null,
                    'category' => 'tool',
                    'message' => $e->getMessage(),
                    'context' => 'generate_image_nanobananapro_antigravity',
                    'payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $ex) {}
            return json(['code' => 500, 'msg' => '工具调用失败: ' . $e->getMessage(), 'data' => null]);
        }
    }

    protected function mapAspectRatioToSize($ratio)
    {
        // User requested "2k above" resolution (approx > 2MP or width >= 2048)
        switch ($ratio) {
            case '16:9': return '2560x1440'; // 2K QHD
            case '9:16': return '1440x2560';
            case '4:3':  return '2048x1536';
            case '3:4':  return '1536x2048';
            case '3:2':  return '2160x1440';
            case '2:3':  return '1440x2160';
            case '21:9': return '2560x1080';
            case '9:21': return '1080x2560';
            case '1:1':
            default:     return '2048x2048';
        }
    }

    protected function sanitizeImageList($list)
    {
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $u) {
            if (!is_string($u)) continue;
            $s = trim(str_replace('`', '', $u));
            if ($s !== '') { $out[] = $s; }
        }
        return $out;
    }

    protected function getImageDimensions($url)
    {
        try {
            $info = @getimagesize($url);
            if (is_array($info) && isset($info[0]) && isset($info[1]) && $info[0] > 0 && $info[1] > 0) {
                return ['width' => (int)$info[0], 'height' => (int)$info[1]];
            }
        } catch (\Throwable $e) {}
        try {
            $data = @file_get_contents($url);
            if ($data !== false) {
                $img = @imagecreatefromstring($data);
                if ($img) {
                    $w = imagesx($img); $h = imagesy($img);
                    imagedestroy($img);
                    if ($w > 0 && $h > 0) { return ['width' => (int)$w, 'height' => (int)$h]; }
                }
            }
        } catch (\Throwable $e2) {}
        return null;
    }

    protected function simplifyRatioLabel($w, $h)
    {
        $w = (int)$w; $h = (int)$h;
        if ($w <= 0 || $h <= 0) return '1:1';
        $a = $w; $b = $h;
        while ($b) { $t = $b; $b = $a % $t; $a = $t; }
        $g = max(1, $a);
        return intval(round($w / $g)) . ':' . intval(round($h / $g));
    }

    protected function parseSizeFromPrompt($text)
    {
        if (!is_string($text) || $text === '') return null;
        $text2 = str_replace(['×','*'], 'x', $text);
        if (preg_match('/(\d{3,5})\s*[xX]\s*(\d{3,5})/', $text2, $m)) {
            $w = (int)$m[1]; $h = (int)$m[2];
            if ($w > 0 && $h > 0) { return $w . 'x' . $h; }
        }
        return null;
    }

    protected function parseQualityFromPrompt($text)
    {
        if (!is_string($text) || $text === '') return null;
        $t = strtolower($text);
        if (preg_match('/\b4\s*k\b/', $t)) return '4k';
        if (preg_match('/\b2\s*k\b/', $t)) return '2k';
        return null;
    }

    protected function scaleSize($sizeStr, $factor)
    {
        if (!is_string($sizeStr) || $sizeStr === '') return $sizeStr;
        if ($factor === 1) return $sizeStr;
        $parts = explode('x', strtolower($sizeStr));
        if (count($parts) !== 2) return $sizeStr;
        $w = (int)trim($parts[0]);
        $h = (int)trim($parts[1]);
        if ($w <= 0 || $h <= 0) return $sizeStr;
        return ($w * $factor) . 'x' . ($h * $factor);
    }

    public function generate_image_seedream_v4_5(Request $request)
    {
        $params = $request->post();
        $prompt = $params['prompt'] ?? '';
        $imageList = $this->sanitizeImageList($params['image_list'] ?? []);
        $imageName = $params['image_name'] ?? '';
        $sizeStr = $params['size'] ?? null;
        $width = isset($params['width']) ? (int)$params['width'] : null;
        $height = isset($params['height']) ? (int)$params['height'] : null;
        $upscaleFactor = isset($params['upscale_factor']) ? (int)$params['upscale_factor'] : 1;
        $taskType = $params['task_type'] ?? '';
        $optMode = $params['optimize_prompt_mode'] ?? 'standard';

        

        if (!$prompt || !$imageName || !$taskType || !$sizeStr) {
            try {
                SystemErrorLog::create([
                    'tenant_id' => $request->tenantId ?? null,
                    'user_id' => $request->userId ?? null,
                    'category' => 'tool',
                    'message' => 'Missing required fields',
                    'context' => 'generate_image_seedream_v4_5',
                    'payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $ex) {}
            return json(['code' => 400, 'msg' => 'Missing required fields', 'data' => null]);
        }

        $options = [
            'model_identity' => 'seedream4.5',
            'response_format' => 'b64_json',
            'n' => 1,
            'upscale_factor' => $upscaleFactor,
            'optimize_prompt_mode' => $optMode
        ];
        if ($sizeStr) { $options['size'] = $sizeStr; }
        elseif (!is_null($width) && $width > 0 && !is_null($height) && $height > 0) { $options['size'] = $width . 'x' . $height; }

        if (is_array($imageList) && !empty($imageList)) {
            $options['reference_images'] = array_values($imageList);
            $options['sequential_image_generation'] = count($imageList) > 1 ? 'auto' : 'disabled';
        }

        try {
            $userId = $request->userId ?? null;

            $taskId = bin2hex(random_bytes(16));
            $payload = [
                'task_id' => $taskId,
                'prompt' => $prompt,
                'options' => $options,
                'user_id' => $userId,
                'tool_meta' => [
                    'image_name' => $imageName,
                    'task_type' => $taskType,
                    'resolution' => $sizeStr ?: ((!is_null($width) && $width > 0 && !is_null($height) && $height > 0) ? ($width . 'x' . $height) : null),
                    'conversation_id' => isset($params['conversation_id']) ? (string)$params['conversation_id'] : ''
                ],
            ];
            $pushed = true;
            $syncResult = null;
            try {
                Queue::push('app\\job\\ImageGenerateJob', $payload, 'default');
            } catch (\Throwable $qe) {
                $pushed = false;
                $failArr = [ 'status' => 'failed', 'error' => 'queue_push_failed', 'updated_at' => time() ];
                Cache::set('image_task:' . $taskId, $failArr, 3600);
                return json(['code' => 500, 'msg' => '队列推送失败', 'data' => null]);
            }

            if ($pushed) {
                $statusArr = [ 'status' => 'queued', 'updated_at' => time() ];
                $cfg = config('queue.connections.redis');
                $redis = class_exists('Redis') ? new \Redis() : null;
                if ($redis) {
                    try {
                        $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
                        if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
                        if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
                        $redis->hMSet('image_task:' . $taskId, $statusArr);
                    } catch (\Throwable $e) {
                        Cache::set('image_task:' . $taskId, $statusArr, 3600);
                    }
                } else {
                    Cache::set('image_task:' . $taskId, $statusArr, 3600);
                }
                return json(['code' => 200, 'msg' => 'Queued', 'data' => [ 'task_id' => $taskId, 'status' => 'queued' ]]);
            }

            return json(['code' => 200, 'msg' => 'Success', 'data' => $syncResult]);
        } catch (\Exception $e) {
            Log::error('generate_image_seedream_v4_5 Error: ' . $e->getMessage());
            try {
                SystemErrorLog::create([
                    'tenant_id' => $request->tenantId ?? null,
                    'user_id' => $request->userId ?? null,
                    'category' => 'tool',
                    'message' => $e->getMessage(),
                    'context' => 'generate_image_seedream_v4_5',
                    'payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $ex) {}
            return json(['code' => 500, 'msg' => '工具调用失败: ' . $e->getMessage(), 'data' => null]);
        }
    }

    public function generate_video_sora_duomi(Request $request)
    {
        $params = $request->post();
        $prompt = $params['prompt'] ?? '';
        $videoName = $params['image_name'] ?? ($params['video_name'] ?? ''); 
        $taskType = $params['task_type'] ?? '';

        if (!$prompt || !$videoName || !$taskType) {
            try {
                SystemErrorLog::create([
                    'tenant_id' => $request->tenantId ?? null,
                    'user_id' => $request->userId ?? null,
                    'category' => 'tool',
                    'message' => 'Missing required fields',
                    'context' => 'generate_video_sora_duomi',
                    'payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $ex) {}
            return json(['code' => 400, 'msg' => 'Missing required fields', 'data' => null]);
        }

        $options = [
            'model_identity' => 'sora2',
        ];

        // Map tool parameters to model options
        if (!empty($params['aspect_ratio'])) {
            $options['aspect_ratio'] = $params['aspect_ratio'];
        }
        if (!empty($params['duration'])) {
            $options['duration'] = (int)$params['duration'];
        }
        
        // Map image_urls to image_urls
        // Tool definition says image_urls is array[string]
        if (!empty($params['image_urls']) && is_array($params['image_urls'])) {
            $options['image_urls'] = $params['image_urls'];
        }

        try {
            $userId = $request->userId ?? null;

            $taskId = bin2hex(random_bytes(16));
            $payload = [
                'task_id' => $taskId,
                'prompt' => $prompt,
                'options' => $options,
                'user_id' => $userId,
                'tool_meta' => [
                    'image_name' => $videoName,
                    'task_type' => $taskType,
                    'conversation_id' => isset($params['conversation_id']) ? (string)$params['conversation_id'] : ''
                ],
            ];
            
            $pushed = true;
            try {
                Queue::push('app\\job\\VideoGenerateJob', $payload, 'default');
            } catch (\Throwable $qe) {
                $pushed = false;
                $failArr = [ 'status' => 'failed', 'error' => 'queue_push_failed', 'updated_at' => time() ];
                Cache::set('image_task:' . $taskId, $failArr, 3600);
                return json(['code' => 500, 'msg' => '队列推送失败', 'data' => null]);
            }

            if ($pushed) {
                $statusArr = [ 'status' => 'queued', 'updated_at' => time() ];
                $cfg = config('queue.connections.redis');
                $redis = class_exists('Redis') ? new \Redis() : null;
                if ($redis) {
                    try {
                        $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
                        if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
                        if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
                        $redis->hMSet('image_task:' . $taskId, $statusArr);
                    } catch (\Throwable $e) {
                        Cache::set('image_task:' . $taskId, $statusArr, 3600);
                    }
                } else {
                    Cache::set('image_task:' . $taskId, $statusArr, 3600);
                }
                return json(['code' => 200, 'msg' => 'Queued', 'data' => [ 'task_id' => $taskId, 'status' => 'queued' ]]);
            }
            
            return json(['code' => 200, 'msg' => 'Success', 'data' => null]);

        } catch (\Exception $e) {
            Log::error('generate_video_sora_duomi Error: ' . $e->getMessage());
            try {
                SystemErrorLog::create([
                    'tenant_id' => $request->tenantId ?? null,
                    'user_id' => $request->userId ?? null,
                    'category' => 'tool',
                    'message' => $e->getMessage(),
                    'context' => 'generate_video_sora_duomi',
                    'payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $ex) {}
            return json(['code' => 500, 'msg' => '工具调用失败: ' . $e->getMessage(), 'data' => null]);
        }
    }

    public function generate_image_seedream_v4_0(Request $request)
    {
        $params = $request->post();
        $prompt = $params['prompt'] ?? '';
        $imageList = $this->sanitizeImageList($params['image_list'] ?? []);
        $imageName = $params['image_name'] ?? '';
        $aspectRatio = $params['aspect_ratio'] ?? null;
        $sizeStr = $params['size'] ?? null;
        $width = isset($params['width']) ? (int)$params['width'] : null;
        $height = isset($params['height']) ? (int)$params['height'] : null;
        $upscaleFactor = isset($params['upscale_factor']) ? (int)$params['upscale_factor'] : 1;
        $taskType = $params['task_type'] ?? '';
        $optMode = $params['optimize_prompt_mode'] ?? 'standard';

        

        if (!$prompt || !$imageName || !$taskType) {
            try {
                SystemErrorLog::create([
                    'tenant_id' => $request->tenantId ?? null,
                    'user_id' => $request->userId ?? null,
                    'category' => 'tool',
                    'message' => 'Missing required fields',
                    'context' => 'generate_image_seedream_v4_0',
                    'payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (
                \Throwable $ex
            ) {}
            return json(['code' => 400, 'msg' => 'Missing required fields', 'data' => null]);
        }

        $options = [
            'model_identity' => 'seedream4.0',
            'response_format' => 'b64_json',
            'n' => 1,
        ];
        if ($sizeStr) { $options['size'] = $sizeStr; }
        elseif (!is_null($width) && $width > 0 && !is_null($height) && $height > 0) { $options['size'] = $width . 'x' . $height; }

        if (is_array($imageList) && !empty($imageList)) {
            $options['reference_images'] = array_values($imageList);
            $options['sequential_image_generation'] = count($imageList) > 1 ? 'auto' : 'disabled';
        }

        try {
            $userId = $request->userId ?? null;

            $taskId = bin2hex(random_bytes(16));
            $payload = [
                'task_id' => $taskId,
                'prompt' => $prompt,
                'options' => $options,
                'user_id' => $userId,
                'tool_meta' => [
                    'image_name' => $imageName,
                    'task_type' => $taskType,
                    'aspect_ratio' => $aspectRatio,
                    'resolution' => $sizeStr ?: ((!is_null($width) && $width > 0 && !is_null($height) && $height > 0) ? ($width . 'x' . $height) : null),
                    'conversation_id' => isset($params['conversation_id']) ? (string)$params['conversation_id'] : ''
                ],
            ];
            $pushed = true;
            $syncResult = null;
            try {
                Queue::push('app\\job\\ImageGenerateJob', $payload, 'default');
            } catch (\Throwable $qe) {
                $pushed = false;
                $failArr = [ 'status' => 'failed', 'error' => 'queue_push_failed', 'updated_at' => time() ];
                Cache::set('image_task:' . $taskId, $failArr, 3600);
                return json(['code' => 500, 'msg' => '队列推送失败', 'data' => null]);
            }

            if ($pushed) {
                $statusArr = [ 'status' => 'queued', 'updated_at' => time() ];
                $cfg = config('queue.connections.redis');
                $redis = class_exists('Redis') ? new \Redis() : null;
                if ($redis) {
                    try {
                        $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
                        if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
                        if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
                        $redis->hMSet('image_task:' . $taskId, $statusArr);
                    } catch (\Throwable $e) {
                        Cache::set('image_task:' . $taskId, $statusArr, 3600);
                    }
                } else {
                    Cache::set('image_task:' . $taskId, $statusArr, 3600);
                }
                return json(['code' => 200, 'msg' => 'Queued', 'data' => [ 'task_id' => $taskId, 'status' => 'queued' ]]);
            }

            return json(['code' => 200, 'msg' => 'Success', 'data' => $syncResult]);
        } catch (\Exception $e) {
            Log::error('generate_image_seedream_v4_0 Error: ' . $e->getMessage());
            try {
                SystemErrorLog::create([
                    'tenant_id' => $request->tenantId ?? null,
                    'user_id' => $request->userId ?? null,
                    'category' => 'tool',
                    'message' => $e->getMessage(),
                    'context' => 'generate_image_seedream_v4_0',
                    'payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $ex) {}
            return json(['code' => 500, 'msg' => '工具调用失败: ' . $e->getMessage(), 'data' => null]);
        }
    }

    public function generate_image_nanobananapro_duomi(Request $request)
    {
        $params = $request->post();
        $prompt = $params['prompt'] ?? '';
        $imageList = $this->sanitizeImageList($params['image_list'] ?? []);
        $imageName = $params['image_name'] ?? '';
        $aspectRatio = $params['aspect_ratio'] ?? null;
        $sizeStr = $params['size'] ?? null;
        $width = isset($params['width']) ? (int)$params['width'] : null;
        $height = isset($params['height']) ? (int)$params['height'] : null;
        $taskType = $params['task_type'] ?? '';

        if (!$prompt || !$imageName || !$taskType) {
            try {
                SystemErrorLog::create([
                    'tenant_id' => $request->tenantId ?? null,
                    'user_id' => $request->userId ?? null,
                    'category' => 'tool',
                    'message' => 'Missing required fields',
                    'context' => 'generate_image_nanobananapro_duomi',
                    'payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $ex) {}
            return json(['code' => 400, 'msg' => 'Missing required fields', 'data' => null]);
        }

        $options = [
            'model_identity' => 'duomi-nano-banana-pro',
            'n' => 1,
        ];
        if ($sizeStr) { $options['size'] = $sizeStr; }
        elseif (!is_null($width) && $width > 0 && !is_null($height) && $height > 0) { $options['size'] = $width . 'x' . $height; }
        
        if ($aspectRatio) {
            $options['aspect_ratio'] = $aspectRatio;
        }

        if (is_array($imageList) && !empty($imageList)) {
            $options['reference_images'] = array_values($imageList);
        }

        if ($taskType === 'EDIT_SINGLE_IMAGE') {
            $options['task_type'] = 'EDIT_SINGLE_IMAGE';
        }

        try {
            $userId = $request->userId ?? null;

            $taskId = bin2hex(random_bytes(16));
            $payload = [
                'task_id' => $taskId,
                'prompt' => $prompt,
                'options' => $options,
                'user_id' => $userId,
                'tool_meta' => [
                    'image_name' => $imageName,
                    'task_type' => $taskType,
                    'aspect_ratio' => $aspectRatio,
                    'resolution' => $sizeStr ?: ((!is_null($width) && $width > 0 && !is_null($height) && $height > 0) ? ($width . 'x' . $height) : null),
                    'conversation_id' => isset($params['conversation_id']) ? (string)$params['conversation_id'] : ''
                ],
            ];
            
            $pushed = true;
            try {
                Queue::push('app\\job\\ImageGenerateJob', $payload, 'default');
            } catch (\Throwable $qe) {
                $pushed = false;
                $failArr = [ 'status' => 'failed', 'error' => 'queue_push_failed', 'updated_at' => time() ];
                Cache::set('image_task:' . $taskId, $failArr, 3600);
                return json(['code' => 500, 'msg' => '队列推送失败', 'data' => null]);
            }

            if ($pushed) {
                $statusArr = [ 'status' => 'queued', 'updated_at' => time() ];
                $cfg = config('queue.connections.redis');
                $redis = class_exists('Redis') ? new \Redis() : null;
                if ($redis) {
                    try {
                        $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
                        if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
                        if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
                        $redis->hMSet('image_task:' . $taskId, $statusArr);
                    } catch (\Throwable $e) {
                        Cache::set('image_task:' . $taskId, $statusArr, 3600);
                    }
                } else {
                    Cache::set('image_task:' . $taskId, $statusArr, 3600);
                }
                return json(['code' => 200, 'msg' => 'Queued', 'data' => [ 'task_id' => $taskId, 'status' => 'queued' ]]);
            }
            
            return json(['code' => 200, 'msg' => 'Success', 'data' => null]);

        } catch (\Exception $e) {
            Log::error('generate_image_nanobananapro_duomi Error: ' . $e->getMessage());
            try {
                SystemErrorLog::create([
                    'tenant_id' => $request->tenantId ?? null,
                    'user_id' => $request->userId ?? null,
                    'category' => 'tool',
                    'message' => $e->getMessage(),
                    'context' => 'generate_image_nanobananapro_duomi',
                    'payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $ex) {}
            return json(['code' => 500, 'msg' => '工具调用失败: ' . $e->getMessage(), 'data' => null]);
        }
    }
}
