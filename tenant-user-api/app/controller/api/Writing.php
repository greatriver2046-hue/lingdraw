<?php
namespace app\controller\api;

use app\BaseController;
use think\Request;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
use app\worker\Pusher;

class Writing extends BaseController
{
    protected function resolveTaskIdByWorkResourceId($userId, $tenantId, string $workResourceId): array
    {
        $wid = trim((string)$workResourceId);
        if ($wid === '') return ['task_id' => '', 'task_status' => null];

        $taskId = '';
        $taskStatus = null;

        try {
            $q = Db::table('resources')
                ->where('resource_id', $wid)
                ->where('user_id', $userId)
                ->where('status', 'normal')
                ->order('id', 'desc');
            if ($tenantId !== null) {
                $q = $q->where('tenant_id', $tenantId);
            }
            $row = $q->find();
            if ($row) {
                $taskId = trim((string)($row['task_id'] ?? ''));
                $rawStatus = $row['task_status_json'] ?? null;
                if ($rawStatus !== null && $rawStatus !== '') {
                    try {
                        $decoded = json_decode((string)$rawStatus, true);
                        if (is_array($decoded)) $taskStatus = $decoded;
                    } catch (\Throwable $e) {
                        $taskStatus = null;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        if ($taskId === '') {
            try {
                $q2 = Db::table('writing_tasks')
                    ->where('work_resource_id', $wid)
                    ->where('user_id', $userId)
                    ->order('id', 'desc');
                if ($tenantId !== null) {
                    $q2 = $q2->where('tenant_id', $tenantId);
                }
                $row2 = $q2->find();
                if ($row2) {
                    $taskId = trim((string)($row2['task_id'] ?? ''));
                }
            } catch (\Throwable $e) {
            }
        }

        return ['task_id' => $taskId, 'task_status' => $taskStatus];
    }
    protected function ensureTables()
    {
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `writing_tasks` (
                `task_id` VARCHAR(32) NOT NULL,
                `tenant_id` INT NULL,
                `user_id` INT NULL,
                `style_id` VARCHAR(50) NULL,
                `style_profile_id` INT NULL,
                `status` VARCHAR(40) NULL,
                `stage` VARCHAR(60) NULL,
                `model` VARCHAR(40) NULL,
                `prompt_json` LONGTEXT NULL,
                `error_message` VARCHAR(1024) NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                `started_at` DATETIME NULL,
                `finished_at` DATETIME NULL,
                PRIMARY KEY (`task_id`),
                INDEX `idx_user` (`user_id`),
                INDEX `idx_tenant` (`tenant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (\Throwable $e) {
        }

        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `writing_artifacts` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `task_id` VARCHAR(32) NOT NULL,
                `type` VARCHAR(50) NOT NULL,
                `version` INT NOT NULL DEFAULT 1,
                `payload_json` LONGTEXT NULL,
                `text` LONGTEXT NULL,
                `created_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_task_type` (`task_id`, `type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (\Throwable $e) {
        }
    }

    protected function ensureResourcesTable()
    {
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `resources` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `resource_id` CHAR(32) NULL,
                `tenant_id` INT NULL,
                `user_id` INT NULL,
                `style_id` VARCHAR(50) NULL,
                `type` VARCHAR(20) NULL COMMENT 'note, link, file, group',
                `title` VARCHAR(255) NULL,
                `url` VARCHAR(1024) NULL,
                `content` LONGTEXT NULL,
                `task_id` VARCHAR(32) NULL,
                `task_status_json` LONGTEXT NULL,
                `style_profile_id` INT NULL,
                `topic` VARCHAR(255) NULL,
                `genre` VARCHAR(255) NULL,
                `word_count` INT NULL,
                `status` VARCHAR(20) DEFAULT 'normal' COMMENT 'normal, hidden, deleted',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_user_style` (`user_id`, `style_id`),
                UNIQUE KEY `uniq_resource_id` (`resource_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (\Throwable $e) {
        }

        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `resource_id` CHAR(32) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD UNIQUE KEY `uniq_resource_id` (`resource_id`)"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `tenant_id` INT NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `user_id` INT NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `style_id` VARCHAR(50) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `type` VARCHAR(20) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `title` VARCHAR(255) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `url` VARCHAR(1024) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `content` LONGTEXT NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `task_id` VARCHAR(32) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `task_status_json` LONGTEXT NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `style_profile_id` INT NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `topic` VARCHAR(255) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `genre` VARCHAR(255) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `word_count` INT NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `status` VARCHAR(20) DEFAULT 'normal' COMMENT 'normal, hidden, deleted'"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `created_at` DATETIME NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `updated_at` DATETIME NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD INDEX `idx_user_style` (`user_id`, `style_id`)"); } catch (\Throwable $e) {}
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

    protected function setTaskStatus($redis, string $taskKey, array $data)
    {
        $data['updated_at'] = isset($data['updated_at']) ? (string)$data['updated_at'] : (string)time();
        $existing = null;
        if ($redis) {
            try {
                $existing = $redis->hGetAll($taskKey);
            } catch (\Throwable $e) {
                $existing = null;
            }
        }
        if (!is_array($existing) || !isset($existing['status'])) {
            $cached = Cache::get($taskKey);
            if (is_array($cached)) {
                $existing = $cached;
            }
        }
        $merged = is_array($existing) ? array_merge($existing, $data) : $data;

        if ($redis) {
            try {
                $redis->hMSet($taskKey, $merged);
            } catch (\Throwable $e) {
            }
        }
        Cache::set($taskKey, $merged, 3600);

        $uid = (int)($merged['user_id'] ?? 0);
        if ($uid > 0) {
            $taskId = '';
            if (strpos($taskKey, 'writing_task:') === 0) {
                $taskId = substr($taskKey, strlen('writing_task:'));
            }
            if ($taskId !== '') {
                try {
                    Pusher::pushWritingTaskUpdate($uid, [
                        'task_id' => (string)$taskId,
                        'status' => (string)($merged['status'] ?? ''),
                        'stage' => (string)($merged['stage'] ?? ''),
                        'progress' => $merged['progress'] ?? 0,
                        'error_message' => (string)($merged['error_message'] ?? ''),
                        'work_resource_id' => (string)($merged['work_resource_id'] ?? ''),
                    ]);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    protected function readTaskStatus($redis, string $taskKey): ?array
    {
        if ($redis) {
            try {
                $raw = $redis->hGetAll($taskKey);
                if ($raw && is_array($raw) && isset($raw['status'])) return $raw;
            } catch (\Throwable $e) {
            }
        }
        $cached = Cache::get($taskKey);
        if (is_array($cached) && isset($cached['status'])) return $cached;
        return null;
    }

    protected function mapModelIdentity(?string $model): ?string
    {
        $m = trim((string)$model);
        if ($m === '') return null;
        if ($m === 'qwen3max') return 'qwen';
        if ($m === 'glm4.7') return 'glm';
        return $m;
    }

    protected function canAccessTask(Request $request, string $taskId, ?array $taskStatus): bool
    {
        $userId = $request->userId ?? null;
        if (!$userId) return false;
        $tenantId = $request->tenantId ?? null;

        if (is_array($taskStatus)) {
            if (isset($taskStatus['user_id']) && (int)$taskStatus['user_id'] !== (int)$userId) return false;
            if ($tenantId !== null && isset($taskStatus['tenant_id']) && (int)$taskStatus['tenant_id'] !== (int)$tenantId) return false;
        }

        try {
            $row = Db::table('writing_tasks')->where('task_id', $taskId)->find();
            if (!$row) return is_array($taskStatus) && isset($taskStatus['user_id']);
            if ((int)($row['user_id'] ?? 0) !== (int)$userId) return false;
            if ($tenantId !== null && (int)($row['tenant_id'] ?? 0) !== (int)$tenantId) return false;
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function create(Request $request)
    {
        $this->ensureTables();

        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }
        $tenantId = $request->tenantId ?? null;

        $title = trim((string)$request->post('title', ''));
        $topic = trim((string)$request->post('topic', ''));
        $genre = trim((string)$request->post('genre', ''));
        if ($genre === '') $genre = '科普';
        $writingPurpose = trim((string)$request->post('writing_purpose', $request->post('purpose', '')));
        $targetAudience = trim((string)$request->post('target_audience', $request->post('audience', '')));
        $requirements = trim((string)$request->post('requirements', ''));
        $wordCount = (int)$request->post('word_count', 0);
        if ($wordCount < 0) $wordCount = 0;
        $styleId = trim((string)$request->post('style_id', ''));
        $styleProfileId = (int)$request->post('style_profile_id', 0);
        $model = $this->mapModelIdentity($request->post('model', ''));
        $workResourceIdFromClient = trim((string)$request->post('work_resource_id', ''));

        if ($topic === '') {
            return json(['code' => 400, 'msg' => 'Missing topic', 'data' => null]);
        }
        if ($styleId !== '' && $styleProfileId <= 0) {
            return json(['code' => 400, 'msg' => 'Missing style_profile_id', 'data' => null]);
        }

        $taskId = bin2hex(random_bytes(16));
        $taskKey = 'writing_task:' . $taskId;
        $redis = $this->getRedis();

        $this->ensureResourcesTable();
        $workTitle = $title !== '' ? $title : mb_substr($topic, 0, 30, 'UTF-8');
        $workResourceId = '';
        $now = date('Y-m-d H:i:s');
        $reuseWork = false;
        if ($workResourceIdFromClient !== '') {
            try {
                $q = Db::table('resources')
                    ->where('resource_id', $workResourceIdFromClient)
                    ->where('user_id', $userId)
                    ->where('status', 'normal')
                    ->order('id', 'desc');
                if ($tenantId !== null) {
                    $q = $q->where('tenant_id', $tenantId);
                }
                $row = $q->find();
                if ($row && (string)($row['type'] ?? '') === 'work') {
                    $workResourceId = $workResourceIdFromClient;
                    $reuseWork = true;
                }
            } catch (\Throwable $e) {
                $reuseWork = false;
            }
        }

        if (!$reuseWork) {
            $workResourceId = bin2hex(random_bytes(16));
            try {
                Db::table('resources')->insert([
                    'resource_id' => $workResourceId,
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'style_id' => $styleId !== '' ? $styleId : null,
                    'type' => 'work',
                    'title' => $workTitle !== '' ? $workTitle : '未命名作品',
                    'url' => null,
                    'content' => '',
                    'task_id' => $taskId,
                    'task_status_json' => json_encode(['status' => 'QUEUED', 'stage' => 'QUEUED', 'progress' => '0'], JSON_UNESCAPED_UNICODE),
                    'style_profile_id' => $styleProfileId > 0 ? $styleProfileId : null,
                    'topic' => $topic,
                    'genre' => $genre,
                    'word_count' => $wordCount > 0 ? $wordCount : null,
                    'status' => 'normal',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Throwable $e) {
                return json(['code' => 500, 'msg' => 'Create work failed', 'data' => null]);
            }
        } else {
            try {
                $updates = [
                    'task_id' => $taskId,
                    'task_status_json' => json_encode(['status' => 'QUEUED', 'stage' => 'QUEUED', 'progress' => '0'], JSON_UNESCAPED_UNICODE),
                    'style_id' => $styleId !== '' ? $styleId : null,
                    'style_profile_id' => $styleProfileId > 0 ? $styleProfileId : null,
                    'topic' => $topic,
                    'genre' => $genre,
                    'word_count' => $wordCount > 0 ? $wordCount : null,
                    'updated_at' => $now,
                    'content' => '',
                ];
                if ($workTitle !== '') {
                    $updates['title'] = $workTitle;
                }
                $q2 = Db::table('resources')
                    ->where('resource_id', $workResourceId)
                    ->where('user_id', $userId)
                    ->where('status', 'normal');
                if ($tenantId !== null) {
                    $q2 = $q2->where('tenant_id', $tenantId);
                }
                $q2->update($updates);
            } catch (\Throwable $e) {
                return json(['code' => 500, 'msg' => 'Update work failed', 'data' => null]);
            }
        }

        $statusArr = [
            'status' => 'QUEUED',
            'stage' => 'QUEUED',
            'progress' => '0',
            'tenant_id' => $tenantId !== null ? (string)$tenantId : '',
            'user_id' => (string)$userId,
            'style_id' => $styleId,
            'style_profile_id' => (string)$styleProfileId,
            'work_resource_id' => $workResourceId,
            'updated_at' => (string)time(),
        ];
        $this->setTaskStatus($redis, $taskKey, $statusArr);

        try {
            Db::table('writing_tasks')->insert([
                'task_id' => $taskId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'style_id' => $styleId,
                'style_profile_id' => $styleProfileId,
                'status' => 'QUEUED',
                'stage' => 'QUEUED',
                'model' => $model ?: '',
                'prompt_json' => json_encode([
                    'title' => $title,
                    'topic' => $topic,
                    'genre' => $genre,
                    'writing_purpose' => $writingPurpose,
                    'target_audience' => $targetAudience,
                    'requirements' => $requirements,
                    'word_count' => $wordCount,
                    'style_id' => $styleId,
                    'style_profile_id' => $styleProfileId,
                    'model' => $model,
                    'work_resource_id' => $workResourceId,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
                'started_at' => null,
                'finished_at' => null,
            ]);
        } catch (\Throwable $e) {
        }

        $payload = [
            'task_id' => $taskId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'title' => $title,
            'topic' => $topic,
            'genre' => $genre,
            'writing_purpose' => $writingPurpose,
            'target_audience' => $targetAudience,
            'requirements' => $requirements,
            'word_count' => $wordCount,
            'style_id' => $styleId,
            'style_profile_id' => $styleProfileId,
            'model_identity' => $model,
            'work_resource_id' => $workResourceId,
        ];

        try {
            Queue::push('app\\job\\WritingTaskJob', $payload, 'default');
        } catch (\Throwable $e) {
            Log::error('Writing create enqueue failed: ' . $e->getMessage());
            $this->setTaskStatus($redis, $taskKey, [
                'status' => 'FAILED',
                'stage' => 'QUEUED',
                'progress' => '0',
                'error_message' => 'Enqueue failed',
            ]);
            return json(['code' => 500, 'msg' => 'Enqueue failed', 'data' => null]);
        }

        return json(['code' => 200, 'msg' => 'Success', 'data' => ['task_id' => $taskId, 'status' => 'QUEUED', 'work_resource_id' => $workResourceId]]);
    }

    public function applyStyle(Request $request)
    {
        $this->ensureTables();
        $this->ensureResourcesTable();

        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }
        $tenantId = $request->tenantId ?? null;

        $taskId = trim((string)$request->post('task_id', ''));
        $workResourceIdInput = trim((string)$request->post('work_resource_id', $request->post('work_id', '')));
        $styleId = trim((string)$request->post('style_id', ''));
        $styleProfileId = (int)$request->post('style_profile_id', 0);
        $model = $this->mapModelIdentity($request->post('model', ''));

        $workById = null;
        if ($workResourceIdInput !== '') {
            try {
                $q = Db::table('resources')
                    ->where('resource_id', $workResourceIdInput)
                    ->where('user_id', $userId)
                    ->where('status', 'normal')
                    ->order('id', 'desc');
                if ($tenantId !== null) {
                    $q = $q->where('tenant_id', $tenantId);
                }
                $workById = $q->find();
            } catch (\Throwable $e) {
                $workById = null;
            }
        }
        $taskIdFromWork = $workById ? trim((string)($workById['task_id'] ?? '')) : '';
        if ($taskIdFromWork !== '' && ($taskId === '' || $taskId !== $taskIdFromWork)) {
            $taskId = $taskIdFromWork;
        }

        if ($taskId === '' && $workResourceIdInput !== '') {
            $resolved = $this->resolveTaskIdByWorkResourceId($userId, $tenantId, $workResourceIdInput);
            $taskId = trim((string)($resolved['task_id'] ?? ''));
        }
        if ($taskId === '') {
            return json(['code' => 400, 'msg' => 'Missing task_id', 'data' => null]);
        }
        if ($styleId === '' || $styleProfileId <= 0) {
            return json(['code' => 400, 'msg' => 'Missing style_id or style_profile_id', 'data' => null]);
        }

        $rowTask = null;
        try {
            $rowTask = Db::table('writing_tasks')->where('task_id', $taskId)->find();
        } catch (\Throwable $e) {
            $rowTask = null;
        }
        if (!$rowTask) {
            $fallbackWork = $workById;
            try {
                if (!$fallbackWork) {
                    $q = Db::table('resources')
                        ->where('task_id', $taskId)
                        ->where('user_id', $userId)
                        ->where('status', 'normal')
                        ->order('id', 'desc');
                    if ($tenantId !== null) {
                        $q = $q->where('tenant_id', $tenantId);
                    }
                    $fallbackWork = $q->find();
                }
            } catch (\Throwable $e) {
                if (!$fallbackWork) $fallbackWork = null;
            }
            if ($fallbackWork) {
                $now = date('Y-m-d H:i:s');
                try {
                    $topic = trim((string)($fallbackWork['topic'] ?? ''));
                    $genre = trim((string)($fallbackWork['genre'] ?? ''));
                    $wordCount = (int)($fallbackWork['word_count'] ?? 0);
                    if ($wordCount < 0) $wordCount = 0;
                    $title = trim((string)($fallbackWork['title'] ?? ''));
                    $workResourceId = trim((string)($fallbackWork['resource_id'] ?? ''));
                    Db::table('writing_tasks')->insert([
                        'task_id' => $taskId,
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'style_id' => $fallbackWork['style_id'] ?? null,
                        'style_profile_id' => $fallbackWork['style_profile_id'] ?? null,
                        'status' => 'WAIT_STYLE_TRANSFER',
                        'stage' => 'STAGE_NEUTRAL_DRAFT',
                        'model' => null,
                        'prompt_json' => json_encode([
                            'title' => $title,
                            'topic' => $topic,
                            'genre' => $genre,
                            'word_count' => $wordCount,
                            'work_resource_id' => $workResourceId,
                        ], JSON_UNESCAPED_UNICODE),
                        'error_message' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'started_at' => null,
                        'finished_at' => null,
                    ]);
                } catch (\Throwable $e) {
                }
                try {
                    $rowTask = Db::table('writing_tasks')->where('task_id', $taskId)->find();
                } catch (\Throwable $e) {
                    $rowTask = null;
                }
            }
            if (!$rowTask) {
                return json(['code' => 404, 'msg' => 'Task not found', 'data' => null]);
            }
        }
        if ((int)($rowTask['user_id'] ?? 0) !== (int)$userId) {
            return json(['code' => 404, 'msg' => 'Task not found', 'data' => null]);
        }
        if ($tenantId !== null && (int)($rowTask['tenant_id'] ?? 0) !== (int)$tenantId) {
            return json(['code' => 404, 'msg' => 'Task not found', 'data' => null]);
        }

        $taskKey = 'writing_task:' . $taskId;
        $redis = $this->getRedis();
        $existingStatus = $this->readTaskStatus($redis, $taskKey);
        $stNow = $existingStatus ? trim((string)($existingStatus['status'] ?? '')) : trim((string)($rowTask['status'] ?? ''));
        if ($stNow !== '' && !in_array($stNow, ['SUCCEEDED', 'FAILED', 'CANCELLED', 'WAIT_STYLE_TRANSFER'], true)) {
            return json(['code' => 409, 'msg' => 'Task is running', 'data' => $existingStatus]);
        }

        try {
            $q = Db::table('style_profiles')->where('id', $styleProfileId)->where('user_id', $userId)->where('style_id', $styleId);
            if ($tenantId !== null) {
                $q = $q->where('tenant_id', $tenantId);
            }
            $profileRow = $q->find();
            if (!$profileRow) {
                return json(['code' => 404, 'msg' => 'Style Profile not found', 'data' => null]);
            }
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Style Profile query failed', 'data' => null]);
        }

        $hasNeutralDraft = false;
        $neutralText = '';
        try {
            $neutral = Db::table('writing_artifacts')
                ->where('task_id', $taskId)
                ->where('type', 'neutral_draft')
                ->order('id', 'desc')
                ->find();
            if ($neutral) {
                $neutralText = trim((string)($neutral['text'] ?? ''));
            }
            $hasNeutralDraft = $neutralText !== '';
        } catch (\Throwable $e) {
            $hasNeutralDraft = false;
        }
        if (!$hasNeutralDraft && $workById) {
            $neutralText = trim((string)($workById['content'] ?? ''));
            $hasNeutralDraft = $neutralText !== '';
            if ($hasNeutralDraft) {
                $now = date('Y-m-d H:i:s');
                try {
                    $maxV = 0;
                    try {
                        $maxV = (int)Db::table('writing_artifacts')->where('task_id', $taskId)->where('type', 'neutral_draft')->max('version');
                    } catch (\Throwable $e) {
                        $maxV = 0;
                    }
                    $ver = $maxV > 0 ? ($maxV + 1) : 1;
                    Db::table('writing_artifacts')->insert([
                        'task_id' => $taskId,
                        'type' => 'neutral_draft',
                        'version' => $ver,
                        'payload_json' => null,
                        'text' => $neutralText,
                        'created_at' => $now,
                    ]);
                } catch (\Throwable $e) {
                }
            }
        }
        if (!$hasNeutralDraft) {
            return json(['code' => 400, 'msg' => 'Neutral draft not found', 'data' => null]);
        }

        $work = $workById;
        try {
            if (!$work) {
                $work = Db::table('resources')
                    ->where('task_id', $taskId)
                    ->where('user_id', $userId)
                    ->where('status', 'normal')
                    ->order('id', 'desc')
                    ->find();
            }
        } catch (\Throwable $e) {
            $work = null;
        }
        $workResourceId = $work ? trim((string)($work['resource_id'] ?? '')) : '';
        if ($workResourceId === '' && $workResourceIdInput !== '') {
            $workResourceId = $workResourceIdInput;
        }
        $topic = $work ? trim((string)($work['topic'] ?? '')) : '';
        $genre = $work ? trim((string)($work['genre'] ?? '')) : '';
        $wordCount = $work ? (int)($work['word_count'] ?? 0) : 0;
        if ($wordCount < 0) $wordCount = 0;
        $title = $work ? trim((string)($work['title'] ?? '')) : '';

        $promptJson = [];
        $requirements = '';
        try {
            $promptJson = json_decode((string)($rowTask['prompt_json'] ?? ''), true);
            if (!is_array($promptJson)) $promptJson = [];
        } catch (\Throwable $e) {
            $promptJson = [];
        }
        $requirements = trim((string)($promptJson['requirements'] ?? ''));
        if ($topic === '') {
            $topic = trim((string)($promptJson['topic'] ?? ''));
        }
        if ($genre === '') {
            $genre = trim((string)($promptJson['genre'] ?? ''));
        }
        if ($wordCount <= 0) {
            $wc2 = $promptJson['word_count'] ?? 0;
            $wc2 = is_numeric($wc2) ? (int)$wc2 : 0;
            if ($wc2 > 0) $wordCount = $wc2;
        }
        if ($model === null || $model === '') {
            $model = $this->mapModelIdentity($promptJson['model'] ?? ($rowTask['model'] ?? ''));
        }
        if ($topic === '') {
            $topic = $title !== '' ? $title : '未命名作品';
        }

        $now = date('Y-m-d H:i:s');
        try {
            if ($workResourceId !== '') {
                Db::table('resources')
                    ->where('resource_id', $workResourceId)
                    ->where('user_id', $userId)
                    ->update([
                        'style_id' => $styleId,
                        'style_profile_id' => $styleProfileId,
                        'updated_at' => $now,
                    ]);
            }
        } catch (\Throwable $e) {
        }

        try {
            $promptJson['style_id'] = $styleId;
            $promptJson['style_profile_id'] = $styleProfileId;
            if ($model !== null) $promptJson['model'] = $model;
            Db::table('writing_tasks')->where('task_id', $taskId)->update([
                'style_id' => $styleId,
                'style_profile_id' => $styleProfileId,
                'model' => $model ?: (string)($rowTask['model'] ?? ''),
                'prompt_json' => json_encode($promptJson, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
                'finished_at' => null,
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
        }

        $this->setTaskStatus($redis, $taskKey, [
            'status' => 'QUEUED',
            'stage' => 'STAGE_NEUTRAL_DRAFT',
            'progress' => '75',
            'tenant_id' => $tenantId !== null ? (string)$tenantId : '',
            'user_id' => (string)$userId,
            'style_id' => $styleId,
            'style_profile_id' => (string)$styleProfileId,
            'work_resource_id' => $workResourceId,
            'error_message' => null,
        ]);

        $payload = [
            'task_id' => $taskId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'title' => $title,
            'topic' => $topic,
            'genre' => $genre,
            'word_count' => $wordCount,
            'requirements' => $requirements,
            'style_id' => $styleId,
            'style_profile_id' => $styleProfileId,
            'model_identity' => $model,
            'work_resource_id' => $workResourceId,
            'resume_from_neutral' => 1,
        ];

        try {
            Queue::push('app\\job\\WritingTaskJob', $payload, 'default');
        } catch (\Throwable $e) {
            Log::error('Writing applyStyle enqueue failed: ' . $e->getMessage());
            $this->setTaskStatus($redis, $taskKey, [
                'status' => 'FAILED',
                'stage' => 'STAGE_NEUTRAL_DRAFT',
                'progress' => '75',
                'error_message' => 'Enqueue failed',
            ]);
            return json(['code' => 500, 'msg' => 'Enqueue failed', 'data' => null]);
        }

        $data = $this->readTaskStatus($redis, $taskKey);
        if (is_array($data)) $data['task_id'] = $taskId;
        return json(['code' => 200, 'msg' => 'Success', 'data' => $data]);
    }

    public function skipStyle(Request $request)
    {
        $this->ensureTables();
        $this->ensureResourcesTable();

        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }
        $tenantId = $request->tenantId ?? null;

        $taskId = trim((string)$request->post('task_id', ''));
        $workResourceIdInput = trim((string)$request->post('work_resource_id', $request->post('work_id', '')));
        $workById = null;
        if ($workResourceIdInput !== '') {
            try {
                $q = Db::table('resources')
                    ->where('resource_id', $workResourceIdInput)
                    ->where('user_id', $userId)
                    ->where('status', 'normal')
                    ->order('id', 'desc');
                if ($tenantId !== null) {
                    $q = $q->where('tenant_id', $tenantId);
                }
                $workById = $q->find();
            } catch (\Throwable $e) {
                $workById = null;
            }
        }
        $taskIdFromWork = $workById ? trim((string)($workById['task_id'] ?? '')) : '';
        if ($taskIdFromWork !== '' && ($taskId === '' || $taskId !== $taskIdFromWork)) {
            $taskId = $taskIdFromWork;
        }
        if ($taskId === '' && $workResourceIdInput !== '') {
            $resolved = $this->resolveTaskIdByWorkResourceId($userId, $tenantId, $workResourceIdInput);
            $taskId = trim((string)($resolved['task_id'] ?? ''));
        }
        if ($taskId === '') {
            if ($workById) {
                $neutralText = trim((string)($workById['content'] ?? ''));
                if ($neutralText !== '') {
                    $now = date('Y-m-d H:i:s');
                    try {
                        Db::table('resources')
                            ->where('resource_id', $workResourceIdInput)
                            ->where('user_id', $userId)
                            ->update([
                                'content' => $neutralText,
                                'updated_at' => $now,
                            ]);
                    } catch (\Throwable $e) {
                    }
                    return json(['code' => 200, 'msg' => 'Success', 'data' => [
                        'task_id' => '',
                        'status' => 'SUCCEEDED',
                        'stage' => 'SUCCEEDED',
                        'progress' => '100',
                        'work_resource_id' => $workResourceIdInput,
                    ]]);
                }
            }
            return json(['code' => 400, 'msg' => 'Missing task_id', 'data' => null]);
        }

        $rowTask = null;
        try {
            $rowTask = Db::table('writing_tasks')->where('task_id', $taskId)->find();
        } catch (\Throwable $e) {
            $rowTask = null;
        }
        if (!$rowTask) {
            $fallbackWork = $workById;
            try {
                if (!$fallbackWork) {
                    $q = Db::table('resources')
                        ->where('task_id', $taskId)
                        ->where('user_id', $userId)
                        ->where('status', 'normal')
                        ->order('id', 'desc');
                    if ($tenantId !== null) {
                        $q = $q->where('tenant_id', $tenantId);
                    }
                    $fallbackWork = $q->find();
                }
            } catch (\Throwable $e) {
                if (!$fallbackWork) $fallbackWork = null;
            }
            if ($fallbackWork) {
                $now = date('Y-m-d H:i:s');
                try {
                    $topic = trim((string)($fallbackWork['topic'] ?? ''));
                    $genre = trim((string)($fallbackWork['genre'] ?? ''));
                    $wordCount = (int)($fallbackWork['word_count'] ?? 0);
                    if ($wordCount < 0) $wordCount = 0;
                    $title = trim((string)($fallbackWork['title'] ?? ''));
                    $workResourceId = trim((string)($fallbackWork['resource_id'] ?? ''));
                    Db::table('writing_tasks')->insert([
                        'task_id' => $taskId,
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'style_id' => $fallbackWork['style_id'] ?? null,
                        'style_profile_id' => $fallbackWork['style_profile_id'] ?? null,
                        'status' => 'WAIT_STYLE_TRANSFER',
                        'stage' => 'STAGE_NEUTRAL_DRAFT',
                        'model' => null,
                        'prompt_json' => json_encode([
                            'title' => $title,
                            'topic' => $topic,
                            'genre' => $genre,
                            'word_count' => $wordCount,
                            'work_resource_id' => $workResourceId,
                        ], JSON_UNESCAPED_UNICODE),
                        'error_message' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'started_at' => null,
                        'finished_at' => null,
                    ]);
                } catch (\Throwable $e) {
                }
                try {
                    $rowTask = Db::table('writing_tasks')->where('task_id', $taskId)->find();
                } catch (\Throwable $e) {
                    $rowTask = null;
                }
            }
            if (!$rowTask) {
                return json(['code' => 404, 'msg' => 'Task not found', 'data' => null]);
            }
        }
        if ((int)($rowTask['user_id'] ?? 0) !== (int)$userId) {
            return json(['code' => 404, 'msg' => 'Task not found', 'data' => null]);
        }
        if ($tenantId !== null && (int)($rowTask['tenant_id'] ?? 0) !== (int)$tenantId) {
            return json(['code' => 404, 'msg' => 'Task not found', 'data' => null]);
        }

        $taskKey = 'writing_task:' . $taskId;
        $redis = $this->getRedis();
        $existingStatus = $this->readTaskStatus($redis, $taskKey);
        $stNow = $existingStatus ? trim((string)($existingStatus['status'] ?? '')) : trim((string)($rowTask['status'] ?? ''));
        if ($stNow !== '' && !in_array($stNow, ['SUCCEEDED', 'FAILED', 'CANCELLED', 'WAIT_STYLE_TRANSFER'], true)) {
            return json(['code' => 409, 'msg' => 'Task is running', 'data' => $existingStatus]);
        }

        $neutralText = '';
        try {
            $neutral = Db::table('writing_artifacts')
                ->where('task_id', $taskId)
                ->where('type', 'neutral_draft')
                ->order('id', 'desc')
                ->find();
            if ($neutral) {
                $neutralText = trim((string)($neutral['text'] ?? ''));
            }
        } catch (\Throwable $e) {
            $neutralText = '';
        }
        if ($neutralText === '' && $workById) {
            $neutralText = trim((string)($workById['content'] ?? ''));
            if ($neutralText !== '') {
                $now = date('Y-m-d H:i:s');
                try {
                    $maxV = 0;
                    try {
                        $maxV = (int)Db::table('writing_artifacts')->where('task_id', $taskId)->where('type', 'neutral_draft')->max('version');
                    } catch (\Throwable $e) {
                        $maxV = 0;
                    }
                    $ver = $maxV > 0 ? ($maxV + 1) : 1;
                    Db::table('writing_artifacts')->insert([
                        'task_id' => $taskId,
                        'type' => 'neutral_draft',
                        'version' => $ver,
                        'payload_json' => null,
                        'text' => $neutralText,
                        'created_at' => $now,
                    ]);
                } catch (\Throwable $e) {
                }
            }
        }
        if ($neutralText === '') {
            return json(['code' => 400, 'msg' => 'Neutral draft not found', 'data' => null]);
        }

        $work = $workById;
        try {
            if (!$work) {
                $work = Db::table('resources')
                    ->where('task_id', $taskId)
                    ->where('user_id', $userId)
                    ->where('status', 'normal')
                    ->order('id', 'desc')
                    ->find();
            }
        } catch (\Throwable $e) {
            $work = null;
        }
        $workResourceId = $work ? trim((string)($work['resource_id'] ?? '')) : '';
        if ($workResourceId === '' && $workResourceIdInput !== '') {
            $workResourceId = $workResourceIdInput;
        }

        $now = date('Y-m-d H:i:s');
        if ($workResourceId !== '') {
            try {
                Db::table('resources')
                    ->where('resource_id', $workResourceId)
                    ->where('user_id', $userId)
                    ->update([
                        'content' => $neutralText,
                        'updated_at' => $now,
                    ]);
            } catch (\Throwable $e) {
            }
        }

        try {
            $maxV = 0;
            try {
                $maxV = (int)Db::table('writing_artifacts')->where('task_id', $taskId)->where('type', 'final_article')->max('version');
            } catch (\Throwable $e) {
                $maxV = 0;
            }
            $ver = $maxV > 0 ? ($maxV + 1) : 1;
            Db::table('writing_artifacts')->insert([
                'task_id' => $taskId,
                'type' => 'final_article',
                'version' => $ver,
                'payload_json' => null,
                'text' => $neutralText,
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
        }

        try {
            Db::table('writing_tasks')->where('task_id', $taskId)->update([
                'status' => 'SUCCEEDED',
                'stage' => 'SUCCEEDED',
                'updated_at' => $now,
                'finished_at' => $now,
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
        }

        $this->setTaskStatus($redis, $taskKey, [
            'status' => 'SUCCEEDED',
            'stage' => 'SUCCEEDED',
            'progress' => '100',
            'tenant_id' => $tenantId !== null ? (string)$tenantId : '',
            'user_id' => (string)$userId,
            'work_resource_id' => $workResourceId,
            'error_message' => null,
        ]);

        return json(['code' => 200, 'msg' => 'Success', 'data' => $this->readTaskStatus($redis, $taskKey)]);
    }

    public function task(Request $request)
    {
        $this->ensureTables();

        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }

        $taskId = trim((string)$request->param('task_id', ''));
        $workResourceIdInput = trim((string)$request->param('work_resource_id', $request->param('work_id', '')));
        $tenantId = $request->tenantId ?? null;
        $taskStatusFromResource = null;
        if ($taskId === '' && $workResourceIdInput !== '') {
            $resolved = $this->resolveTaskIdByWorkResourceId($userId, $tenantId, $workResourceIdInput);
            $taskId = trim((string)($resolved['task_id'] ?? ''));
            $taskStatusFromResource = $resolved['task_status'] ?? null;
        }
        if ($taskId === '') {
            if (is_array($taskStatusFromResource)) {
                $taskStatusFromResource['work_resource_id'] = $workResourceIdInput;
                return json(['code' => 200, 'msg' => 'Success', 'data' => $taskStatusFromResource]);
            }
            return json(['code' => 400, 'msg' => 'Missing task_id', 'data' => null]);
        }

        $taskKey = 'writing_task:' . $taskId;
        $redis = $this->getRedis();
        $data = $this->readTaskStatus($redis, $taskKey);
        if (!$data) {
            if ($workResourceIdInput !== '') {
                try {
                    $q = Db::table('resources')
                        ->where('resource_id', $workResourceIdInput)
                        ->where('user_id', $userId)
                        ->where('status', 'normal')
                        ->order('id', 'desc');
                    if ($tenantId !== null) {
                        $q = $q->where('tenant_id', $tenantId);
                    }
                    $row = $q->find();
                    if ($row) {
                        $rawStatus = $row['task_status_json'] ?? null;
                        if ($rawStatus !== null && $rawStatus !== '') {
                            $decoded = json_decode((string)$rawStatus, true);
                            if (is_array($decoded)) {
                                $decoded['work_resource_id'] = $workResourceIdInput;
                                $decoded['task_id'] = $taskId;
                                return json(['code' => 200, 'msg' => 'Success', 'data' => $decoded]);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
            if (is_array($taskStatusFromResource)) {
                $taskStatusFromResource['task_id'] = $taskId;
                if ($workResourceIdInput !== '') $taskStatusFromResource['work_resource_id'] = $workResourceIdInput;
                return json(['code' => 200, 'msg' => 'Success', 'data' => $taskStatusFromResource]);
            }
            return json(['code' => 404, 'msg' => 'Task not found', 'data' => null]);
        }
        if (is_array($data)) $data['task_id'] = $taskId;
        if (!$this->canAccessTask($request, $taskId, $data)) {
            return json(['code' => 404, 'msg' => 'Task not found', 'data' => null]);
        }

        $this->ensureResourcesTable();
        $workResourceId = trim((string)($data['work_resource_id'] ?? ''));
        if ($workResourceId !== '') {
            try {
                Db::table('resources')
                    ->where('resource_id', $workResourceId)
                    ->where('user_id', $userId)
                    ->where('status', 'normal')
                    ->update([
                        'task_id' => $taskId,
                        'task_status_json' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    ]);
            } catch (\Throwable $e) {
            }
        }

        return json(['code' => 200, 'msg' => 'Success', 'data' => $data]);
    }

    public function cancel(Request $request)
    {
        $this->ensureTables();

        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }

        $taskId = trim((string)$request->post('task_id', ''));
        if ($taskId === '') {
            return json(['code' => 400, 'msg' => 'Missing task_id', 'data' => null]);
        }

        $taskKey = 'writing_task:' . $taskId;
        $redis = $this->getRedis();
        $existing = $this->readTaskStatus($redis, $taskKey);
        if (!$existing) {
            return json(['code' => 404, 'msg' => 'Task not found', 'data' => null]);
        }
        if (!$this->canAccessTask($request, $taskId, $existing)) {
            return json(['code' => 404, 'msg' => 'Task not found', 'data' => null]);
        }

        $st = (string)($existing['status'] ?? '');
        if (in_array($st, ['SUCCEEDED', 'FAILED', 'CANCELLED'], true)) {
            return json(['code' => 200, 'msg' => 'Success', 'data' => $existing]);
        }

        if ($redis) {
            try {
                $redis->setex('writing_cancel:' . $taskId, 3600, '1');
            } catch (\Throwable $e) {
                Cache::set('writing_cancel:' . $taskId, '1', 3600);
            }
        } else {
            Cache::set('writing_cancel:' . $taskId, '1', 3600);
        }

        $this->setTaskStatus($redis, $taskKey, [
            'status' => 'CANCELLED',
            'stage' => (string)($existing['stage'] ?? ''),
            'progress' => (string)($existing['progress'] ?? '0'),
        ]);

        try {
            Db::table('writing_tasks')->where('task_id', $taskId)->update([
                'status' => 'CANCELLED',
                'updated_at' => date('Y-m-d H:i:s'),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
        }

        $data = $this->readTaskStatus($redis, $taskKey);
        if (is_array($data)) $data['task_id'] = $taskId;
        return json(['code' => 200, 'msg' => 'Success', 'data' => $data]);
    }

    public function result(Request $request)
    {
        $this->ensureTables();

        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }

        $taskId = trim((string)$request->param('task_id', ''));
        if ($taskId === '') {
            return json(['code' => 400, 'msg' => 'Missing task_id', 'data' => null]);
        }
        $type = trim((string)$request->param('type', 'final_article'));
        if ($type === '') $type = 'final_article';

        try {
            $rowTask = Db::table('writing_tasks')->where('task_id', $taskId)->find();
            if ($rowTask) {
                if ((int)($rowTask['user_id'] ?? 0) !== (int)$userId) {
                    return json(['code' => 404, 'msg' => 'Not found', 'data' => null]);
                }
                $tenantId = $request->tenantId ?? null;
                if ($tenantId !== null && (int)($rowTask['tenant_id'] ?? 0) !== (int)$tenantId) {
                    return json(['code' => 404, 'msg' => 'Not found', 'data' => null]);
                }
            }

            $tryTypes = [$type];
            if ($type === 'final_article') {
                $tryTypes = ['final_article', 'styled_final', 'styled_draft', 'neutral_draft'];
            }

            $row = null;
            $hitType = $type;
            foreach ($tryTypes as $tt) {
                $row = Db::table('writing_artifacts')
                    ->where('task_id', $taskId)
                    ->where('type', $tt)
                    ->order('id', 'desc')
                    ->find();
                if ($row) {
                    $hitType = $tt;
                    break;
                }
            }
            if (!$row) {
                $this->ensureResourcesTable();
                $work = null;
                try {
                    $work = Db::table('resources')
                        ->where('task_id', $taskId)
                        ->where('user_id', $userId)
                        ->where('status', 'normal')
                        ->order('id', 'desc')
                        ->find();
                } catch (\Throwable $e) {
                    $work = null;
                }
                $content = $work ? trim((string)($work['content'] ?? '')) : '';
                if ($content !== '') {
                    return json([
                        'code' => 200,
                        'msg' => 'Success',
                        'data' => [
                            'task_id' => $taskId,
                            'type' => 'resource_content',
                            'text' => $content,
                            'payload_json' => '',
                            'created_at' => $work['updated_at'] ?? ($work['created_at'] ?? null),
                        ],
                    ]);
                }
                return json(['code' => 404, 'msg' => 'Not found', 'data' => null]);
            }
            return json([
                'code' => 200,
                'msg' => 'Success',
                'data' => [
                    'task_id' => $taskId,
                    'type' => $hitType,
                    'text' => $row['text'] ?? '',
                    'payload_json' => $row['payload_json'] ?? '',
                    'created_at' => $row['created_at'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Writing result failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }
}
