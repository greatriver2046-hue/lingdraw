<?php
namespace app\controller\api;

use app\BaseController;
use app\model\Conversation as ConversationModel;
use think\Request;
use think\facade\Db;
use think\facade\Log;

class Conversation extends BaseController
{
    protected function ensureTable()
    {
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `conversations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `conversation_id` CHAR(32) NULL,
                `tenant_id` INT NULL,
                `user_id` INT NULL,
                `title` VARCHAR(255) NULL,
                `cover_url` VARCHAR(1024) NULL,
                `cover_thumb_url` VARCHAR(1024) NULL,
                `status` VARCHAR(20) DEFAULT 'normal' COMMENT 'normal, hidden, deleted',
                `messages_json` LONGTEXT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_user` (`user_id`),
                UNIQUE KEY `uniq_conversation_id` (`conversation_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (\Throwable $e) {
            Log::error('Ensure conversations table failed: ' . $e->getMessage());
        }
        // Ensure new columns exist for legacy tables
        try { Db::execute("ALTER TABLE `conversations` ADD COLUMN `conversation_id` CHAR(32) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `conversations` ADD UNIQUE KEY `uniq_conversation_id` (`conversation_id`)"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `conversations` ADD COLUMN `cover_url` VARCHAR(1024) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `conversations` ADD COLUMN `cover_thumb_url` VARCHAR(1024) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `conversations` ADD COLUMN `status` VARCHAR(20) DEFAULT 'normal' COMMENT 'normal, hidden, deleted'"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `conversations` ADD COLUMN `canvas_json` LONGTEXT NULL"); } catch (\Throwable $e) {}
        $this->ensureThinkingTable();
    }

    protected function ensureThinkingTable()
    {
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `conversation_thinking_steps` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `conversation_id` CHAR(32) NOT NULL,
                `message_id` VARCHAR(64) NOT NULL,
                `tenant_id` INT NULL,
                `user_id` INT NULL,
                `thinking_action` VARCHAR(255) NULL,
                `thinking_steps_json` LONGTEXT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_conv_msg` (`conversation_id`, `message_id`),
                INDEX `idx_conv` (`conversation_id`),
                INDEX `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (\Throwable $e) {
            Log::error('Ensure conversation_thinking_steps table failed: ' . $e->getMessage());
        }
    }

    public function create(Request $request)
    {
        $this->ensureTable();
        $title = $request->post('title');
        $tenantId = $request->tenantId ?? null;
        $userId = $request->userId ?? null;
        $now = date('Y-m-d H:i:s');
        $uid = bin2hex(random_bytes(16));
        $initMs = (int)(microtime(true) * 1000);
        $initMsg = [
            'id' => $initMs,
            'role' => 'assistant',
            'content' => 'Hi，我是你的专属设计师，现在你可以告诉我你的需求，我会尽我所能帮你完成！',
            'timestamp' => $initMs
        ];
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'title' => $title ?: '新的对话',
            'conversation_id' => $uid,
            'status' => 'normal',
            'messages_json' => json_encode([$initMsg], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $conv = ConversationModel::create($data);
        return json(['code' => 200, 'msg' => 'Success', 'data' => ['conversation_id' => $uid, 'initial_message' => $initMsg]]);
    }

    public function list(Request $request)
    {
        $this->ensureTable();
        $tenantId = $request->tenantId ?? null;
        $userId = $request->userId ?? null;
        
        $page = (int)$request->param('page', 1);
        $pageSize = (int)$request->param('page_size', 20);
        
        $query = ConversationModel::order('id','desc');
        $query = $query->where('status', 'normal');
        if ($tenantId) $query = $query->where('tenant_id', $tenantId);
        if ($userId) $query = $query->where('user_id', $userId);
        
        $rows = $query->field(['id','conversation_id','title','cover_thumb_url','created_at','updated_at'])
            ->page($page, $pageSize)
            ->select();
        
        $items = [];
        foreach ($rows as $row) {
            if (empty($row['conversation_id'])) {
                try {
                    $newUid = bin2hex(random_bytes(16));
                    Db::table('conversations')->where('id', $row['id'])->update([
                        'conversation_id' => $newUid,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    $row['conversation_id'] = $newUid;
                } catch (\Throwable $e) { /* ignore */ }
            }

            $items[] = [
                'conversation_id' => isset($row['conversation_id']) ? $row['conversation_id'] : $row['id'],
                'title' => $row['title'],
                'cover_thumb_url' => isset($row['cover_thumb_url']) ? $row['cover_thumb_url'] : '',
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }
        $hasMore = count($rows) >= $pageSize;
        return json(['code' => 200, 'msg' => 'Success', 'data' => [
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'has_more' => $hasMore
        ]]);
    }

    public function delete(Request $request)
    {
        $this->ensureTable();
        $idParam = $request->post('id');
        $convIdParam = $request->post('conversation_id');
        $conv = null;
        if ($convIdParam && is_string($convIdParam) && strlen($convIdParam) >= 32) {
            $conv = ConversationModel::where('conversation_id', $convIdParam)->find();
        } elseif ($idParam) {
            $id = (int)$idParam;
            if ($id > 0) { $conv = ConversationModel::find($id); }
        }
        if (!$conv) {
            return json(['code' => 404, 'msg' => 'Conversation not found']);
        }
        $conv->status = 'deleted';
        $conv->updated_at = date('Y-m-d H:i:s');
        $conv->save();
        return json(['code' => 200, 'msg' => 'Success']);
    }

    public function messages(Request $request)
    {
        $this->ensureTable();
        $idParam = $request->get('id');
        $uidParam = $request->get('uid');
        $convIdParam = $request->get('conversation_id');
        $conv = null;
        if ($convIdParam && is_string($convIdParam) && strlen($convIdParam) >= 32) {
            $conv = ConversationModel::where('conversation_id', $convIdParam)->find();
        } elseif ($idParam && (!is_numeric($idParam) || strlen((string)$idParam) >= 32)) {
            $conv = ConversationModel::where('conversation_id', (string)$idParam)->find();
        } else {
            $id = (int)($idParam ?? 0);
            if ($id > 0) { $conv = ConversationModel::find($id); }
        }
        if (!$conv) {
            return json(['code' => 404, 'msg' => 'Conversation not found', 'data' => null]);
        }
        $status = isset($conv->status) ? $conv->status : 'normal';
        if ($status === 'deleted') {
             return json(['code' => 200, 'msg' => 'Conversation deleted', 'data' => ['is_deleted' => true]]);
        }
        $msgs = [];
        try {
            $msgs = $conv->messages_json ? json_decode($conv->messages_json, true) ?: [] : [];
        } catch (\Throwable $e) { $msgs = []; }
        
        $canvas = null;
        try {
            $canvas = $conv->canvas_json ? json_decode($conv->canvas_json, true) : null;
        } catch (\Throwable $e) { $canvas = null; }

        return json(['code' => 200, 'msg' => 'Success', 'data' => [
            'messages' => $msgs, 
            'title' => $conv->title,
            'canvas' => $canvas
        ]]);
    }

    public function saveCanvas(Request $request)
    {
        $this->ensureTable();
        $convIdParam = $request->post('conversation_id');
        $canvasData = $request->post('canvas_json'); // Expecting JSON string or array

        if (!$convIdParam) {
             return json(['code' => 400, 'msg' => 'Missing conversation_id']);
        }

        $conv = ConversationModel::where('conversation_id', $convIdParam)->find();
        if (!$conv) {
             return json(['code' => 404, 'msg' => 'Conversation not found']);
        }
        
        // If it's an array, encode it. If it's already a string, use it.
        $jsonStr = is_array($canvasData) ? json_encode($canvasData, JSON_UNESCAPED_UNICODE) : $canvasData;
        
        $conv->canvas_json = $jsonStr;
        $conv->updated_at = date('Y-m-d H:i:s');
        $conv->save();
        
        return json(['code' => 200, 'msg' => 'Success']);
    }

    public function updateCoverThumb(Request $request)
    {
        $this->ensureTable();
        $tenantId = $request->tenantId ?? null;
        $userId = $request->userId ?? null;

        $convIdParam = trim((string)$request->post('conversation_id', ''));
        $thumbUrl = trim((string)$request->post('cover_thumb_url', ''));

        if ($convIdParam === '' || $thumbUrl === '') {
            return json(['code' => 400, 'msg' => 'Missing conversation_id or cover_thumb_url', 'data' => null]);
        }

        $query = ConversationModel::where('conversation_id', $convIdParam)->where('status', 'normal');
        if ($tenantId) $query = $query->where('tenant_id', $tenantId);
        if ($userId) $query = $query->where('user_id', $userId);
        $conv = $query->find();

        if (!$conv) {
            return json(['code' => 404, 'msg' => 'Conversation not found', 'data' => null]);
        }

        $conv->cover_thumb_url = $thumbUrl;
        $conv->updated_at = date('Y-m-d H:i:s');
        $conv->save();

        return json(['code' => 200, 'msg' => 'Success', 'data' => ['conversation_id' => $convIdParam, 'cover_thumb_url' => $thumbUrl]]);
    }

    public function saveThinking(Request $request)
    {
        $this->ensureTable();
        $convId = $request->post('conversation_id');
        $messageId = $request->post('message_id');
        $action = $request->post('thinking_action');
        $steps = $request->post('thinking_steps');

        if (!$convId || !$messageId) {
            return json(['code' => 400, 'msg' => 'Missing conversation_id or message_id']);
        }

        $tenantId = $request->tenantId ?? null;
        $userId = $request->userId ?? null;

        $stepsArr = [];
        if (is_array($steps)) {
            $stepsArr = $steps;
        } elseif (is_string($steps) && trim($steps) !== '') {
            $decoded = json_decode($steps, true);
            if (is_array($decoded)) $stepsArr = $decoded;
        }

        $cleanSteps = [];
        foreach ($stepsArr as $s) {
            if (is_string($s)) {
                $t = trim($s);
                if ($t !== '') $cleanSteps[] = $t;
            } elseif (is_scalar($s)) {
                $t = trim((string)$s);
                if ($t !== '') $cleanSteps[] = $t;
            }
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'conversation_id' => (string)$convId,
            'message_id' => (string)$messageId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thinking_action' => is_string($action) ? mb_substr(trim($action), 0, 255, 'UTF-8') : null,
            'thinking_steps_json' => json_encode($cleanSteps, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];

        try {
            Db::table('conversation_thinking_steps')
                ->where('conversation_id', (string)$convId)
                ->where('message_id', (string)$messageId)
                ->update($row);
            $exists = Db::table('conversation_thinking_steps')
                ->where('conversation_id', (string)$convId)
                ->where('message_id', (string)$messageId)
                ->count();
            if (!$exists) {
                $row['created_at'] = $now;
                Db::table('conversation_thinking_steps')->insert($row);
            }
        } catch (\Throwable $e) {
            try {
                $row['created_at'] = $now;
                Db::table('conversation_thinking_steps')->insert($row);
            } catch (\Throwable $e2) {
                return json(['code' => 500, 'msg' => 'Failed to save thinking']);
            }
        }

        return json(['code' => 200, 'msg' => 'Success']);
    }

    public function getThinking(Request $request)
    {
        $this->ensureTable();
        $convId = $request->get('conversation_id');
        $messageId = $request->get('message_id');
        if (!$convId) {
            return json(['code' => 400, 'msg' => 'Missing conversation_id', 'data' => null]);
        }

        $tenantId = $request->tenantId ?? null;
        $userId = $request->userId ?? null;

        try {
            $q = Db::table('conversation_thinking_steps')->where('conversation_id', (string)$convId);
            if ($tenantId) $q = $q->where('tenant_id', $tenantId);
            if ($userId) $q = $q->where('user_id', $userId);
            if ($messageId) $q = $q->where('message_id', (string)$messageId);
            $rows = $q->order('id', 'asc')->select();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Failed to load thinking', 'data' => null]);
        }

        $out = [];
        foreach ($rows as $r) {
            $steps = [];
            try {
                $steps = isset($r['thinking_steps_json']) && $r['thinking_steps_json'] ? json_decode($r['thinking_steps_json'], true) ?: [] : [];
            } catch (\Throwable $e) { $steps = []; }

            $out[] = [
                'conversation_id' => $r['conversation_id'] ?? (string)$convId,
                'message_id' => $r['message_id'] ?? '',
                'thinking_action' => $r['thinking_action'] ?? '',
                'thinking_steps' => $steps,
                'created_at' => $r['created_at'] ?? null,
                'updated_at' => $r['updated_at'] ?? null,
            ];
        }

        return json(['code' => 200, 'msg' => 'Success', 'data' => ['items' => $out]]);
    }

    public function deleteThinking(Request $request)
    {
        $this->ensureTable();
        $convId = $request->post('conversation_id');
        $messageId = $request->post('message_id');
        if (!$convId || !$messageId) {
            return json(['code' => 400, 'msg' => 'Missing conversation_id or message_id']);
        }

        $tenantId = $request->tenantId ?? null;
        $userId = $request->userId ?? null;

        try {
            $q = Db::table('conversation_thinking_steps')
                ->where('conversation_id', (string)$convId)
                ->where('message_id', (string)$messageId);
            if ($tenantId) $q = $q->where('tenant_id', $tenantId);
            if ($userId) $q = $q->where('user_id', $userId);
            $affected = $q->delete();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Failed to delete thinking']);
        }

        if (!$affected) {
            return json(['code' => 404, 'msg' => 'Thinking not found']);
        }

        return json(['code' => 200, 'msg' => 'Success']);
    }

    public function append(Request $request)
    {
        $this->ensureTable();
        $idParam = $request->post('id');
        $uidParam = $request->post('uid');
        $convIdParam = $request->post('conversation_id');
        $role = $request->post('role');
        $content = $request->post('content');
        $timestamp = time();
        if ((!$idParam && !$uidParam) || !$role || !is_string($content)) {
            return json(['code' => 400, 'msg' => 'Invalid parameters', 'data' => null]);
        }
        $conv = null;
        if ($convIdParam && is_string($convIdParam) && strlen($convIdParam) >= 32) {
            $conv = ConversationModel::where('conversation_id', $convIdParam)->find();
        } elseif ($idParam && (!is_numeric($idParam) || strlen((string)$idParam) >= 32)) {
            $conv = ConversationModel::where('conversation_id', (string)$idParam)->find();
        } else {
            $id = (int)($idParam ?? 0);
            if ($id > 0) { $conv = ConversationModel::find($id); }
        }
        if (!$conv) {
            return json(['code' => 404, 'msg' => 'Conversation not found', 'data' => null]);
        }
        $msgs = [];
        try { $msgs = $conv->messages_json ? json_decode($conv->messages_json, true) ?: [] : []; } catch (\Throwable $e) { $msgs = []; }
        $msgs[] = [ 'id' => $timestamp, 'role' => $role, 'content' => $content, 'timestamp' => $timestamp ];
        $conv->messages_json = json_encode($msgs, JSON_UNESCAPED_UNICODE);
        $conv->updated_at = date('Y-m-d H:i:s');
        $conv->save();
        return json(['code' => 200, 'msg' => 'Success', 'data' => null]);
    }

    public function deleteMessage(Request $request)
    {
        $this->ensureTable();
        $convIdParam = $request->post('conversation_id');
        $msgIdParam = $request->post('message_id');
        if (!$convIdParam || !$msgIdParam) {
            return json(['code' => 400, 'msg' => 'Missing parameters']);
        }
        $conv = ConversationModel::where('conversation_id', $convIdParam)->find();
        if (!$conv) {
            return json(['code' => 404, 'msg' => 'Conversation not found']);
        }
        $msgs = [];
        try { $msgs = $conv->messages_json ? json_decode($conv->messages_json, true) ?: [] : []; } catch (\Throwable $e) { $msgs = []; }
        $targetId = (string)$msgIdParam;
        
        // Try to parse targetId as timestamp (ms) if it's numeric
        $targetTs = null;
        if (is_numeric($targetId) && strlen($targetId) >= 13) {
            $targetTs = (int)($targetId / 1000);
        }

        $out = [];
        $removed = false;
        foreach ($msgs as $m) {
            $mid = isset($m['id']) ? (string)$m['id'] : '';
            // 1. Exact ID match
            if ($mid === $targetId) { 
                $removed = true; 
                continue; 
            }
            // 2. Fallback: Timestamp match (for frontend temp IDs)
            if (!$removed && $targetTs && isset($m['timestamp'])) {
                $mTs = (int)$m['timestamp'];
                // Allow 120s variance to account for network latency, generation time, and clock skew
                if (abs($mTs - $targetTs) <= 120) {
                    $removed = true;
                    continue;
                }
            }
            
            $out[] = $m;
        }
        if (!$removed) {
            return json(['code' => 404, 'msg' => 'Message not found']);
        }
        $conv->messages_json = json_encode($out, JSON_UNESCAPED_UNICODE);
        $conv->updated_at = date('Y-m-d H:i:s');
        $conv->save();
        return json(['code' => 200, 'msg' => 'Success']);
    }

    public function clearMessages(Request $request)
    {
        $this->ensureTable();
        $convIdParam = $request->post('conversation_id');
        if (!$convIdParam) {
            return json(['code' => 400, 'msg' => 'Missing conversation_id']);
        }

        $conv = ConversationModel::where('conversation_id', $convIdParam)->find();
        if (!$conv) {
            return json(['code' => 404, 'msg' => 'Conversation not found']);
        }

        $nowMs = (int)(microtime(true) * 1000);
        $conv->messages_json = json_encode([
            [
                'id' => $nowMs,
                'role' => 'assistant',
                'content' => 'Hi，我是你的专属设计师，现在你可以告诉我你的需求，我会尽我所能帮你完成！',
                'timestamp' => $nowMs
            ]
        ], JSON_UNESCAPED_UNICODE);
        $conv->updated_at = date('Y-m-d H:i:s');
        $conv->save();

        try {
            Db::table('conversation_thinking_steps')->where('conversation_id', (string)$convIdParam)->delete();
        } catch (\Throwable $e) {}

        return json(['code' => 200, 'msg' => 'Success']);
    }
}
