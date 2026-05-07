<?php
namespace app\job;

use think\queue\Job;
use app\service\VideoService;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use app\model\SystemErrorLog;class VideoGenerateJob
{
    public $timeout = 3600;

    public function fire(Job $job, $data)
    {
        $taskId = isset($data['task_id']) ? (string)$data['task_id'] : '';
        $prompt = isset($data['prompt']) ? (string)$data['prompt'] : '';
        $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
        $userId = isset($data['user_id']) ? $data['user_id'] : null;
        $toolMeta = isset($data['tool_meta']) && is_array($data['tool_meta']) ? $data['tool_meta'] : [];
        if (empty($toolMeta) && isset($options['tool_meta']) && is_array($options['tool_meta'])) {
            $toolMeta = $options['tool_meta'];
        }
        
        Log::info("VideoGenerateJob fired. Data options: " . json_encode($options, JSON_UNESCAPED_UNICODE));

        // Prevent retries (effectively tries=1)
        if ($job->attempts() > 1) {
            $job->delete();
            $service = new VideoService();
            $service->refundPoints($taskId);
            
            $errorMsg = 'Task retried too many times (timeout)';
            $this->handleFailure($taskId, $errorMsg, $toolMeta);
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

        $limit = (int)env('VIDEO_TASK_CONCURRENCY', 10);
        $semKey = 'video:gen:semaphore';
        $lockKey = 'video:gen:lock:' . $taskId;
        $taskKey = 'video_task:' . $taskId;
        $imageTaskKey = 'image_task:' . $taskId;

        $queuedArr = [ 'status' => 'queued', 'updated_at' => time() ];
        if ($redis) {
            try { 
                $redis->hMSet($taskKey, $queuedArr); 
                $redis->hMSet($imageTaskKey, $queuedArr);
            } catch (\Throwable $e) { 
                Cache::set($taskKey, $queuedArr, 3600); 
                Cache::set($imageTaskKey, $queuedArr, 3600);
            }
        } else {
            Cache::set($taskKey, $queuedArr, 3600);
            Cache::set($imageTaskKey, $queuedArr, 3600);
        }

        $acquired = false;
        try {
            if ($redis) {
                $cur = $redis->incr($semKey);
                if ($cur > $limit) {
                    $redis->decr($semKey);
                    $job->release(10); // Wait longer for video slots
                    return;
                }
                $redis->expire($semKey, 600);
                $redis->setex($lockKey, 600, 1);
                $acquired = true;
            }

            $procArr = [ 'status' => 'processing', 'updated_at' => time() ];
            if ($redis) {
                try { 
                    $redis->hMSet($taskKey, $procArr); 
                    $redis->hMSet($imageTaskKey, $procArr);
                } catch (\Throwable $e) { 
                    Cache::set($taskKey, $procArr, 3600); 
                    Cache::set($imageTaskKey, $procArr, 3600);
                }
            } else {
                Cache::set($taskKey, $procArr, 3600);
                Cache::set($imageTaskKey, $procArr, 3600);
            }
            
            \app\worker\Pusher::pushTaskUpdate($userId, $taskId, 'processing');

            $service = new VideoService();
            // Pass task_id in options for refund tracking
            $options['task_id'] = $taskId;
            $result = $service->generate($prompt, $options, $userId);

            if (!empty($toolMeta) && isset($toolMeta['image_name'])) {
                $result['image_name'] = $toolMeta['image_name'];
            }

            $succArr = [ 'status' => 'success', 'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'updated_at' => time() ];
            if ($redis) {
                try { 
                    $redis->hMSet($taskKey, $succArr); 
                    $redis->hMSet($imageTaskKey, $succArr);
                } catch (\Throwable $e) { 
                    Cache::set($taskKey, $succArr, 3600); 
                    Cache::set($imageTaskKey, $succArr, 3600);
                }
            } else {
                Cache::set($taskKey, $succArr, 3600);
                Cache::set($imageTaskKey, $succArr, 3600);
            }

            // WebSocket 推送
            \app\worker\Pusher::pushTaskUpdate($userId, $taskId, 'success', $result);

            if (!empty($toolMeta)) {
                $first = isset($result['videos'][0]) ? $result['videos'][0] : null;
                $videoUrl = $first && isset($first['url']) ? $first['url'] : null;
                $conversationUid = isset($toolMeta['conversation_id']) ? (string)$toolMeta['conversation_id'] : '';
                
                if ($conversationUid !== '' && $videoUrl) {
                    try {
                        $row = Db::table('conversations')->where('conversation_id', $conversationUid)->find();
                        if ($row) {
                            $msgs = [];
                            try { $msgs = isset($row['messages_json']) && $row['messages_json'] ? json_decode($row['messages_json'], true) ?: [] : []; } catch (\Throwable $e) { $msgs = []; }
                            
                            $videoName = isset($toolMeta['image_name']) ? $toolMeta['image_name'] : '';
                            $subject = $videoName ?: (is_string($prompt) && trim($prompt) !== '' ? trim($prompt) : '视频');
                            $finishLine = "已为您生成视频：{$subject}。";
                            
                            $ts = time();
                            // Append new message with result
                            $msgs[] = [
                                'id' => $ts,
                                'role' => 'assistant',
                                'content' => $finishLine,
                                'timestamp' => $ts,
                                'toolResult' => [
                                    'video_url' => $videoUrl,
                                    'image_name' => $videoName,
                                    'task_type' => $toolMeta['task_type'] ?? 'TEXT_TO_VIDEO',
                                    'status' => 'success'
                                ],
                                'finishLine' => '' // Removed text below card as per user request
                            ];

                            Db::table('conversations')->where('conversation_id', $conversationUid)->update([
                                'messages_json' => json_encode($msgs, JSON_UNESCAPED_UNICODE),
                                'cover_url' => $videoUrl,
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);
                            Log::info("VideoGenerateJob: Saved message to conversation {$conversationUid} with video {$videoUrl}");
                        } else {
                            Log::warning("VideoGenerateJob: Conversation {$conversationUid} not found");
                        }
                    } catch (\Throwable $e) {
                        Log::error("VideoGenerateJob: Save failed - " . $e->getMessage());
                    }
                } else {
                    Log::warning("VideoGenerateJob: Missing convUid or videoUrl. meta=" . json_encode($toolMeta));
                }
            }

            if ($redis && $acquired) {
                $redis->decr($semKey);
                $redis->del($lockKey);
            }
            $job->delete();

        } catch (\Throwable $e) {
            // Refund points if needed
            if ($taskId) {
                $service = new VideoService();
                $service->refundPoints($taskId);
            }

            $failArr = [ 'status' => 'failed', 'error' => $e->getMessage(), 'updated_at' => time() ];
            if ($redis) {
                try { 
                    $redis->hMSet($taskKey, $failArr); 
                    $redis->hMSet($imageTaskKey, $failArr);
                } catch (\Throwable $e2) { 
                    Cache::set($taskKey, $failArr, 3600); 
                    Cache::set($imageTaskKey, $failArr, 3600);
                }
                if ($acquired) {
                    $redis->decr($semKey);
                    $redis->del($lockKey);
                }
            } else {
                Cache::set($taskKey, $failArr, 3600);
                Cache::set($imageTaskKey, $failArr, 3600);
            }

            // WebSocket 推送
            \app\worker\Pusher::pushTaskUpdate($userId, $taskId, 'failed', $e->getMessage());

            // Update conversation with error message to ensure UI consistency
            $conversationUid = isset($toolMeta['conversation_id']) ? (string)$toolMeta['conversation_id'] : '';
            if ($conversationUid !== '') {
                try {
                    $row = Db::table('conversations')->where('conversation_id', $conversationUid)->find();
                    if ($row) {
                        $msgs = [];
                        try { $msgs = isset($row['messages_json']) && $row['messages_json'] ? json_decode($row['messages_json'], true) ?: [] : []; } catch (\Throwable $decodeErr) { $msgs = []; }
                        
                        $ts = time();
                        $errorMsg = "视频生成失败：" . $e->getMessage();
                        $msgs[] = [
                            'id' => $ts,
                            'role' => 'assistant',
                            'content' => $errorMsg,
                            'timestamp' => $ts,
                            'toolResult' => [
                                'status' => 'failed',
                                'error' => $e->getMessage(),
                                'task_type' => $toolMeta['task_type'] ?? 'TEXT_TO_VIDEO'
                            ]
                        ];

                        Db::table('conversations')->where('conversation_id', $conversationUid)->update([
                            'messages_json' => json_encode($msgs, JSON_UNESCAPED_UNICODE),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                        Log::info("VideoGenerateJob: Updated conversation {$conversationUid} with failure message.");
                    }
                } catch (\Throwable $ex) {
                    Log::error("VideoGenerateJob: Failed to update conversation: " . $ex->getMessage());
                }
            }
        }
    }

    public function failed($data)
    {
        $taskId = isset($data['task_id']) ? (string)$data['task_id'] : '';
        $toolMeta = isset($data['tool_meta']) && is_array($data['tool_meta']) ? $data['tool_meta'] : [];
        
        if ($taskId) {
            $service = new VideoService();
            $service->refundPoints($taskId);
            
            $this->handleFailure($taskId, 'Task failed (system error or timeout)', $toolMeta);
        }
    }

    protected function handleFailure($taskId, $errorMsg, $toolMeta)
    {
        // Update Redis/Cache status
        $failArr = [ 'status' => 'failed', 'error' => $errorMsg, 'updated_at' => time() ];
        $taskKey = 'video_task:' . $taskId;
        $imageTaskKey = 'image_task:' . $taskId;

        try {
            $cfg = config('queue.connections.redis');
            $redis = class_exists('Redis') ? new \Redis() : null;
            if ($redis) {
                $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
                if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
                if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
                $redis->hMSet($taskKey, $failArr);
                $redis->hMSet($imageTaskKey, $failArr);
            } else {
                throw new \Exception("No Redis");
            }
        } catch (\Throwable $e) {
            Cache::set($taskKey, $failArr, 3600);
            Cache::set($imageTaskKey, $failArr, 3600);
        }

        // Update Conversation
        $conversationUid = isset($toolMeta['conversation_id']) ? (string)$toolMeta['conversation_id'] : '';
        if ($conversationUid !== '') {
            try {
                $row = Db::table('conversations')->where('conversation_id', $conversationUid)->find();
                if ($row) {
                    $msgs = [];
                    try { $msgs = isset($row['messages_json']) && $row['messages_json'] ? json_decode($row['messages_json'], true) ?: [] : []; } catch (\Throwable $decodeErr) { $msgs = []; }
                    
                    $ts = time();
                    $displayError = "视频生成失败：" . $errorMsg;
                    $msgs[] = [
                        'id' => $ts,
                        'role' => 'assistant',
                        'content' => $displayError,
                        'timestamp' => $ts,
                        'toolResult' => [
                            'status' => 'failed',
                            'error' => $errorMsg,
                            'task_type' => $toolMeta['task_type'] ?? 'TEXT_TO_VIDEO'
                        ]
                    ];

                    Db::table('conversations')->where('conversation_id', $conversationUid)->update([
                        'messages_json' => json_encode($msgs, JSON_UNESCAPED_UNICODE),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error("VideoGenerateJob: Failed to update conversation on failure: " . $e->getMessage());
            }
        }
    }
}
