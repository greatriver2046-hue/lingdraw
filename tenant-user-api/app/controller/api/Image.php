<?php
namespace app\controller\api;

use app\BaseController;
use app\service\ImageService;
use think\facade\Filesystem;
use app\model\SystemErrorLog;
use app\model\ImageGeneration;
use think\Request;
use think\facade\Log;
use think\facade\Queue;
use think\facade\Cache;
use think\facade\Db;

class Image extends BaseController
{
    protected $imageService;
    protected $llmService;

    public function __construct(ImageService $imageService, \app\service\LlmService $llmService)
    {
        $this->imageService = $imageService;
        $this->llmService = $llmService;
    }

    public function recognizeMarkers(Request $request)
    {
        $params = $request->post();
        if (empty($params)) {
            try {
                $raw = $request->getContent();
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $params = $json;
                }
            } catch (\Throwable $e) {}
        }
        if (empty($params)) {
            $params = $request->param();
        }

        $image = isset($params['image']) ? trim((string)$params['image']) : '';
        if ($image === '') {
            $image = isset($params['image_url']) ? trim((string)$params['image_url']) : '';
        }
        
        if ($image === '') {
            return json(['code' => 400, 'msg' => '图片数据不能为空', 'data' => null]);
        }

        try {
            $userId = $request->userId ?? null;
            if (!preg_match('#^data:image/[^;]+;base64,#i', $image) && !preg_match('#^https?://#i', $image)) {
                $maybeBase64 = preg_replace('/\s+/', '', $image);
                if ($maybeBase64 !== '' && strlen($maybeBase64) > 200 && preg_match('#^[A-Za-z0-9+/=]+$#', $maybeBase64)) {
                    $image = 'data:image/jpeg;base64,' . $maybeBase64;
                }
            }
            $result = $this->llmService->recognizeMarkers($image, $userId);

            if (is_string($result) && $result !== '') {
                $result = str_replace(["\r\n", "\r"], "\n", $result);
                $result = preg_replace('/^\s*```[\w-]*\s*$/m', '', $result);
                $result = trim($result);
            }
            
            $markers = $this->parseMarkersResult(is_string($result) ? $result : '');
            return json(['code' => 200, 'msg' => 'Success', 'data' => ['result' => $result, 'markers' => $markers]]);
        } catch (\Exception $e) {
            Log::error('Marker Recognition Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    protected function parseMarkersResult(string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') return [];

        $markers = [];
        $lines = preg_split("/\n+/", $text);
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;

            $num = null;
            $content = '';

            if (preg_match('/^\[?\s*标记点\s*(\d+)\s*\]?\s*[:：]\s*(.*)$/u', $line, $m)) {
                $num = (int)$m[1];
                $content = (string)$m[2];
            } elseif (preg_match('/^\s*标记点\s*(\d+)\s+(.*)$/u', $line, $m)) {
                $num = (int)$m[1];
                $content = (string)$m[2];
            } elseif (preg_match('/^\s*(\d+)\s*[\.、:：]\s*(.*)$/u', $line, $m)) {
                $num = (int)$m[1];
                $content = (string)$m[2];
            }

            if ($num !== null && $num > 0) {
                $content = trim($content);
                if (!isset($markers[$num]) || $markers[$num] === '') {
                    $markers[$num] = $content;
                }
            }
        }

        if (empty($markers)) return [];

