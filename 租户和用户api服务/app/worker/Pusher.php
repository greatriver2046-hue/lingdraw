<?php

namespace app\worker;

use GatewayWorker\Lib\Gateway;

class Pusher
{
    /**
     * 向用户推送任务更新
     * @param int|string $userId 用户ID
     * @param string $taskId 任务ID
     * @param string $status 状态 (success, failed, processing, queued)
     * @param mixed $data 结果数据或错误信息
     */
    public static function pushTaskUpdate($userId, $taskId, $status, $data = null)
    {
        if (!$userId) {
            return;
        }

        // 设置 Gateway 的注册地址，与 config/gateway_worker.php 中的 registerAddress 一致
        $config = config('gateway_worker');
        Gateway::$registerAddress = $config['registerAddress'] ?? '127.0.0.1:1236';

        $pushData = [
            'type'    => 'task_update',
            'task_id' => $taskId,
            'status'  => $status,
            'timestamp' => time(),
        ];

        if ($status === 'success') {
            $pushData['result'] = $data;
        } elseif ($status === 'failed') {
            $pushData['error'] = $data;
        } elseif ($status === 'processing' && $data !== null) {
            $pushData['progress'] = $data;
        }

        // 同时推送到 Redis 队列，以便 WorkerServer (Windows兼容模式) 也能获取到
        try {
            $cfg = config('queue.connections.redis');
            $redis = new \Redis();
            $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379);
            if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
            if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
            
            $redis->rPush('ws_notifications', json_encode([
                'uid' => $userId,
                'payload' => $pushData
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // 忽略 Redis 失败
        }

        try {
            // 尝试使用 Gateway 推送 (Linux 环境)
            if (class_exists('GatewayWorker\Lib\Gateway') && !empty(config('gateway_worker.registerAddress'))) {
                Gateway::$registerAddress = config('gateway_worker.registerAddress');
                Gateway::sendToUid($userId, json_encode($pushData, JSON_UNESCAPED_UNICODE));
            }
        } catch (\Exception $e) {
            // 忽略推送失败
        }
    }

    public static function pushWritingTaskUpdate($userId, array $taskUpdate)
    {
        if (!$userId) {
            return;
        }

        $pushData = array_merge([
            'type' => 'writing_task_update',
            'timestamp' => time(),
        ], $taskUpdate);

        try {
            $cfg = config('queue.connections.redis');
            $redis = new \Redis();
            $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379);
            if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
            if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }

            $redis->rPush('ws_notifications', json_encode([
                'uid' => $userId,
                'payload' => $pushData
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
        }

        try {
            if (class_exists('GatewayWorker\Lib\Gateway') && !empty(config('gateway_worker.registerAddress'))) {
                Gateway::$registerAddress = config('gateway_worker.registerAddress');
                Gateway::sendToUid($userId, json_encode($pushData, JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * 推送完成信号
     * @param int|string $userId 用户ID
     * @param string $taskId 任务ID
     */
    public static function pushDoneSignal($userId, $taskId)
    {
        if (!$userId) {
            return;
        }

        $pushData = [
            'type'    => 'done',
            'task_id' => $taskId,
            'content' => '',
            'timestamp' => time(),
        ];

        // Redis Push
        try {
            $cfg = config('queue.connections.redis');
            $redis = new \Redis();
            $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379);
            if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
            if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
            
            $redis->rPush('ws_notifications', json_encode([
                'uid' => $userId,
                'payload' => $pushData
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
        }

        // Gateway Push
        try {
            if (class_exists('GatewayWorker\Lib\Gateway') && !empty(config('gateway_worker.registerAddress'))) {
                Gateway::$registerAddress = config('gateway_worker.registerAddress');
                Gateway::sendToUid($userId, json_encode($pushData, JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $e) {
        }
    }
}
