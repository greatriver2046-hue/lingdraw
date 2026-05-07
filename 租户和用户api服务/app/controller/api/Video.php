<?php
namespace app\controller\api;

use app\BaseController;
use app\service\VideoService;
use think\Request;
use think\facade\Log;
use think\facade\Queue;
use think\facade\Cache;

class Video extends BaseController
{
    protected $videoService;

    public function __construct(VideoService $videoService)
    {
        $this->videoService = $videoService;
    }

    public function generate(Request $request)
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
        // Fallback to param() which merges get/post/json
        if (empty($params)) {
            $params = $request->param();
        }
        $prompt = $params['prompt'] ?? '';
        $options = $params['options'] ?? [];
        if (is_string($options)) {
            try { $options = json_decode($options, true) ?: []; } catch (\Throwable $e) { $options = []; }
        }

        if (!$prompt || !is_string($prompt)) {
            return json(['code' => 400, 'msg' => 'Prompt is required', 'data' => null]);
        }

        try {
            $userId = $request->userId ?? null;
            $tenantId = $request->tenantId ?? null;

            $taskId = bin2hex(random_bytes(16));
            $payload = [
                'task_id' => $taskId,
                'prompt' => $prompt,
                'options' => array_merge($options, [
                    'model_identity' => $params['model_identity'] ?? ($options['model_identity'] ?? null),
                ]),
                'user_id' => $userId,
                'tenant_id' => $tenantId,
            ];

            $pushed = true;
            try {
                Queue::push('app\\job\\VideoGenerateJob', $payload, 'default');
            } catch (\Throwable $qe) {
                $pushed = false;
                $failArr = [ 'status' => 'failed', 'error' => 'queue_push_failed', 'updated_at' => time() ];
                Cache::set('video_task:' . $taskId, $failArr, 3600);
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
                        $redis->hMSet('video_task:' . $taskId, $statusArr);
                    } catch (\Throwable $e) {
                        Cache::set('video_task:' . $taskId, $statusArr, 3600);
                    }
                } else {
                    Cache::set('video_task:' . $taskId, $statusArr, 3600);
                }
                return json(['code' => 200, 'msg' => 'Queued', 'data' => [ 'task_id' => $taskId, 'status' => 'queued' ]]);
            }

            return json(['code' => 500, 'msg' => 'Unknown Error', 'data' => null]);
        } catch (\Exception $e) {
            Log::error('Video Generate Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function task($id)
    {
        try {
            $cfg = config('queue.connections.redis');
            $redis = class_exists('Redis') ? new \Redis() : null;
            $taskKey = 'video_task:' . $id;
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
            
            // Decode result if it exists
            if (isset($info['result']) && is_string($info['result'])) {
                $decoded = json_decode($info['result'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $info['result'] = $decoded;
                }
            }

            return json(['code' => 200, 'msg' => 'Success', 'data' => $info]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }
}
