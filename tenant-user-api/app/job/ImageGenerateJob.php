<?php
namespace app\job;

use think\queue\Job;
use app\service\ImageService;
use app\service\LlmService;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

class ImageGenerateJob
{
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

        $limit = (int)env('IMAGE_TASK_CONCURRENCY', 50);
        $semKey = 'image:gen:semaphore';
        $lockKey = 'image:gen:lock:' . $taskId;
        $taskKey = 'image_task:' . $taskId;

        $queuedArr = [ 'status' => 'queued', 'updated_at' => time() ];
        if ($redis) {
            try { $redis->hMSet($taskKey, $queuedArr); } catch (\Throwable $e) { Cache::set($taskKey, $queuedArr, 3600); }
        } else {
            Cache::set($taskKey, $queuedArr, 3600);
        }

        $acquired = false;
        try {
            if ($redis) {
                $cur = $redis->incr($semKey);
                if ($cur > $limit) {
                    $redis->decr($semKey);
                    $job->release(2);
                    return;
                }
                $redis->expire($semKey, 600);
                $redis->setex($lockKey, 600, 1);
                $acquired = true;
            }

            $procArr = [ 'status' => 'processing', 'updated_at' => time() ];
            if ($redis) {
                try { $redis->hMSet($taskKey, $procArr); } catch (\Throwable $e) { Cache::set($taskKey, $procArr, 3600); }
            } else {
                Cache::set($taskKey, $procArr, 3600);
            }
            
            \app\worker\Pusher::pushTaskUpdate($userId, $taskId, 'processing');

            $service = new ImageService();
            // Inject task_id into options for tracking
            $options['task_id'] = $taskId;
            $result = $service->generate($prompt, $options, $userId);

            if (!empty($toolMeta) && isset($toolMeta['image_name'])) {
                $result['image_name'] = $toolMeta['image_name'];
            }

            $modelIdentityUsed = '';
            if (isset($options['model_identity']) && is_string($options['model_identity'])) {
                $modelIdentityUsed = trim($options['model_identity']);
            } elseif (isset($options['model']) && is_string($options['model'])) {
                $modelIdentityUsed = trim($options['model']);
            } elseif (!empty($toolMeta) && isset($toolMeta['model_identity']) && is_string($toolMeta['model_identity'])) {
                $modelIdentityUsed = trim($toolMeta['model_identity']);
            }
            if ($modelIdentityUsed !== '') {
                $result['model_identity'] = $modelIdentityUsed;
            }

            $modelNameUsed = '';
            if ($modelIdentityUsed !== '') {
                try {
                    $name = Db::table('model_configs')->where('model_identity', $modelIdentityUsed)->value('name');
                    if (is_string($name) && trim($name) !== '') {
                        $modelNameUsed = trim($name);
                    }
                } catch (\Throwable $e) {
                }
                if ($modelNameUsed === '') {
                    try {
                        $name = Db::table('model_configs')->where('model_id', $modelIdentityUsed)->value('name');
                        if (is_string($name) && trim($name) !== '') {
                            $modelNameUsed = trim($name);
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
            if ($modelNameUsed !== '') {
                $result['model_name'] = $modelNameUsed;
            }

            $imageUrls = [];
            if (isset($result['images']) && is_array($result['images'])) {
                foreach ($result['images'] as $img) {
                    if (is_array($img) && isset($img['url']) && is_string($img['url']) && trim($img['url']) !== '') {
                        $imageUrls[] = trim($img['url']);
                    }
                }
            }
            $imageUrls = array_values(array_unique($imageUrls));
            $firstUrl = isset($imageUrls[0]) ? $imageUrls[0] : null;

            $organizedReply = '';
            if (!empty($toolMeta) && isset($toolMeta['conversation_id']) && is_string($toolMeta['conversation_id']) && trim($toolMeta['conversation_id']) !== '' && $firstUrl) {
                $organizedReply = $this->summarizeSuccessForUser($prompt, $result, $toolMeta, $imageUrls);
                if ($organizedReply !== '') {
                    $result['organized_reply'] = $organizedReply;
                }
            }

            $succArr = [ 'status' => 'success', 'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'updated_at' => time() ];
            if ($redis) {
                try { $redis->hMSet($taskKey, $succArr); } catch (\Throwable $e) { Cache::set($taskKey, $succArr, 3600); }
            } else {
                Cache::set($taskKey, $succArr, 3600);
            }

            if (!empty($toolMeta)) {
                $resLabel = isset($toolMeta['resolution']) ? (string)$toolMeta['resolution'] : '';
                if ($firstUrl) {
                    $dim2 = null;
                    try {
                        $info2 = @getimagesize($firstUrl);
                        if (is_array($info2) && isset($info2[0]) && isset($info2[1]) && $info2[0] > 0 && $info2[1] > 0) {
                            $dim2 = [ 'w' => (int)$info2[0], 'h' => (int)$info2[1] ];
                        }
                    } catch (\Throwable $e) {}

                    if ($dim2 && $dim2['w'] > 0 && $dim2['h'] > 0) {
                        $resLabel = $dim2['w'] . 'x' . $dim2['h'];
                    }
                }

                $payloadBase = [
                    'image_name' => $toolMeta['image_name'] ?? '',
                    'task_type' => $toolMeta['task_type'] ?? '',
                    'resolution' => $resLabel,
                ];
                if ($modelIdentityUsed !== '') {
                    $payloadBase['model_identity'] = $modelIdentityUsed;
                }
                if ($modelNameUsed !== '') {
                    $payloadBase['model_name'] = $modelNameUsed;
                }
                $conversationUid = isset($toolMeta['conversation_id']) ? (string)$toolMeta['conversation_id'] : '';
                if ($conversationUid !== '' && $firstUrl) {
                    try {
                        $row = \think\facade\Db::table('conversations')->where('conversation_id', $conversationUid)->find();
                        if ($row) {
                            $msgs = [];
                            try { $msgs = isset($row['messages_json']) && $row['messages_json'] ? json_decode($row['messages_json'], true) ?: [] : []; } catch (\Throwable $e) { $msgs = []; }
                            $subject = (is_string($payloadBase['image_name']) && trim($payloadBase['image_name']) !== '') ? trim($payloadBase['image_name']) : (is_string($prompt) && trim($prompt) !== '' ? trim($prompt) : '图片');
                            $total = max(1, count($imageUrls));
                            $batchTotal = isset($toolMeta['batch_total']) ? (int)$toolMeta['batch_total'] : 0;
                            $batchIndex = isset($toolMeta['batch_index']) ? (int)$toolMeta['batch_index'] : 0;
                            $baseTs = (int)(microtime(true) * 1000);
                            for ($i = 0; $i < $total; $i++) {
                                $url = isset($imageUrls[$i]) ? $imageUrls[$i] : $firstUrl;
                                $finishLine = '';
                                if ($total > 1) {
                                    $finishLine = "已为您生成{$subject}的图片（" . ($i + 1) . "/{$total}）。";
                                } elseif ($batchTotal > 1 && $batchIndex > 0) {
                                    $finishLine = "已为您生成{$subject}的图片（{$batchIndex}/{$batchTotal}）。";
                                } else {
                                    $finishLine = $subject ? "已为您生成{$subject}的图片。" : '已为您生成图片。';
                                }
                                $msgs[] = [
                                    'id' => $baseTs + $i,
                                    'role' => 'assistant',
                                    'content' => $finishLine,
                                    'timestamp' => time(),
                                    'toolResult' => array_merge($payloadBase, ['image_url' => $url]),
                                    'finishLine' => $finishLine
                                ];
                            }
                            if ($organizedReply !== '') {
                                $msgs[] = [
                                    'id' => $baseTs + $total + 1,
                                    'role' => 'assistant',
                                    'content' => $organizedReply,
                                    'timestamp' => time(),
                                ];
                            }
                            $coverUrl = $firstUrl;
                            $coverThumb = isset($row['cover_thumb_url']) && $row['cover_thumb_url'] ? $row['cover_thumb_url'] : null;

                            \think\facade\Db::table('conversations')->where('conversation_id', $conversationUid)->update([
                                'messages_json' => json_encode($msgs, JSON_UNESCAPED_UNICODE),
                                'cover_url' => $coverUrl,
                                'cover_thumb_url' => $coverThumb ?: null,
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    } catch (\Throwable $e) { }
                }
            }

            // WebSocket 推送
            \app\worker\Pusher::pushTaskUpdate($userId, $taskId, 'success', $result);

            $job->delete();
        } catch (\Throwable $e) {
            // Refund points if needed
            if ($taskId) {
                try {
                    $service = new ImageService();
                    $service->refundPoints($taskId);
                } catch (\Throwable $re) {}
            }

            $rawError = (string)$e->getMessage();
            $finalError = $this->summarizeFailureForUser($rawError, $toolMeta, $prompt);

            $failArr = [
                'status' => 'failed',
                'error' => mb_substr($finalError, 0, 500),
                'raw_error' => mb_substr($rawError, 0, 500),
                'updated_at' => time()
            ];
            if ($redis) {
                try { $redis->hMSet($taskKey, $failArr); } catch (\Throwable $e2) { Cache::set($taskKey, $failArr, 3600); }
            } else {
                Cache::set($taskKey, $failArr, 3600);
            }

            $conversationUid = isset($toolMeta['conversation_id']) ? (string)$toolMeta['conversation_id'] : '';
            if ($conversationUid !== '') {
                try {
                    $row = Db::table('conversations')->where('conversation_id', $conversationUid)->find();
                    if ($row) {
                        $msgs = [];
                        try { $msgs = isset($row['messages_json']) && $row['messages_json'] ? json_decode($row['messages_json'], true) ?: [] : []; } catch (\Throwable $e3) { $msgs = []; }
                        $msgs[] = [
                            'id' => (int)(microtime(true) * 1000),
                            'role' => 'assistant',
                            'content' => $finalError,
                            'timestamp' => time(),
                        ];
                        Db::table('conversations')->where('conversation_id', $conversationUid)->update([
                            'messages_json' => json_encode($msgs, JSON_UNESCAPED_UNICODE),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                } catch (\Throwable $e4) { }
            }

            // WebSocket 推送
            \app\worker\Pusher::pushTaskUpdate($userId, $taskId, 'failed', $finalError);
            \app\worker\Pusher::pushDoneSignal($userId, $taskId);
            $job->delete();
        } finally {
            if ($acquired && $redis) {
                try { $redis->del($lockKey); $redis->decr($semKey); } catch (\Throwable $e) { }
            }
        }
    }

    protected function summarizeFailureForUser(string $rawError, array $toolMeta, string $fallbackUserText): string
    {
        $s5Prompt = '';
        try {
            $row = Db::table('system_prompts')->order('id', 'desc')->find();
            if ($row && isset($row['agent_sm_s5_result_prompt']) && is_string($row['agent_sm_s5_result_prompt'])) {
                $s5Prompt = trim($row['agent_sm_s5_result_prompt']);
            }
        } catch (\Throwable $e) {
        }

        if ($s5Prompt === '') {
            return '生成失败，请调整提示词后重试。';
        }

        $conversationUid = isset($toolMeta['conversation_id']) ? (string)$toolMeta['conversation_id'] : '';
        $contextText = '';
        $lastUserText = '';
        if ($conversationUid !== '') {
            try {
                $row = Db::table('conversations')->where('conversation_id', $conversationUid)->find();
                if ($row && isset($row['messages_json']) && is_string($row['messages_json']) && $row['messages_json'] !== '') {
                    $msgs = json_decode($row['messages_json'], true);
                    if (is_array($msgs)) {
                        $contextText = $this->buildContextTextFromMessages($msgs, 12);
                        $lastUserText = $this->extractLastUserTextFromMessages($msgs);
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        if ($lastUserText === '') $lastUserText = $fallbackUserText;

        $userPayload = '';
        if ($contextText !== '') {
            $userPayload .= "对话上下文：\n" . $contextText . "\n\n";
        }
        $userPayload .= "用户本轮输入：\n" . (string)$lastUserText . "\n\n错误信息：\n" . $rawError;

        try {
            $llm = new LlmService();
            $resp = $llm->chat(
                [
                    ['role' => 'system', 'content' => $s5Prompt],
                    ['role' => 'user', 'content' => $userPayload],
                ],
                [
                    'stream' => false,
                    'temperature' => 0.3,
                    'usage_type' => 'image_error_summary',
                ],
                null
            );
            $content = isset($resp['content']) && is_string($resp['content']) ? trim($resp['content']) : '';
            if ($content !== '') return $content;
        } catch (\Throwable $e) {
            Log::error('summarizeFailureForUser failed: ' . $e->getMessage());
        }

        return '生成失败，请调整提示词后重试。';
    }

    protected function resolveGeneralLlmModelIdentity(): string
    {
        $val = '';
        try {
            $configVal = Db::table('system_configs')->where('category', 'default_models')->value('config');
            if ($configVal) {
                $config = json_decode($configVal, true);
                if (is_array($config)) {
                    if (!empty($config['default_general_llm_model'])) {
                        $val = (string)$config['default_general_llm_model'];
                    } elseif (!empty($config['default_llm_model'])) {
                        $val = (string)$config['default_llm_model'];
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        $val = trim((string)$val);
        if ($val === '') return '';

        try {
            $byId = Db::table('model_configs')->where('model_id', $val)->value('model_identity');
            if (is_string($byId) && trim($byId) !== '') return trim($byId);
        } catch (\Throwable $e) {
        }

        try {
            $byIdentity = Db::table('model_configs')->where('model_identity', $val)->where('status', 'active')->value('model_identity');
            if (is_string($byIdentity) && trim($byIdentity) !== '') return trim($byIdentity);
        } catch (\Throwable $e) {
        }

        return $val;
    }

    protected function summarizeSuccessForUser(string $fallbackUserText, array $result, array $toolMeta, array $imageUrls): string
    {
        return '图片生成任务已经完成，需要任何修改或者生成新的图片请告诉我';
    }

    protected function extractLastUserTextFromMessages(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i] ?? null;
            if (!is_array($m)) continue;
            if (($m['role'] ?? '') !== 'user') continue;
            $c = $m['content'] ?? '';
            if (is_string($c) && trim($c) !== '') return trim($c);
        }
        return '';
    }

    protected function buildContextTextFromMessages(array $messages, int $maxItems): string
    {
        $msgs = [];
        foreach ($messages as $m) {
            if (!is_array($m)) continue;
            $role = isset($m['role']) && is_string($m['role']) ? $m['role'] : '';
            if ($role !== 'user' && $role !== 'assistant') continue;
            $c = $m['content'] ?? '';
            if (!is_string($c)) continue;
            $t = trim($c);
            if ($t === '') continue;
            if (mb_strlen($t) > 400) $t = mb_substr($t, 0, 400);
            $prefix = $role === 'user' ? '用户' : '助手';
            $msgs[] = $prefix . '：' . $t;
        }
        if (count($msgs) > $maxItems) {
            $msgs = array_slice($msgs, -$maxItems);
        }
        return implode("\n", $msgs);
    }
}
