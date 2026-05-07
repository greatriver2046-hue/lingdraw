<?php
namespace app\controller\api;

use app\BaseController;
use think\Request;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;

class StyleProfile extends BaseController
{
    protected function ensureTable()
    {
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `style_profiles` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id` INT NULL,
                `user_id` INT NULL,
                `style_id` VARCHAR(50) NOT NULL,
                `input_hash` VARCHAR(64) NULL,
                `profile_json` LONGTEXT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_style_profiles_owner` (`tenant_id`, `user_id`, `style_id`),
                INDEX `idx_style_profiles_hash` (`input_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (\Throwable $e) {
        }
    }

    protected function getRedis()
    {
        $cfg = config('queue.connections.redis');
        $redis = class_exists('Redis') ? new \Redis() : null;
        if ($redis) {
            try {
                $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
                if (!empty($cfg['password'])) {
                    $redis->auth($cfg['password']);
                }
                if (!empty($cfg['select'])) {
                    $redis->select((int)$cfg['select']);
                }
                return $redis;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    public function generate(Request $request)
    {
        $this->ensureTable();

        $styleId = trim((string)$request->post('style_id', ''));
        if ($styleId === '') {
            return json(['code' => 400, 'msg' => 'Missing style_id', 'data' => null]);
        }

        $tenantId = $request->tenantId ?? null;
        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }

        $taskId = bin2hex(random_bytes(16));
        $taskKey = 'style_profile_task:' . $taskId;
        $statusArr = [
            'status' => 'queued',
            'stage' => 'queued',
            'phase' => 'queued',
            'progress' => '0',
            'style_id' => $styleId,
            'updated_at' => (string)time(),
        ];

        $redis = $this->getRedis();
        if ($redis) {
            try {
                $redis->hMSet($taskKey, $statusArr);
            } catch (\Throwable $e) {
                $existing = Cache::get($taskKey);
                if (is_array($existing)) {
                    $statusArr = array_merge($existing, $statusArr);
                }
                Cache::set($taskKey, $statusArr, 3600);
            }
        } else {
            $existing = Cache::get($taskKey);
            if (is_array($existing)) {
                $statusArr = array_merge($existing, $statusArr);
            }
            Cache::set($taskKey, $statusArr, 3600);
        }

        $payload = [
            'task_id' => $taskId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'style_id' => $styleId,
        ];

        try {
            Queue::push('app\\job\\StyleProfileGenerateJob', $payload, 'default');
        } catch (\Throwable $e) {
            Log::error('StyleProfile generate enqueue failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => 'Enqueue failed', 'data' => null]);
        }

        return json([
            'code' => 200,
            'msg' => 'Success',
            'data' => [
                'task_id' => $taskId,
                'status' => 'queued',
                'phase' => 'queued',
                'progress' => 0,
            ],
        ]);
    }

    public function task(Request $request)
    {
        $taskId = trim((string)$request->param('task_id', ''));
        if ($taskId === '') {
            return json(['code' => 400, 'msg' => 'Missing task_id', 'data' => null]);
        }

        $taskKey = 'style_profile_task:' . $taskId;
        $redis = $this->getRedis();
        $data = null;
        if ($redis) {
            try {
                $raw = $redis->hGetAll($taskKey);
                if ($raw && is_array($raw) && isset($raw['status'])) {
                    $data = $raw;
                }
            } catch (\Throwable $e) {
            }
        }
        if (!$data) {
            $cached = Cache::get($taskKey);
            if (is_array($cached) && isset($cached['status'])) {
                $data = $cached;
            }
        }

        if (!$data) {
            return json(['code' => 404, 'msg' => 'Task not found', 'data' => null]);
        }

        if (isset($data['result']) && is_string($data['result']) && $data['result'] !== '') {
            $decoded = json_decode($data['result'], true);
            if (is_array($decoded)) {
                $data['result'] = $decoded;
            }
        }

        return json(['code' => 200, 'msg' => 'Success', 'data' => $data]);
    }

    public function latest(Request $request)
    {
        $this->ensureTable();

        $tenantId = $request->tenantId ?? null;
        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }

        $styleId = trim((string)$request->param('style_id', ''));
        if ($styleId === '') {
            return json(['code' => 400, 'msg' => 'Missing style_id', 'data' => null]);
        }

        try {
            $query = Db::table('style_profiles')->where('style_id', $styleId);
            if ($tenantId !== null) {
                $query = $query->where('tenant_id', $tenantId);
            }
            $query = $query->where('user_id', $userId)->order('id', 'desc');
            $row = $query->find();
            if (!$row) {
                return json(['code' => 200, 'msg' => 'Success', 'data' => null]);
            }
            return json([
                'code' => 200,
                'msg' => 'Success',
                'data' => [
                    'id' => (int)$row['id'],
                    'style_id' => $row['style_id'],
                    'input_hash' => $row['input_hash'] ?? '',
                    'profile_json' => $row['profile_json'] ?? '',
                    'created_at' => $row['created_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('StyleProfile latest failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function list(Request $request)
    {
        $this->ensureTable();

        $tenantId = $request->tenantId ?? null;
        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }

        $styleId = trim((string)$request->param('style_id', ''));
        $limit = (int)$request->param('limit', 20);
        if ($limit <= 0) $limit = 20;
        if ($limit > 100) $limit = 100;

        try {
            $query = Db::table('style_profiles')->where('user_id', $userId)->order('id', 'desc');
            if ($tenantId !== null) {
                $query = $query->where('tenant_id', $tenantId);
            }
            if ($styleId !== '') {
                $query = $query->where('style_id', $styleId);
            }

            $rows = $query->limit($limit)->select()->toArray();
            $items = array_map(function ($row) {
                return [
                    'id' => (int)($row['id'] ?? 0),
                    'style_id' => (string)($row['style_id'] ?? ''),
                    'input_hash' => (string)($row['input_hash'] ?? ''),
                    'created_at' => $row['created_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                ];
            }, $rows);

            return json(['code' => 200, 'msg' => 'Success', 'data' => ['items' => $items]]);
        } catch (\Throwable $e) {
            Log::error('StyleProfile list failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function detail(Request $request)
    {
        $this->ensureTable();

        $tenantId = $request->tenantId ?? null;
        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }

        $id = (int)$request->param('id', 0);
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => 'Missing id', 'data' => null]);
        }

        try {
            $query = Db::table('style_profiles')->where('id', $id)->where('user_id', $userId);
            if ($tenantId !== null) {
                $query = $query->where('tenant_id', $tenantId);
            }
            $row = $query->find();
            if (!$row) {
                return json(['code' => 404, 'msg' => 'Not found', 'data' => null]);
            }

            return json([
                'code' => 200,
                'msg' => 'Success',
                'data' => [
                    'id' => (int)$row['id'],
                    'style_id' => (string)($row['style_id'] ?? ''),
                    'input_hash' => (string)($row['input_hash'] ?? ''),
                    'profile_json' => (string)($row['profile_json'] ?? ''),
                    'created_at' => $row['created_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('StyleProfile detail failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }
}
