<?php
namespace app\job;

use think\queue\Job;
use app\service\LlmService;
use think\facade\Cache;

class ImageReversePromptJob
{
    public function fire(Job $job, $data)
    {
        $taskId = isset($data['task_id']) ? (string)$data['task_id'] : '';
        $imageUrl = isset($data['image_url']) ? (string)$data['image_url'] : '';
        $userId = isset($data['user_id']) ? $data['user_id'] : null;

        if (empty($taskId) || empty($imageUrl)) {
            $job->delete();
            return;
        }

        $cfg = config('queue.connections.redis');
        $redis = class_exists('Redis') ? new \Redis() : null;
        if ($redis) {
            try {
                $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
                if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
                if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
            } catch (\Throwable $e) {
                $redis = null;
            }
        }

        $taskKey = 'image_task:' . $taskId;

        // 1. Update status to processing
        $procArr = [ 'status' => 'processing', 'updated_at' => time() ];
        if ($redis) {
            try { $redis->hMSet($taskKey, $procArr); } catch (\Throwable $e) { Cache::set($taskKey, $procArr, 3600); }
        } else {
            Cache::set($taskKey, $procArr, 3600);
        }

        try {
            // 2. Call LlmService
            $service = new LlmService();
            $resultText = $service->reversePrompt($imageUrl, $userId);

            // 3. Success
            $result = ['text' => $resultText];
            $succArr = [ 'status' => 'success', 'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'updated_at' => time() ];
            
            if ($redis) {
                try { $redis->hMSet($taskKey, $succArr); } catch (\Throwable $e) { Cache::set($taskKey, $succArr, 3600); }
            } else {
                Cache::set($taskKey, $succArr, 3600);
            }

            // WebSocket Push
            if (class_exists('\app\worker\Pusher')) {
                \app\worker\Pusher::pushTaskUpdate($userId, $taskId, 'success', $result);
            }

            $job->delete();
        } catch (\Throwable $e) {
            // 4. Failed
            $failArr = [ 'status' => 'failed', 'error' => $e->getMessage(), 'updated_at' => time() ];
            if ($redis) {
                try { $redis->hMSet($taskKey, $failArr); } catch (\Throwable $e) { Cache::set($taskKey, $failArr, 3600); }
            } else {
                Cache::set($taskKey, $failArr, 3600);
            }
            
            if (class_exists('\app\worker\Pusher')) {
                \app\worker\Pusher::pushTaskUpdate($userId, $taskId, 'failed', ['error' => $e->getMessage()]);
            }

            // Don't retry if it's a logic error
            $job->delete();
        }
    }
}
