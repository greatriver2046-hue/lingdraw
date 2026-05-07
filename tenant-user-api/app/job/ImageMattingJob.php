<?php
namespace app\job;

use think\queue\Job;
use app\service\ImageService;
use think\facade\Cache;
use think\facade\Log;

class ImageMattingJob
{
    public function fire(Job $job, $data)
    {
        $taskId = isset($data['task_id']) ? (string)$data['task_id'] : '';
        $imageUrl = isset($data['image_url']) ? (string)$data['image_url'] : '';
        $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
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

        // Set status to queued initially if not already set
        $queuedArr = [ 'status' => 'queued', 'updated_at' => time() ];
        if ($redis) {
            try { $redis->hMSet($taskKey, $queuedArr); } catch (\Throwable $e) { Cache::set($taskKey, $queuedArr, 3600); }
        } else {
            Cache::set($taskKey, $queuedArr, 3600);
        }

        try {
            // Set status to processing
            $procArr = [ 'status' => 'processing', 'updated_at' => time() ];
            if ($redis) {
                try { $redis->hMSet($taskKey, $procArr); } catch (\Throwable $e) { Cache::set($taskKey, $procArr, 3600); }
            } else {
                Cache::set($taskKey, $procArr, 3600);
            }

            $service = new ImageService();
            // Perform the matting operation
            $result = $service->matting($imageUrl, $options, $userId);

            // Set status to success
            $succArr = [ 
                'status' => 'success', 
                'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 
                'updated_at' => time() 
            ];
            if ($redis) {
                try { $redis->hMSet($taskKey, $succArr); } catch (\Throwable $e) { Cache::set($taskKey, $succArr, 3600); }
            } else {
                Cache::set($taskKey, $succArr, 3600);
            }

            // WebSocket 推送
            \app\worker\Pusher::pushTaskUpdate($userId, $taskId, 'success', $result);

            $job->delete();
        } catch (\Throwable $e) {
            Log::error("ImageMattingJob Error [{$taskId}]: " . $e->getMessage());
            
            $failArr = [ 
                'status' => 'failed', 
                'error' => mb_substr($e->getMessage(), 0, 500), 
                'updated_at' => time() 
            ];
            if ($redis) {
                try { $redis->hMSet($taskKey, $failArr); } catch (\Throwable $e2) { Cache::set($taskKey, $failArr, 3600); }
            } else {
                Cache::set($taskKey, $failArr, 3600);
            }

            // WebSocket 推送
            \app\worker\Pusher::pushTaskUpdate($userId, $taskId, 'failed', $e->getMessage());

            $job->delete();
        }
    }
}
