<?php
namespace app\controller\api;

use app\BaseController;
use think\Request;
use think\facade\Db;

class PoseHistory extends BaseController
{
    protected function ensureTable()
    {
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `pose_history` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id` INT NULL,
                `user_id` INT NULL,
                `thumbnail_url` VARCHAR(1024) NULL,
                `pose_json` LONGTEXT NULL,
                `last_used_at` DATETIME NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_user` (`user_id`),
                INDEX `idx_tenant` (`tenant_id`),
                INDEX `idx_last_used_at` (`last_used_at`),
                INDEX `idx_updated_at` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (\Throwable $e) {
        }

        try { Db::execute("ALTER TABLE `pose_history` ADD COLUMN `tenant_id` INT NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `pose_history` ADD COLUMN `user_id` INT NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `pose_history` ADD COLUMN `thumbnail_url` VARCHAR(1024) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `pose_history` ADD COLUMN `pose_json` LONGTEXT NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `pose_history` ADD COLUMN `last_used_at` DATETIME NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `pose_history` ADD COLUMN `created_at` DATETIME NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `pose_history` ADD COLUMN `updated_at` DATETIME NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `pose_history` ADD INDEX `idx_user` (`user_id`)"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `pose_history` ADD INDEX `idx_tenant` (`tenant_id`)"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `pose_history` ADD INDEX `idx_last_used_at` (`last_used_at`)"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `pose_history` ADD INDEX `idx_updated_at` (`updated_at`)"); } catch (\Throwable $e) {}
    }

    public function save(Request $request)
    {
        $this->ensureTable();
        $userId = $request->userId ?? null;
        $tenantId = $request->tenantId ?? null;

        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized']);
        }

        $poseJson = $request->post('pose_json');
        if ($poseJson === null || $poseJson === '') {
            return json(['code' => 400, 'msg' => 'Missing pose_json']);
        }
        if (is_array($poseJson) || is_object($poseJson)) {
            try {
                $poseJson = json_encode($poseJson, JSON_UNESCAPED_UNICODE);
            } catch (\Throwable $e) {
                $poseJson = '';
            }
        }
        $poseJson = (string)$poseJson;
        if (trim($poseJson) === '') {
            return json(['code' => 400, 'msg' => 'Invalid pose_json']);
        }

        $thumbnailUrl = trim((string)$request->post('thumbnail_url'));

        $now = date('Y-m-d H:i:s');
        $q = Db::table('pose_history')->where('user_id', $userId)->where('pose_json', $poseJson);
        if ($tenantId !== null) {
            $q = $q->where('tenant_id', $tenantId);
        }
        $existing = $q->order('id', 'desc')->find();

        if ($existing && isset($existing['id'])) {
            $update = ['updated_at' => $now];
            if ($thumbnailUrl !== '') $update['thumbnail_url'] = $thumbnailUrl;
            Db::table('pose_history')->where('id', (int)$existing['id'])->update($update);
            $id = (int)$existing['id'];
        } else {
            $id = Db::table('pose_history')->insertGetId([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'thumbnail_url' => $thumbnailUrl,
                'pose_json' => $poseJson,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        return json(['code' => 200, 'msg' => 'Success', 'data' => ['id' => $id]]);
    }

    public function updateLastUsed(Request $request)
    {
        $this->ensureTable();
        $userId = $request->userId ?? null;
        $tenantId = $request->tenantId ?? null;

        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized']);
        }

        $id = (int)$request->param('id', 0);
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => 'Invalid parameters']);
        }

        $now = date('Y-m-d H:i:s');
        $q = Db::table('pose_history')->where('id', $id)->where('user_id', $userId);
        if ($tenantId !== null) {
            $q = $q->where('tenant_id', $tenantId);
        }
        $rows = $q->update(['last_used_at' => $now, 'updated_at' => $now]);

        return json(['code' => 200, 'msg' => 'Success', 'data' => ['updated' => $rows > 0]]);
    }

    public function list(Request $request)
    {
        $this->ensureTable();
        $userId = $request->userId ?? null;
        $tenantId = $request->tenantId ?? null;

        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized']);
        }

        $page = (int)$request->param('page', 1);
        $pageSize = (int)$request->param('page_size', 20);
        if ($page <= 0) $page = 1;
        if ($pageSize <= 0) $pageSize = 20;
        if ($pageSize > 100) $pageSize = 100;

        $q = Db::table('pose_history')->where('user_id', $userId);
        if ($tenantId !== null) {
            $q = $q->where('tenant_id', $tenantId);
        }

        $rows = $q->order('last_used_at', 'desc')->order('updated_at', 'desc')->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $items = array_map(function ($row) {
            return [
                'id' => (int)($row['id'] ?? 0),
                'thumbnail_url' => (string)($row['thumbnail_url'] ?? ''),
                'pose_json' => (string)($row['pose_json'] ?? ''),
                'last_used_at' => (string)($row['last_used_at'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? '')
            ];
        }, $rows ?: []);

        $hasMore = count($items) >= $pageSize;

        return json(['code' => 200, 'msg' => 'Success', 'data' => [
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'has_more' => $hasMore
        ]]);
    }
}