        ksort($markers);
        $out = [];
        foreach ($markers as $num => $content) {
            $out[] = ['number' => (int)$num, 'content' => (string)$content];
        }
        return $out;
    }

    public function generate(Request $request)
    {
        $params = $request->post();
        $prompt = $params['prompt'] ?? '';
        $options = $params['options'] ?? [];

        // Handle OCR text modification prompt splicing on backend
        $isTextEdit = false;
        if (isset($params['ocr_modifications']) && is_array($params['ocr_modifications'])) {
            $parts = [];
            foreach ($params['ocr_modifications'] as $mod) {
                if (isset($mod['old']) && isset($mod['new']) && trim($mod['new']) !== '' && $mod['old'] !== $mod['new']) {
                    $parts[] = "将图中的文字“" . $mod['old'] . "”改为“" . $mod['new'] . "”";
                }
            }
            if (!empty($parts)) {
                $prompt = implode('，', $parts) . "，除了画质优化之外不修改其他内容。";
                $isTextEdit = true;
            }
        }

        $flag = $options['is_pose_edit'] ?? false;
        $isPoseEdit = false;
        if (is_bool($flag)) $isPoseEdit = $flag;
        elseif (is_numeric($flag)) $isPoseEdit = ((int)$flag) === 1;
        elseif (is_string($flag)) $isPoseEdit = in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'y'], true);

        if (!$prompt || !is_string($prompt)) {
            if ($isPoseEdit) {
                $prompt = '';
            } else {
                return json(['code' => 400, 'msg' => 'Prompt is required', 'data' => null]);
            }
        }

        try {
            $userId = $request->userId ?? null;

            $taskId = bin2hex(random_bytes(16));
            $modelIdentity = $params['model_identity'] ?? ($options['model_identity'] ?? null);

            // If it is text edit and no model provided, try to use the default text_edit_model
            if ($isTextEdit && empty($modelIdentity)) {
                try {
                    $defaultModels = Db::table('system_configs')->where('category', 'default_models')->value('config');
                    if ($defaultModels) {
                        $dmConfig = json_decode($defaultModels, true);
                        if (!empty($dmConfig['text_edit_model'])) {
                            $modelIdentity = $dmConfig['text_edit_model'];
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to fetch default text_edit_model: ' . $e->getMessage());
                }
                
                if (empty($modelIdentity)) {
                    return json(['code' => 400, 'msg' => '未配置默认文字修改模型，请联系管理员配置', 'data' => null]);
                }
            }

            $payload = [
                'task_id' => $taskId,
                'prompt' => $prompt,
                'options' => array_merge($options, [
                    'model_identity' => $modelIdentity,
                ]),
                'user_id' => $userId,
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
            Log::error('Image Generate Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function matting(Request $request)
    {
        $params = $request->post();
        $imageUrl = $params['image_url'] ?? '';
        $options = $params['options'] ?? [];

        if (!$imageUrl) {
            return json(['code' => 400, 'msg' => 'Image URL is required', 'data' => null]);
        }

        try {
            $userId = $request->userId ?? null;

            // Handle default model for matting
            $modelIdentity = $params['model_identity'] ?? ($options['model_identity'] ?? null);

            // Fetch default configuration
            $defaultIdentity = null;
            try {
                $defaultModels = Db::table('system_configs')->where('category', 'default_models')->value('config');
                if ($defaultModels) {
                    $dmConfig = json_decode($defaultModels, true);
                    if (!empty($dmConfig['remove_bg_model'])) {
                        $defaultIdentity = $dmConfig['remove_bg_model'];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to fetch default remove_bg_model: ' . $e->getMessage());
            }

            // Override if empty or if it matches legacy hardcoded 'imageseg' and we have a default
            if (empty($modelIdentity) || ($modelIdentity === 'imageseg' && $defaultIdentity)) {
                if ($defaultIdentity) {
                    $modelIdentity = $defaultIdentity;
                }
            }

            if (empty($modelIdentity)) {
                return json(['code' => 400, 'msg' => '未配置默认背景去除模型，请联系管理员配置', 'data' => null]);
            }
            
            $options['model_identity'] = $modelIdentity;

            // 1. Check if HD matting is needed
            $isHd = false;
            try {
                $imageInfo = @getimagesize($imageUrl);
                if ($imageInfo) {
                    $width = $imageInfo[0];
                    $height = $imageInfo[1];
                    if ($width > 2000 || $height > 2000) {
                        $isHd = true;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to get image size in controller: " . $e->getMessage());
            }

            // 2. If HD, use queue to avoid timeout
            if ($isHd) {
                $taskId = bin2hex(random_bytes(16));
                $payload = [
                    'task_id' => $taskId,
                    'image_url' => $imageUrl,
                    'options' => $options,
                    'user_id' => $userId,
                ];

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

                Queue::push('app\\job\\ImageMattingJob', $payload, 'default');
                
                return json(['code' => 200, 'msg' => 'Queued', 'data' => [ 'task_id' => $taskId, 'status' => 'queued' ]]);
            }

            // 3. For normal images, keep synchronous for better UX
            $result = $this->imageService->matting($imageUrl, $options, $userId);
            
            // Flatten response for frontend compatibility
            if (isset($result['images'][0]['url'])) {
                $result['image_url'] = $result['images'][0]['url'];
            }
            
            return json(['code' => 200, 'msg' => 'Success', 'data' => $result]);
        } catch (\Exception $e) {
            Log::error('Image Matting Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function reversePrompt(Request $request)
    {
        $params = $request->post();
        $imageUrl = $params['image_url'] ?? '';
        $options = $params['options'] ?? [];

        if (!$imageUrl) {
            return json(['code' => 400, 'msg' => 'Image URL is required', 'data' => null]);
        }

        try {
            $userId = $request->userId ?? null;

            $taskId = bin2hex(random_bytes(16));
            $payload = [
                'task_id' => $taskId,
                'image_url' => $imageUrl,
                'options' => $options,
                'user_id' => $userId,
            ];

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

            Queue::push('app\\job\\ImageReversePromptJob', $payload, 'default');
            
            return json(['code' => 200, 'msg' => 'Queued', 'data' => [ 'task_id' => $taskId, 'status' => 'queued' ]]);
        } catch (\Exception $e) {
            Log::error('Image Reverse Prompt Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function task($id)
    {
        try {
            $cfg = config('queue.connections.redis');
            $redis = class_exists('Redis') ? new \Redis() : null;
            $taskKey = 'image_task:' . $id;
            $info = [];
            if ($redis) {
                try {
                    $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
                    if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
                    if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
                    if ($redis->exists($taskKey)) {
                        $info = $redis->hGetAll($taskKey) ?: [];
                    }
                } catch (\Throwable $e) {
                    $info = Cache::get($taskKey) ?: [];
                }
            } else {
                $info = Cache::get($taskKey) ?: [];
            }
            if (empty($info)) {
                return json(['code' => 404, 'msg' => 'Task not found', 'data' => null]);
            }
            $result = [];
            if (!empty($info['result'])) {
                try { $result = json_decode($info['result'], true) ?: []; } catch (\Throwable $e) { $result = []; }
            }
            return json(['code' => 200, 'msg' => 'Success', 'data' => [
                'task_id' => $id,
                'status' => $info['status'] ?? 'unknown',
                'result' => $result ?: null,
                'error' => $info['error'] ?? null,
            ]]);
        } catch (\Exception $e) {
            Log::error('Image Task Query Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function history(Request $request)
    {
        try {
            $tenantId = $request->tenantId ?? null;
            $userId = $request->userId ?? null;
            $page = (int)($request->get('page') ?? 1);
            $pageSize = (int)($request->get('page_size') ?? 20);

            $query = ImageGeneration::order('created_at', 'desc');
            if ($tenantId) {
                $query = $query->where('tenant_id', $tenantId);
            }
            if ($userId) {
                $query = $query->where('user_id', $userId);
            }

            $total = $query->count();
            $list = $query->page($page, $pageSize)->select();

            return json([
                'code' => 200,
                'msg' => 'Success',
                'data' => [
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'items' => $list,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Image History Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function upload(Request $request)
    {
        try {
            $tenantId = $request->tenantId ?? null;
            $userId = $request->userId ?? null;
            $files = $request->file();
            
            // Debugging upload issues
            $contentLength = $request->header('content-length');
            Log::info("Upload request: Content-Length={$contentLength}, Files count=" . count($files));
            if (empty($files) && $contentLength > 0) {
                 Log::warning("Upload failed: Content-Length is {$contentLength} but no files found. Possible post_max_size exceeded.");
                 $maxPost = ini_get('post_max_size');
                 $maxUpload = ini_get('upload_max_filesize');
                 return json(['code' => 400, 'msg' => "Upload failed. Request size ({$contentLength}) might exceed server limits (post_max_size: {$maxPost}, upload_max_filesize: {$maxUpload})", 'data' => null]);
            }

            $urls = [];
            if (!$files) {
                return json(['code' => 400, 'msg' => 'No files uploaded', 'data' => null]);
            }

            // Use ImageService for OSS-first storage
            $service = app(ImageService::class);

            $handleOne = function($f) use (&$urls, $service, $tenantId, $userId) {
                $ext = 'png';
                if (method_exists($f, 'getOriginalName')) {
                    $name = $f->getOriginalName();
                    $ext = pathinfo($name, PATHINFO_EXTENSION) ?: $ext;
                } elseif (method_exists($f, 'getFilename')) {
                    $name = $f->getFilename();
                    $ext = pathinfo($name, PATHINFO_EXTENSION) ?: $ext;
                }
                $path = null;
                if (method_exists($f, 'getRealPath')) {
                    $path = $f->getRealPath();
                } elseif (method_exists($f, 'getPathname')) {
                    $path = $f->getPathname();
                }
                
                // Log file info for debugging
                Log::info("Handling file upload: name={$name}, path={$path}, readable=" . (is_readable($path) ? 'yes' : 'no') . ", size=" . ($path ? filesize($path) : 'N/A'));

                if ($path && is_readable($path)) {
                    $binary = file_get_contents($path);
                    if (strlen($binary) === 0) {
                        Log::error("File binary is empty for {$path}");
                    }
                    $url = $service->storeBinary($binary, $ext, 'refs');
                    if ($url) {
                        $urls[] = $url;
                        try {
                            Db::table('image_assets')->insert([
                                'tenant_id' => $tenantId,
                                'user_id' => $userId,
                                'category' => 'image',
                                'type' => 'user_upload',
                                'url' => $url,
                                'created_at' => date('Y-m-d H:i:s'),
                            ]);
                        } catch (\Throwable $e) {}
                    } else {
                         Log::error("storeBinary returned null for {$path}");
                    }
                } else {
                    // Fallback: store via public disk
                    Log::info("Fallback to public disk for {$name}");
                    try {
                        $p = Filesystem::disk('public')->putFile('refs', $f);
                        if ($p) {
                            $url = rtrim(request()->domain(), '/') . config('filesystem.disks.public.url') . '/' . str_replace('\\', '/', $p);
                            $urls[] = $url;
                            try {
                                Db::table('image_assets')->insert([
                                    'tenant_id' => $tenantId,
                                    'user_id' => $userId,
                                    'category' => 'image',
                                    'type' => 'user_upload',
                                    'url' => $url,
                                    'created_at' => date('Y-m-d H:i:s'),
                                ]);
                            } catch (\Throwable $e) {}
                        } else {
                            Log::error("Filesystem putFile failed for {$name}");
                        }
                    } catch (\Throwable $e) {
                        Log::error("Fallback upload error: " . $e->getMessage());
                    }
                }
            };

            foreach ($files as $key => $file) {
                if (is_array($file)) {
                    foreach ($file as $f) {
                        $handleOne($f);
                    }
                } else {
                    $handleOne($file);
                }
            }

            if (empty($urls)) {
                // If files were provided but no URLs generated, it's a failure.
                // Log additional context for debugging
                Log::error("Upload failed: No valid files processed. Files array: " . json_encode(array_keys($files)));
                return json(['code' => 400, 'msg' => 'Upload failed: No valid files processed or file size limit exceeded.', 'data' => null]);
            }
            return json(['code' => 200, 'msg' => 'Success', 'data' => ['urls' => $urls]]);
        } catch (\Exception $e) {
            Log::error('Image Upload Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }
}
