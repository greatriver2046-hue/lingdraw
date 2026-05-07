<?php
namespace app\controller\api;

use app\BaseController;
use think\Request;
use think\facade\Db;
use think\facade\Log;

class Style extends BaseController
{
    protected function seedStyles(): array
    {
        return [
            ['style_id' => 'default', 'name' => '默认'],
            ['style_id' => 'realistic', 'name' => '写实'],
            ['style_id' => 'anime', 'name' => '动漫'],
            ['style_id' => 'illustration', 'name' => '插画'],
            ['style_id' => 'cyberpunk', 'name' => '赛博朋克'],
            ['style_id' => 'watercolor', 'name' => '水彩'],
            ['style_id' => 'ink', 'name' => '国风水墨'],
        ];
    }

    protected function ensureTable()
    {
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `user_styles` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id` INT NOT NULL DEFAULT 0,
                `user_id` INT NOT NULL DEFAULT 0,
                `style_id` VARCHAR(50) NOT NULL,
                `name` VARCHAR(255) NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT 'normal, deleted',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_owner_style` (`tenant_id`, `user_id`, `style_id`),
                INDEX `idx_owner` (`tenant_id`, `user_id`),
                INDEX `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (\Throwable $e) {
        }
    }

    protected function ensureSeedStyles(array $owner)
    {
        try {
            $total = (int)Db::table('user_styles')
                ->where('tenant_id', $owner['tenant_id'])
                ->where('user_id', $owner['user_id'])
                ->count();
            if ($total > 0) return;

            $now = date('Y-m-d H:i:s');
            $rows = [];
            foreach ($this->seedStyles() as $s) {
                $sid = trim((string)($s['style_id'] ?? ''));
                if ($sid === '') continue;
                $rows[] = [
                    'tenant_id' => $owner['tenant_id'],
                    'user_id' => $owner['user_id'],
                    'style_id' => $sid,
                    'name' => (string)($s['name'] ?? $sid),
                    'status' => 'normal',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows) Db::table('user_styles')->insertAll($rows);
        } catch (\Throwable $e) {
        }
    }

    protected function owner(Request $request): array
    {
        $tenantId = $request->tenantId ?? null;
        $userId = $request->userId ?? null;
        return [
            'tenant_id' => $tenantId !== null ? (int)$tenantId : 0,
            'user_id' => $userId !== null ? (int)$userId : 0,
        ];
    }

    public function list(Request $request)
    {
        $this->ensureTable();

        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }

        $owner = $this->owner($request);
        try {
            $this->ensureSeedStyles($owner);

            $rows = Db::table('user_styles')
                ->where('tenant_id', $owner['tenant_id'])
                ->where('user_id', $owner['user_id'])
                ->where('status', 'normal')
                ->order('id', 'asc')
                ->select()
                ->toArray();

            $items = [];
            foreach ($rows as $r) {
                $sid = trim((string)($r['style_id'] ?? ''));
                if ($sid === '') continue;
                $items[] = [
                    'style_id' => $sid,
                    'name' => trim((string)($r['name'] ?? '')) ?: $sid,
                    'created_at' => $r['created_at'] ?? null,
                    'updated_at' => $r['updated_at'] ?? null,
                ];
            }

            return json(['code' => 200, 'msg' => 'Success', 'data' => ['items' => $items]]);
        } catch (\Throwable $e) {
            Log::error('Style list failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function create(Request $request)
    {
        $this->ensureTable();

        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }

        $name = trim((string)$request->post('name', ''));
        if ($name === '') {
            return json(['code' => 400, 'msg' => 'Missing name', 'data' => null]);
        }

        $owner = $this->owner($request);
        $now = date('Y-m-d H:i:s');
        try {
            $styleId = '';
            for ($i = 0; $i < 5; $i++) {
                $candidate = 'u' . bin2hex(random_bytes(8));
                $exists = Db::table('user_styles')
                    ->where('tenant_id', $owner['tenant_id'])
                    ->where('user_id', $owner['user_id'])
                    ->where('style_id', $candidate)
                    ->find();
                if (!$exists) {
                    $styleId = $candidate;
                    break;
                }
            }
            if ($styleId === '') {
                return json(['code' => 500, 'msg' => 'Create style failed', 'data' => null]);
            }

            Db::table('user_styles')->insert([
                'tenant_id' => $owner['tenant_id'],
                'user_id' => $owner['user_id'],
                'style_id' => $styleId,
                'name' => $name,
                'status' => 'normal',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return json(['code' => 200, 'msg' => 'Success', 'data' => ['item' => [
                'style_id' => $styleId,
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]]]);
        } catch (\Throwable $e) {
            Log::error('Style create failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function rename(Request $request)
    {
        $this->ensureTable();

        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }

        $styleId = trim((string)$request->post('style_id', ''));
        $name = trim((string)$request->post('name', ''));
        if ($styleId === '') {
            return json(['code' => 400, 'msg' => 'Missing style_id', 'data' => null]);
        }
        if ($name === '') {
            return json(['code' => 400, 'msg' => 'Missing name', 'data' => null]);
        }

        $owner = $this->owner($request);
        $now = date('Y-m-d H:i:s');
        try {
            $row = Db::table('user_styles')
                ->where('tenant_id', $owner['tenant_id'])
                ->where('user_id', $owner['user_id'])
                ->where('style_id', $styleId)
                ->find();

            if ($row) {
                Db::table('user_styles')->where('id', $row['id'])->update([
                    'name' => $name,
                    'status' => 'normal',
                    'updated_at' => $now,
                ]);
                $createdAt = $row['created_at'] ?? null;
            } else {
                Db::table('user_styles')->insert([
                    'tenant_id' => $owner['tenant_id'],
                    'user_id' => $owner['user_id'],
                    'style_id' => $styleId,
                    'name' => $name,
                    'status' => 'normal',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $createdAt = $now;
            }

            return json(['code' => 200, 'msg' => 'Success', 'data' => ['item' => [
                'style_id' => $styleId,
                'name' => $name,
                'created_at' => $createdAt,
                'updated_at' => $now,
            ]]]);
        } catch (\Throwable $e) {
            Log::error('Style rename failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function delete(Request $request)
    {
        $this->ensureTable();

        $userId = $request->userId ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
        }

        $styleId = trim((string)$request->post('style_id', ''));
        if ($styleId === '') {
            return json(['code' => 400, 'msg' => 'Missing style_id', 'data' => null]);
        }

        $owner = $this->owner($request);
        try {
            $spCount = 0;
            $resCount = 0;
            try {
                $spCount = (int)Db::table('style_profiles')
                    ->where('tenant_id', $owner['tenant_id'])
                    ->where('user_id', $owner['user_id'])
                    ->where('style_id', $styleId)
                    ->count();
            } catch (\Throwable $e) {
            }
            try {
                $resCount = (int)Db::table('resources')
                    ->where('tenant_id', $owner['tenant_id'])
                    ->where('user_id', $owner['user_id'])
                    ->where('style_id', $styleId)
                    ->where('status', 'normal')
                    ->count();
            } catch (\Throwable $e) {
            }

            if ($spCount > 0 || $resCount > 0) {
                return json(['code' => 400, 'msg' => 'Style not empty', 'data' => ['style_profiles' => $spCount, 'resources' => $resCount]]);
            }

            Db::table('user_styles')
                ->where('tenant_id', $owner['tenant_id'])
                ->where('user_id', $owner['user_id'])
                ->where('style_id', $styleId)
                ->update([
                    'status' => 'deleted',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return json(['code' => 200, 'msg' => 'Success', 'data' => ['style_id' => $styleId]]);
        } catch (\Throwable $e) {
            Log::error('Style delete failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }
}
