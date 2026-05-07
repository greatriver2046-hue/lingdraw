<?php
namespace app\job;

use GuzzleHttp\Client;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\queue\Job;
use app\service\LlmService;
use app\service\ImageService;
use app\worker\Pusher;
use Symfony\Component\DomCrawler\Crawler;
use andreskrey\Readability\Configuration;
use andreskrey\Readability\ParseException;
use andreskrey\Readability\Readability;

class WritingTaskJob
{
    protected $wsUserId = 0;
    protected $wsTaskId = '';
    protected $wsWorkResourceId = '';
    protected $wsTenantId = null;
    protected $systemErrorLogDedup = [];
    protected $volcInlineReferenceText = '';

    protected function getSystemErrorLogColumns(): array
    {
        static $cols = null;
        if (is_array($cols)) return $cols;

        $map = [];
        try {
            $rows = Db::query("SHOW COLUMNS FROM `system_error_logs`");
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (!is_array($r)) continue;
                    $field = $r['Field'] ?? $r['field'] ?? null;
                    $field = is_string($field) ? trim($field) : '';
                    if ($field === '') continue;
                    $map[$field] = 1;
                }
            }
        } catch (\Throwable $e) {
        }

        $cols = $map;
        return $cols;
    }

    protected function writeSystemErrorLog(string $category, string $message, array $context = []): void
    {
        $cat = trim($category) !== '' ? trim($category) : 'llm';
        $msg = trim($message);
        if ($msg === '') return;

        $endpoint = isset($context['endpoint']) ? (string)$context['endpoint'] : '';
        $code = isset($context['code']) ? (string)$context['code'] : '';
        $source = isset($context['source']) ? (string)$context['source'] : '租户和用户api服务';
        $req = $context['request'] ?? null;
        $resp = $context['response'] ?? null;

        $reqStr = is_string($req) ? $req : json_encode($req, JSON_UNESCAPED_UNICODE);
        $respStr = is_string($resp) ? $resp : json_encode($resp, JSON_UNESCAPED_UNICODE);

        $dedupKey = md5($cat . '|' . $msg . '|' . $endpoint . '|' . $code . '|' . (string)$reqStr);
        if (isset($this->systemErrorLogDedup[$dedupKey])) return;
        $this->systemErrorLogDedup[$dedupKey] = 1;

        $payload = $context;
        $payload['endpoint'] = $endpoint;
        $payload['code'] = $code;
        $payload['source'] = $source;
        $payload['task_id'] = (string)$this->wsTaskId;
        $payload['work_resource_id'] = (string)$this->wsWorkResourceId;
        $payload['user_id'] = (int)$this->wsUserId;
        $payload['tenant_id'] = $this->wsTenantId !== null ? (int)$this->wsTenantId : null;

        $cols = $this->getSystemErrorLogColumns();
        if (!$cols) return;

        $row = [];
        if (isset($cols['tenant_id']) && $this->wsTenantId !== null) $row['tenant_id'] = (int)$this->wsTenantId;
        if (isset($cols['user_id']) && (int)$this->wsUserId > 0) $row['user_id'] = (int)$this->wsUserId;

        if (isset($cols['category'])) $row['category'] = $cat;
        if (isset($cols['message'])) $row['message'] = $msg;
        if (isset($cols['endpoint'])) $row['endpoint'] = $endpoint;
        if (isset($cols['code'])) $row['code'] = $code;
        if (isset($cols['source'])) $row['source'] = $source;
        if (isset($cols['context'])) $row['context'] = $code !== '' ? $code : $endpoint;
        if (isset($cols['request'])) $row['request'] = $reqStr ?: '';
        if (isset($cols['response'])) $row['response'] = $respStr ?: '';
        if (isset($cols['payload'])) $row['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (isset($cols['create_time'])) $row['create_time'] = time();
        if (isset($cols['created_at'])) $row['created_at'] = date('Y-m-d H:i:s');

        try {
            Db::table('system_error_logs')->insert($row);
        } catch (\Throwable $e) {
            try {
                Log::error('system_error_logs insert failed: ' . $e->getMessage());
            } catch (\Throwable $e2) {
            }
        }
    }

    protected function pushWritingTaskUpdate(array $taskStatus)
    {
        $uid = (int)$this->wsUserId;
        $tid = (string)$this->wsTaskId;
        if ($uid <= 0 || $tid === '') {
            return;
        }

        $payload = [
            'task_id' => $tid,
        ];
        if ((string)$this->wsWorkResourceId !== '') {
            $payload['work_resource_id'] = (string)$this->wsWorkResourceId;
        }
        if (isset($taskStatus['status'])) $payload['status'] = (string)$taskStatus['status'];
        if (isset($taskStatus['stage'])) $payload['stage'] = (string)$taskStatus['stage'];
        if (isset($taskStatus['progress'])) $payload['progress'] = $taskStatus['progress'];
        if (isset($taskStatus['error_message'])) $payload['error_message'] = (string)$taskStatus['error_message'];
        if (isset($taskStatus['preview'])) $payload['preview'] = $taskStatus['preview'];

        try {
            Pusher::pushWritingTaskUpdate($uid, $payload);
        } catch (\Throwable $e) {
        }
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

    protected function updateWorkResource(string $resourceId, int $userId, ?int $tenantId, ?string $title, ?string $content)
    {
        $rid = trim((string)$resourceId);
        if ($rid === '') return;

        $updates = ['updated_at' => date('Y-m-d H:i:s')];
        if (is_string($title) && trim($title) !== '') {
            $updates['title'] = trim($title);
        }
        if (is_string($content)) {
            $updates['content'] = $content;
        }

        if (count($updates) <= 1) return;

        try {
            $this->ensureResourcesTable();
            $q = Db::table('resources')->where('resource_id', $rid)->where('user_id', $userId)->where('status', 'normal');
            if ($tenantId !== null) $q = $q->where('tenant_id', $tenantId);
            $q->update($updates);
        } catch (\Throwable $e) {
        }
    }

    protected function loadNeutralDraftForResume(string $taskId, string $workResourceId, int $userId, ?int $tenantId): string
    {
        $tid = trim((string)$taskId);
        if ($tid !== '') {
            try {
                $row = Db::table('writing_artifacts')
                    ->where('task_id', $tid)
                    ->where('type', 'neutral_draft')
                    ->order('id', 'desc')
                    ->find();
                if ($row) {
                    $text = trim((string)($row['text'] ?? ''));
                    if ($text !== '') return $text;
                }
            } catch (\Throwable $e) {
            }
        }

        $rid = trim((string)$workResourceId);
        if ($rid === '') return '';
        try {
            $this->ensureResourcesTable();
            $q = Db::table('resources')
                ->where('resource_id', $rid)
                ->where('user_id', $userId)
                ->where('status', 'normal')
                ->order('id', 'desc');
            if ($tenantId !== null) $q = $q->where('tenant_id', $tenantId);
            $work = $q->find();
            if ($work) {
                $text = trim((string)($work['content'] ?? ''));
                if ($text !== '') return $text;
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    protected function updateWorkResourceTaskStatus(string $resourceId, int $userId, ?int $tenantId, string $taskId, array $taskStatus)
    {
        $rid = trim((string)$resourceId);
        if ($rid === '' || $userId <= 0) return;

        $now = date('Y-m-d H:i:s');
        $statusJson = json_encode($taskStatus, JSON_UNESCAPED_UNICODE);
        if (!is_string($statusJson)) $statusJson = null;

        try {
            $this->ensureResourcesTable();
            $q = Db::table('resources')
                ->where('resource_id', $rid)
                ->where('user_id', $userId)
                ->where('status', 'normal');
            if ($tenantId !== null) $q = $q->where('tenant_id', $tenantId);
            $q->update([
                'task_id' => $taskId !== '' ? $taskId : null,
                'task_status_json' => $statusJson,
                'updated_at' => $now,
            ]);
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
                if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
                if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
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
        $existing = Cache::get($taskKey);
        $merged = $data;
        if (is_array($existing)) {
            $merged = array_merge($existing, $data);
        }

        if ($redis) {
            try {
                $redis->hMSet($taskKey, $merged);
            } catch (\Throwable $e) {
            }
        }
        Cache::set($taskKey, $merged, 3600);
        $this->pushWritingTaskUpdate($merged);

        $uid = (int)$this->wsUserId;
        $tid = (string)$this->wsTaskId;
        $rid = (string)$this->wsWorkResourceId;
        if ($uid > 0 && $tid !== '' && $rid !== '') {
            $this->updateWorkResourceTaskStatus($rid, $uid, $this->wsTenantId !== null ? (int)$this->wsTenantId : null, $tid, $merged);
        }
    }

    protected function getTaskStatus($redis, string $taskKey): ?array
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

    protected function isCancelled($redis, string $taskId): bool
    {
        $key = 'writing_cancel:' . $taskId;
        if ($redis) {
            try {
                $v = $redis->get($key);
                if ($v) return true;
            } catch (\Throwable $e) {
            }
        }
        $v2 = Cache::get($key);
        return $v2 ? true : false;
    }

    protected function saveArtifact(string $taskId, string $type, ?array $payload, ?string $text, int $version = 1)
    {
        $now = date('Y-m-d H:i:s');
        try {
            Db::table('writing_artifacts')->insert([
                'task_id' => $taskId,
                'type' => $type,
                'version' => $version,
                'payload_json' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                'text' => $text,
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
        }
    }

    protected function updateTaskRow(string $taskId, array $updates)
    {
        try {
            $updates['updated_at'] = $updates['updated_at'] ?? date('Y-m-d H:i:s');
            Db::table('writing_tasks')->where('task_id', $taskId)->update($updates);
        } catch (\Throwable $e) {
        }
    }

    protected function extractJson(string $text)
    {
        $raw = trim($text);
        if ($raw === '') return null;
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $slice = substr($raw, $start, $end - $start + 1);
        $data2 = json_decode($slice, true);
        if (is_array($data2)) return $data2;
        return null;
    }

    protected function llmJson(int $userId, array $messages, array $options): ?array
    {
        $llm = new LlmService();
        $res = $llm->chat($messages, array_merge([
            'stream' => false,
            'temperature' => 0.2,
            'timeout' => 180,
            'connect_timeout' => 15,
            'retry' => 1,
            'retry_delay_ms' => 800,
            'usage_type' => 'writing',
        ], $options), $userId);
        $content = isset($res['content']) ? (string)$res['content'] : '';
        $parsed = $this->extractJson($content);
        return is_array($parsed) ? $parsed : null;
    }

    protected function llmText(int $userId, array $messages, array $options): string
    {
        $llm = new LlmService();
        $res = $llm->chat($messages, array_merge([
            'stream' => false,
            'temperature' => 0.5,
            'timeout' => 240,
            'connect_timeout' => 15,
            'retry' => 1,
            'retry_delay_ms' => 800,
            'usage_type' => 'writing',
        ], $options), $userId);
        return isset($res['content']) ? (string)$res['content'] : '';
    }

    protected function loadSystemPromptsRow(): ?array
    {
        try {
            $row = Db::table('system_prompts')->order('id', 'desc')->find();
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function pickDbPrompt(?array $row, string $field, string $default): string
    {
        if (is_array($row) && array_key_exists($field, $row)) {
            $v = is_string($row[$field]) ? trim($row[$field]) : trim((string)$row[$field]);
            if ($v !== '') return $v;
        }
        return $default;
    }

    protected function pickDbModelIdentity(?array $row, string $field, ?string $fallback): ?string
    {
        if (is_array($row) && array_key_exists($field, $row)) {
            $v = is_string($row[$field]) ? trim($row[$field]) : trim((string)$row[$field]);
            if ($v !== '') return $v;
        }
        $fb = is_string($fallback) ? trim($fallback) : trim((string)$fallback);
        return $fb !== '' ? $fb : null;
    }

    protected function renderPromptTemplate(string $template, array $vars): string
    {
        if ($template === '' || empty($vars)) return $template;
        foreach ($vars as $k => $v) {
            $template = str_replace('{{' . (string)$k . '}}', (string)$v, $template);
        }
        return $template;
    }

    protected function readStyleProfile(int $userId, ?int $tenantId, int $styleProfileId): ?array
    {
        try {
            $query = Db::table('style_profiles')->where('id', $styleProfileId)->where('user_id', $userId);
            if ($tenantId !== null) $query = $query->where('tenant_id', $tenantId);
            $row = $query->find();
            if (!$row) return null;
            $profileJson = (string)($row['profile_json'] ?? '');
            $parsed = null;
            if ($profileJson !== '') {
                $parsed = json_decode($profileJson, true);
                if (!is_array($parsed)) $parsed = null;
            }
            return [
                'id' => (int)$row['id'],
                'style_id' => (string)($row['style_id'] ?? ''),
                'input_hash' => (string)($row['input_hash'] ?? ''),
                'profile_json' => $profileJson,
                'profile' => $parsed,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function compileStyleRuntimeConfig(?array $profile): array
    {
        $compiled = [];
        if (is_array($profile)) {
            $compiled = isset($profile['compiled_constraints']) && is_array($profile['compiled_constraints'])
                ? $profile['compiled_constraints']
                : [];
        }
        $must = isset($compiled['must']) && is_array($compiled['must']) ? array_values($compiled['must']) : [];
        $mustNot = isset($compiled['must_not']) && is_array($compiled['must_not']) ? array_values($compiled['must_not']) : [];
        $templates = isset($compiled['templates']) && is_array($compiled['templates']) ? $compiled['templates'] : [];
        $knobs = isset($compiled['knobs']) && is_array($compiled['knobs']) ? $compiled['knobs'] : [];

        $lexicon = [];
        if (is_array($profile) && isset($profile['lexicon']) && is_array($profile['lexicon'])) {
            $lexicon = $profile['lexicon'];
        }
        $metricsBaseline = [];
        if (is_array($profile) && isset($profile['metrics_baseline']) && is_array($profile['metrics_baseline'])) {
            $metricsBaseline = $profile['metrics_baseline'];
        }
        $riskControls = [];
        if (is_array($profile) && isset($profile['risk_controls']) && is_array($profile['risk_controls'])) {
            $riskControls = $profile['risk_controls'];
        }
        $judgeChecklist = [];
        if (is_array($profile) && isset($profile['style_dimensions']) && is_array($profile['style_dimensions'])) {
            foreach ($profile['style_dimensions'] as $dim) {
                if (!is_array($dim)) continue;
                $name = trim((string)($dim['name'] ?? ''));
                $tendency = is_array($dim['author_tendency'] ?? null) ? trim((string)($dim['author_tendency']['description'] ?? '')) : '';
                if ($name !== '' || $tendency !== '') {
                    $judgeChecklist[] = trim($name . ($tendency !== '' ? ("：" . $tendency) : ''));
                }
            }
        }

        return [
            'must' => $must,
            'must_not' => $mustNot,
            'templates' => $templates,
            'knobs' => $knobs,
            'lexicon' => $lexicon,
            'metrics_baseline' => $metricsBaseline,
            'risk_controls' => $riskControls,
            'style_judge_checklist' => $judgeChecklist,
        ];
    }

    protected function topLexiconTexts(array $lexicon, string $key, int $limit = 8): array
    {
        if ($limit <= 0) return [];
        $items = isset($lexicon[$key]) && is_array($lexicon[$key]) ? $lexicon[$key] : [];
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $t = trim((string)($it['text'] ?? ''));
            if ($t === '') continue;
            $out[] = $t;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    protected function formatTemplatesSummary($templates): string
    {
        if (!is_array($templates) || !$templates) return '';
        $pick = function (string $k) use ($templates) {
            $arr = isset($templates[$k]) && is_array($templates[$k]) ? $templates[$k] : [];
            $out = [];
            foreach ($arr as $s) {
                $v = trim((string)$s);
                if ($v === '') continue;
                $out[] = $v;
                if (count($out) >= 2) break;
            }
            return $out;
        };
        $opening = $pick('opening');
        $transition = $pick('transition');
        $closing = $pick('closing');
        $list = $pick('list');
        $lines = [];
        if ($opening) $lines[] = "开头模板示例：" . implode(' / ', $opening);
        if ($transition) $lines[] = "过渡模板示例：" . implode(' / ', $transition);
        if ($list) $lines[] = "列表模板示例：" . implode(' / ', $list);
        if ($closing) $lines[] = "结尾模板示例：" . implode(' / ', $closing);
        return implode("\n", $lines);
    }

    protected function formatKnobsSummary($knobs): string
    {
        if (!is_array($knobs) || !$knobs) return '';
        $lines = [];
        foreach ($knobs as $k) {
            if (!is_array($k)) continue;
            $name = trim((string)($k['name'] ?? $k['id'] ?? ''));
            $id = trim((string)($k['id'] ?? ''));
            $min = isset($k['min']) ? (string)$k['min'] : '';
            $max = isset($k['max']) ? (string)$k['max'] : '';
            $def = isset($k['default']) ? (string)$k['default'] : '';
            $desc = trim((string)($k['description'] ?? ''));
            $head = $name !== '' ? $name : ($id !== '' ? $id : '');
            if ($head === '') continue;
            $range = ($min !== '' || $max !== '') ? "（{$min}~{$max}，默认 {$def}）" : ($def !== '' ? "（默认 {$def}）" : '');
            $tail = $desc !== '' ? "：{$desc}" : '';
            $lines[] = $head . $range . $tail;
            if (count($lines) >= 6) break;
        }
        return implode("\n", $lines);
    }

    protected function formatStyleRuntimeGuide(array $styleRuntime): string
    {
        $must = isset($styleRuntime['must']) && is_array($styleRuntime['must']) ? $styleRuntime['must'] : [];
        $mustNot = isset($styleRuntime['must_not']) && is_array($styleRuntime['must_not']) ? $styleRuntime['must_not'] : [];
        $templates = $styleRuntime['templates'] ?? [];
        $knobs = $styleRuntime['knobs'] ?? [];
        $lexicon = isset($styleRuntime['lexicon']) && is_array($styleRuntime['lexicon']) ? $styleRuntime['lexicon'] : [];
        $metrics = isset($styleRuntime['metrics_baseline']) && is_array($styleRuntime['metrics_baseline']) ? $styleRuntime['metrics_baseline'] : [];
        $risk = isset($styleRuntime['risk_controls']) && is_array($styleRuntime['risk_controls']) ? $styleRuntime['risk_controls'] : [];
        $checklist = isset($styleRuntime['style_judge_checklist']) && is_array($styleRuntime['style_judge_checklist']) ? $styleRuntime['style_judge_checklist'] : [];

        $signature = $this->topLexiconTexts($lexicon, 'signature_phrases', 10);
        $connectors = $this->topLexiconTexts($lexicon, 'preferred_connectors', 10);
        $taboo = $this->topLexiconTexts($lexicon, 'taboo_patterns', 8);
        $tpl = $this->formatTemplatesSummary($templates);
        $kb = $this->formatKnobsSummary($knobs);
        $riskRules = isset($risk['rewrite_required_rules']) && is_array($risk['rewrite_required_rules']) ? $risk['rewrite_required_rules'] : [];

        $lines = [];
        if ($must) {
            $lines[] = "必须：\n- " . implode("\n- ", array_map(fn($x) => trim((string)$x), array_slice($must, 0, 12)));
        }
        if ($mustNot) {
            $lines[] = "禁止：\n- " . implode("\n- ", array_map(fn($x) => trim((string)$x), array_slice($mustNot, 0, 12)));
        }
        if ($taboo) {
            $lines[] = "禁用表达（不要出现）： " . implode('、', $taboo);
        }
        if ($connectors) {
            $lines[] = "偏好连接词（优先使用）： " . implode('、', $connectors);
        }
        if ($signature) {
            $lines[] = "标志性口头禅（适度点缀）： " . implode('、', $signature);
        }
        $avgLen = isset($metrics['avg_sentence_length']) ? (float)$metrics['avg_sentence_length'] : null;
        $shortRatio = isset($metrics['short_sentence_ratio']) ? (float)$metrics['short_sentence_ratio'] : null;
        $qRatio = isset($metrics['question_ratio']) ? (float)$metrics['question_ratio'] : null;
        $listDensity = isset($metrics['list_density']) ? (float)$metrics['list_density'] : null;
        if ($avgLen !== null || $shortRatio !== null || $qRatio !== null || $listDensity !== null) {
            $parts = [];
            if ($avgLen !== null) $parts[] = "平均句长≈" . rtrim(rtrim(sprintf('%.2f', $avgLen), '0'), '.');
            if ($shortRatio !== null) $parts[] = "短句占比≈" . rtrim(rtrim(sprintf('%.2f', $shortRatio), '0'), '.');
            if ($qRatio !== null) $parts[] = "反问占比≈" . rtrim(rtrim(sprintf('%.2f', $qRatio), '0'), '.');
            if ($listDensity !== null) $parts[] = "列表密度≈" . rtrim(rtrim(sprintf('%.2f', $listDensity), '0'), '.');
            if ($parts) $lines[] = "目标节奏（尽量贴近）： " . implode('，', $parts);
        }
        if ($tpl !== '') {
            $lines[] = $tpl;
        }
        if ($kb !== '') {
            $lines[] = "风格旋钮（按默认值执行）：\n" . $kb;
        }
        if ($riskRules) {
            $lines[] = "风控改写原则：\n- " . implode("\n- ", array_map(fn($x) => trim((string)$x), array_slice($riskRules, 0, 8)));
        }
        if ($checklist) {
            $lines[] = "风格评审清单（写完自检）：\n- " . implode("\n- ", array_map(fn($x) => trim((string)$x), array_slice($checklist, 0, 10)));
        }
        return implode("\n\n", $lines);
    }

    protected function isPrivateOrReservedIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return true;
        $long = ip2long($ip);
        if ($long === false) return true;
        $ranges = [
            ['0.0.0.0', '0.255.255.255'],
            ['10.0.0.0', '10.255.255.255'],
            ['100.64.0.0', '100.127.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.0.0.0', '192.0.0.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['198.18.0.0', '198.19.255.255'],
            ['224.0.0.0', '239.255.255.255'],
            ['240.0.0.0', '255.255.255.255'],
        ];
        foreach ($ranges as $r) {
            $start = ip2long($r[0]);
            $end = ip2long($r[1]);
            if ($start !== false && $end !== false && $long >= $start && $long <= $end) return true;
        }
        return false;
    }

    protected function isPrivateOrReservedIpv6(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return true;
        $lower = strtolower($ip);
        if ($lower === '::1') return true;
        if (strpos($lower, 'fe80:') === 0) return true;
        if (strpos($lower, 'fc') === 0 || strpos($lower, 'fd') === 0) return true;
        if (strpos($lower, '::') === 0) return true;
        return false;
    }

    protected function isAllowedRemoteUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (!is_array($parsed)) return false;

        $scheme = strtolower((string)($parsed['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) return false;
        if (isset($parsed['user']) || isset($parsed['pass'])) return false;

        $host = (string)($parsed['host'] ?? '');
        if ($host === '') return false;

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !$this->isPrivateOrReservedIp($host);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return !$this->isPrivateOrReservedIpv6($host);
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (!is_array($records) || count($records) === 0) return false;

        foreach ($records as $r) {
            $ip = $r['ip'] ?? ($r['ipv6'] ?? null);
            if (!$ip) continue;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $this->isPrivateOrReservedIp($ip)) return false;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $this->isPrivateOrReservedIpv6($ip)) return false;
        }

        return true;
    }

    protected function normalizeText(string $text): string
    {
        $t = preg_replace('/\r\n?/', "\n", $text);
        $t = preg_replace("/[ \t]+/u", ' ', $t);
        $t = preg_replace("/\n{3,}/u", "\n\n", $t);
        return trim((string)$t);
    }

    protected function removeNoiseLines(string $text): string
    {
        $lines = preg_split("/\n/u", $text);
        if (!is_array($lines)) return $text;
        $out = [];
        foreach ($lines as $line) {
            $l = trim((string)$line);
            if ($l === '') continue;
            if (preg_match('/^\s*(cookie|cookies|隐私|privacy|免责声明|免责声明|登录|注册|下载|打开app)\b/iu', $l)) continue;
            $out[] = $l;
        }
        return $this->normalizeText(implode("\n", $out));
    }

    protected function stripUrlsFromMarkdown(string $text): string
    {
        $t = (string)$text;
        $t = preg_replace('~\[((?:[^\\\]]|\\.)+)\]\(\s*https?:\/\/[^\s)]+\s*\)~iu', '$1', $t);
        $t = preg_replace('~<\s*https?:\/\/[^\s>]+\s*>~iu', '', $t);
        $t = preg_replace('~\(\s*https?:\/\/[^\s)]+\s*\)~iu', '', $t);
        $t = preg_replace('~（\s*https?:\/\/[^\s）]+\s*）~iu', '', $t);
        $t = preg_replace('~https?:\/\/[^\s\)\]\}]+~iu', '', $t);
        $t = preg_replace('~\(\s*\)~u', '', $t);
        $t = preg_replace('~（\s*）~u', '', $t);
        $t = preg_replace("/[ \t]+/u", ' ', $t);
        return trim((string)$t);
    }

    protected function buildCitationSources(array $fetchedSources): array
    {
        $out = [];
        $seen = [];
        $i = 0;
        foreach ($fetchedSources as $s) {
            if (!is_array($s)) continue;
            $url = trim((string)($s['url'] ?? ''));
            if ($url === '') continue;
            $key = strtolower($url);
            if (isset($seen[$key])) continue;
            $seen[$key] = 1;
            $i++;
            $out[] = [
                'id' => $i,
                'title' => trim((string)($s['title'] ?? '')),
                'url' => $url,
            ];
            if ($i >= 20) break;
        }
        return $out;
    }

    protected function buildRawExcerptPool(array $fetchedSources, array $urlToIdMap): array
    {
        $out = [];
        $seen = [];
        foreach ($fetchedSources as $s) {
            if (!is_array($s)) continue;
            $url = trim((string)($s['url'] ?? ''));
            if ($url === '') continue;
            $sid = (int)($urlToIdMap[strtolower($url)] ?? 0);
            if ($sid <= 0) continue;
            if (isset($seen[$sid])) continue;
            $seen[$sid] = 1;

            $title = trim((string)($s['title'] ?? ''));
            $content = trim((string)($s['content_excerpt'] ?? ''));
            $content = $content !== '' ? $this->stripUrlsFromMarkdown($content) : '';
            if ($content === '') continue;

            $paras = $this->splitParagraphs($content);
            $candidates = [];
            foreach ($paras as $idx => $p) {
                $pp = trim((string)$p);
                $len = $pp !== '' ? mb_strlen($pp, 'UTF-8') : 0;
                if ($len < 120) continue;
                $candidates[] = ['idx' => (int)$idx, 'len' => (int)$len, 'text' => $pp];
            }
            if (!$candidates) continue;

            usort($candidates, function ($a, $b) {
                $la = (int)($a['len'] ?? 0);
                $lb = (int)($b['len'] ?? 0);
                if ($la === $lb) return (int)($a['idx'] ?? 0) <=> (int)($b['idx'] ?? 0);
                return $lb <=> $la;
            });
            $picked = array_slice($candidates, 0, 4);
            usort($picked, function ($a, $b) {
                return (int)($a['idx'] ?? 0) <=> (int)($b['idx'] ?? 0);
            });

            $excerpts = [];
            foreach ($picked as $it) {
                $txt = trim((string)($it['text'] ?? ''));
                if ($txt === '') continue;
                $excerpts[] = $this->chunkText($txt, 900);
            }
            if (!$excerpts) continue;

            $out[] = [
                'source_id' => $sid,
                'title' => $title,
                'excerpts' => $excerpts,
            ];
            if (count($out) >= 20) break;
        }

        return $out;
    }

    protected function isFactualWritingGenre(string $genre): bool
    {
        $g = trim(mb_strtolower($genre, 'UTF-8'));
        if ($g === '') return true;
        $keywords = [
            '科普', '新闻', '报道', '评论', '社论', '解读', '分析', '研报', '报告', '白皮书',
            '论文', '综述', '研究', '教程', '指南', '说明', '攻略', '评测', '测评', '对比',
            '政策', '标准', '行业', '市场', '数据', '统计', '价格', '版本',
        ];
        foreach ($keywords as $k) {
            if (mb_strpos($g, mb_strtolower($k, 'UTF-8')) !== false) return true;
        }
        return false;
    }

    protected function replaceFactPackSourceUrlsWithIds(array $factPack, array $urlToIdMap): array
    {
        $walk = function ($v) use (&$walk, $urlToIdMap) {
            if (is_string($v)) {
                if (stripos($v, 'http://') !== false || stripos($v, 'https://') !== false || stripos($v, 'www.') !== false) {
                    return $this->stripUrlsFromMarkdown($v);
                }
                return $v;
            }
            if (!is_array($v)) return $v;
            $out = [];
            $pendingIds = [];
            foreach ($v as $k => $vv) {
                if ($k === 'source_url') {
                    $u = trim((string)$vv);
                    $id = $u !== '' ? ($urlToIdMap[strtolower($u)] ?? null) : null;
                    if (is_int($id) && $id > 0) $pendingIds[$id] = 1;
                    continue;
                }
                if ($k === 'source_id') {
                    $id = is_numeric($vv) ? (int)$vv : 0;
                    if ($id > 0) $pendingIds[$id] = 1;
                    continue;
                }
                $out[$k] = $walk($vv);
            }

            if (isset($out['source_ids']) && is_array($out['source_ids'])) {
                foreach ($out['source_ids'] as $idv) {
                    $id = is_numeric($idv) ? (int)$idv : 0;
                    if ($id > 0) $pendingIds[$id] = 1;
                }
            }

            if (!empty($pendingIds)) {
                $ids = array_keys($pendingIds);
                sort($ids);
                $out['source_ids'] = $ids;
            } else {
                if (isset($out['source_ids'])) unset($out['source_ids']);
            }

            return $out;
        };
        return $walk($factPack);
    }

    protected function extractTitleFromHtml(string $html): string
    {
        $crawler = new Crawler();
        try {
            $crawler->addHtmlContent($html, 'UTF-8');
        } catch (\Throwable $e) {
            return '';
        }
        $t = '';
        try {
            $titleNode = $crawler->filter('title');
            if ($titleNode->count() > 0) $t = trim((string)$titleNode->text(''));
        } catch (\Throwable $e) {
        }
        if ($t !== '') return $t;
        try {
            $h1 = $crawler->filter('h1');
            if ($h1->count() > 0) return trim((string)$h1->text(''));
        } catch (\Throwable $e) {
        }
        return '';
    }

    protected function htmlToCleanText(string $html): string
    {
        $wrapped = '<!doctype html><html><head><meta charset="utf-8"></head><body><div id="__content__">' . (string)$html . '</div></body></html>';
        $crawler = new Crawler();
        try {
            $crawler->addHtmlContent($wrapped, 'UTF-8');
        } catch (\Throwable $e) {
            $plain = strip_tags((string)$html);
            $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return $this->removeNoiseLines($this->normalizeText($plain));
        }

        $root = $crawler->filter('#__content__');
        if ($root->count() === 0) {
            $plain = strip_tags((string)$html);
            $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return $this->removeNoiseLines($this->normalizeText($plain));
        }

        $root->filter('script,style,noscript,iframe,form,button,input,select,textarea,svg,canvas')->each(function (Crawler $n) {
            foreach ($n as $node) {
                if ($node instanceof \DOMNode && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        });

        $blocks = $root->filter('h1,h2,h3,h4,p,li,blockquote,pre');
        if ($blocks->count() === 0) {
            $plain = $root->text('', true);
            return $this->removeNoiseLines($this->normalizeText($plain));
        }

        $buf = [];
        foreach ($blocks as $node) {
            try {
                $txt = trim($node->textContent ?? '');
            } catch (\Throwable $e) {
                $txt = '';
            }
            if ($txt === '') continue;
            $buf[] = $txt;
        }

        return $this->removeNoiseLines($this->normalizeText(implode("\n", $buf)));
    }

    protected function fetchHtml(string $url): array
    {
        $trimmed = trim((string)$url);
        if ($trimmed === '') return ['html' => null, 'url' => null, 'status' => null, 'error' => 'empty_url'];
        if (!preg_match('/^(https?:\/\/)/i', $trimmed)) $trimmed = 'https://' . $trimmed;
        if (!$this->isAllowedRemoteUrl($trimmed)) return ['html' => null, 'url' => null, 'status' => null, 'error' => 'url_not_allowed'];

        $effectiveUrl = null;
        $client = new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; AIsaasBot/1.0)',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            ],
        ]);

        try {
            $res = $client->request('GET', $trimmed, [
                'http_errors' => false,
                'allow_redirects' => ['max' => 5, 'track_redirects' => true],
                'on_stats' => function ($stats) use (&$effectiveUrl) {
                    $effectiveUrl = (string)$stats->getEffectiveUri();
                },
            ]);
        } catch (\Throwable $e) {
            return ['html' => null, 'url' => null, 'status' => null, 'error' => $e->getMessage()];
        }

        $finalUrl = $effectiveUrl ?: $trimmed;
        if (!$this->isAllowedRemoteUrl($finalUrl)) return ['html' => null, 'url' => null, 'status' => null, 'error' => 'final_url_not_allowed'];

        $status = (int)$res->getStatusCode();
        if ($status < 200 || $status >= 300) return ['html' => null, 'url' => $finalUrl, 'status' => $status, 'error' => 'http_status'];

        $contentType = strtolower((string)$res->getHeaderLine('Content-Type'));
        if ($contentType !== '' && strpos($contentType, 'text/html') === false && strpos($contentType, 'application/xhtml') === false) {
            return ['html' => null, 'url' => $finalUrl, 'status' => $status, 'error' => 'content_type'];
        }

        $body = (string)$res->getBody();
        if ($body === '') return ['html' => null, 'url' => $finalUrl, 'status' => $status, 'error' => 'empty_body'];
        return ['html' => $body, 'url' => $finalUrl, 'status' => $status, 'error' => null];
    }

    protected function scrapeReadable(string $url): array
    {
        $cfg2 = $this->getWebSearchConfig();
        $apiKey2 = isset($cfg2['api_key']) ? trim((string)$cfg2['api_key']) : '';
        if ($apiKey2 !== '') {
            $apiKey2 = $this->normalizeZhipuApiKey($apiKey2);
            $endpoint2 = isset($cfg2['web_reader_endpoint']) ? trim((string)$cfg2['web_reader_endpoint']) : '';
            if ($endpoint2 === '') $endpoint2 = 'https://open.bigmodel.cn/api/mcp/web_reader/mcp';
            $z = $this->zhipuWebRead($url, $apiKey2, $endpoint2);
            if (is_array($z)) {
                $finalUrl = (string)($z['url'] ?? $url);
                $images = [];
                $fetched = $this->fetchHtml($finalUrl);
                $html = $fetched['html'] ?? null;
                $htmlUrl = (string)($fetched['url'] ?? $finalUrl);
                if (is_string($html) && $html !== '') {
                    $images = $this->extractImagesFromReadableHtml($html, $htmlUrl);
                }
                return [
                    'title' => (string)($z['title'] ?? ''),
                    'content' => (string)($z['content'] ?? ''),
                    'url' => $finalUrl,
                    'images' => $images,
                ];
            }
        }

        $fetched = $this->fetchHtml($url);
        $html = $fetched['html'] ?? null;
        $finalUrl = (string)($fetched['url'] ?? $url);
        if (!$html) return ['title' => '', 'content' => '', 'url' => $finalUrl];

        $cfg = new Configuration();
        if (method_exists($cfg, 'setFixRelativeURLs')) $cfg->setFixRelativeURLs(true);
        if (method_exists($cfg, 'setOriginalURL')) $cfg->setOriginalURL($finalUrl);
        if (method_exists($cfg, 'setSummonCthulhu')) $cfg->setSummonCthulhu(true);

        $readability = new Readability($cfg);
        try {
            $readability->parse($html);
        } catch (ParseException $e) {
            $images = $this->extractImagesFromHtml($html, $finalUrl);
            $fallbackTitle = $this->extractTitleFromHtml($html);
            $fallbackContent = $this->htmlToCleanText($html);
            return ['title' => $fallbackTitle, 'content' => $fallbackContent, 'url' => $finalUrl, 'images' => $images];
        } catch (\Throwable $e) {
            $images = $this->extractImagesFromHtml($html, $finalUrl);
            return ['title' => '', 'content' => '', 'url' => $finalUrl, 'images' => $images];
        }

        $title = trim((string)$readability->getTitle());
        $contentHtml = (string)$readability->getContent();
        $images = $this->extractImagesFromHtml($contentHtml !== '' ? $contentHtml : $html, $finalUrl);
        $content = $this->htmlToCleanText($contentHtml);
        if ($content === '') {
            $content = $this->htmlToCleanText($html);
        }
        if ($title === '') $title = $this->extractTitleFromHtml($html);

        return ['title' => $title, 'content' => $content, 'url' => $finalUrl, 'images' => $images];
    }

    protected function extractImagesFromReadableHtml(string $html, string $baseUrl): array
    {
        $baseUrl = trim((string)$baseUrl);
        if ($html === '' || $baseUrl === '') return [];
        if (!$this->isAllowedRemoteUrl($baseUrl)) return [];

        $cfg = new Configuration();
        if (method_exists($cfg, 'setFixRelativeURLs')) $cfg->setFixRelativeURLs(true);
        if (method_exists($cfg, 'setOriginalURL')) $cfg->setOriginalURL($baseUrl);
        if (method_exists($cfg, 'setSummonCthulhu')) $cfg->setSummonCthulhu(true);

        $readability = new Readability($cfg);
        try {
            $readability->parse($html);
            $contentHtml = (string)$readability->getContent();
            if ($contentHtml !== '') {
                return $this->extractImagesFromHtml($contentHtml, $baseUrl);
            }
        } catch (\Throwable $e) {
        }

        return $this->extractImagesFromHtml($html, $baseUrl);
    }

    protected function extractImagesFromHtml(string $html, string $baseUrl): array
    {
        $baseUrl = trim((string)$baseUrl);
        if ($html === '' || $baseUrl === '') return [];
        if (!$this->isAllowedRemoteUrl($baseUrl)) return [];

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (\Throwable $e) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            return [];
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $candidates = [];

        $parsePx = function (string $raw): int {
            $s = trim((string)$raw);
            if ($s === '') return 0;
            if (is_numeric($s)) return (int)$s;
            if (preg_match('/^(\d{1,5})\s*px$/i', $s, $m)) return (int)$m[1];
            return 0;
        };

        $body = $doc->getElementsByTagName('body')->item(0);
        $imgs = $body ? $body->getElementsByTagName('img') : $doc->getElementsByTagName('img');
        $imgAttrKeys = ['src', 'data-src', 'data-original', 'data-url', 'data-lazy-src', 'data-lazy', 'data-actualsrc'];
        foreach ($imgs as $img) {
            if (!($img instanceof \DOMElement)) continue;
            $w = $parsePx((string)$img->getAttribute('width'));
            $h = $parsePx((string)$img->getAttribute('height'));
            if ($w <= 0) $w = $parsePx((string)$img->getAttribute('data-width'));
            if ($h <= 0) $h = $parsePx((string)$img->getAttribute('data-height'));

            $style = (string)$img->getAttribute('style');
            if (($w <= 0 || $h <= 0) && $style !== '') {
                if ($w <= 0 && preg_match('/\bwidth\s*:\s*(\d{1,5})\s*px\b/i', $style, $m)) $w = (int)$m[1];
                if ($h <= 0 && preg_match('/\bheight\s*:\s*(\d{1,5})\s*px\b/i', $style, $m)) $h = (int)$m[1];
            }
            if (($w > 0 && $w <= 80) || ($h > 0 && $h <= 80)) continue;

            $cls = strtolower(trim((string)$img->getAttribute('class')));
            $id = strtolower(trim((string)$img->getAttribute('id')));
            $alt = strtolower(trim((string)$img->getAttribute('alt')));
            $hint = $cls . ' ' . $id . ' ' . $alt;
            if ($hint !== '' && preg_match('/\b(logo|icon|avatar|sprite|favicon|qrcode)\b/i', $hint)) continue;

            foreach ($imgAttrKeys as $k) {
                $v = trim((string)$img->getAttribute($k));
                if ($v !== '') {
                    if (preg_match('/\b(sprite|favicon|icon|logo|avatar|placeholder|spacer|pixel)\b/i', $v)) continue;
                    $candidates[] = $v;
                    break;
                }
            }
        }

        $unique = [];
        $out = [];
        foreach ($candidates as $cand) {
            $abs = $this->resolveUrl($baseUrl, $cand);
            if ($abs === '') continue;
            if (!$this->isAllowedRemoteUrl($abs)) continue;

            $key = strtolower($this->stripUrlFragment($abs));
            if ($key === '' || isset($unique[$key])) continue;

            $path = (string)(parse_url($abs, PHP_URL_PATH) ?? '');
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext !== '' && !in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp'], true)) {
                continue;
            }

            if (preg_match('/(sprite|favicon|icon|logo)/i', $abs)) continue;

            $unique[$key] = true;
            $out[] = $abs;
            if (count($out) >= 30) break;
        }
        return $out;
    }

    protected function pickImageExt(string $url, string $contentType, ?string $binary = null): ?string
    {
        $ct = strtolower(trim((string)$contentType));
        if ($ct !== '' && strpos($ct, 'image/') !== 0) return null;

        if (strpos($ct, 'image/jpeg') !== false) return 'jpg';
        if (strpos($ct, 'image/png') !== false) return 'png';
        if (strpos($ct, 'image/webp') !== false) return 'webp';
        if (strpos($ct, 'image/gif') !== false) return 'gif';
        if (strpos($ct, 'image/avif') !== false) return 'avif';
        if (strpos($ct, 'image/bmp') !== false) return 'bmp';

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        if ($ext !== '' && in_array($ext, ['jpg', 'png', 'webp', 'gif', 'avif', 'bmp'], true)) return $ext;

        if ($binary !== null && $binary !== '') {
            $info = @getimagesizefromstring($binary);
            $mime = is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';
            if ($mime === 'image/jpeg') return 'jpg';
            if ($mime === 'image/png') return 'png';
            if ($mime === 'image/webp') return 'webp';
            if ($mime === 'image/gif') return 'gif';
            if ($mime === 'image/avif') return 'avif';
            if ($mime === 'image/bmp') return 'bmp';
        }

        return null;
    }

    protected function isLikelyNonContentImageUrl(string $url): bool
    {
        $u = trim((string)$url);
        if ($u === '') return true;
        if (preg_match('/\b(sprite|favicon|icon|logo|avatar|placeholder|spacer|pixel|qrcode)\b/i', $u)) return true;
        if (preg_match('/\b1x1\b/i', $u)) return true;
        if (preg_match('/\b(\d{1,3})x(\d{1,3})\b/i', $u, $m)) {
            $w = (int)($m[1] ?? 0);
            $h = (int)($m[2] ?? 0);
            if (($w > 0 && $w <= 80) || ($h > 0 && $h <= 80)) return true;
        }
        return false;
    }

    protected function storeRemoteImageUrlResult(Client $client, ImageService $imageService, string $imageUrl, string $refererUrl, string $prefix): array
    {
        $u = trim((string)$imageUrl);
        if ($u === '' || !$this->isAllowedRemoteUrl($u)) {
            return ['stored_url' => null, 'rejected' => true];
        }

        try {
            $headers = [
                'Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8',
                'User-Agent' => 'Mozilla/5.0 (compatible; AIsaasBot/1.0)',
            ];
            $ref = trim((string)$refererUrl);
            if ($ref !== '') $headers['Referer'] = $ref;

            $resp = $client->request('GET', $u, [
                'headers' => $headers,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            return ['stored_url' => null, 'rejected' => false];
        }

        $code = (int)$resp->getStatusCode();
        if ($code < 200 || $code >= 300) return ['stored_url' => null, 'rejected' => false];

        $contentType = strtolower(trim((string)$resp->getHeaderLine('Content-Type')));
        if ($contentType !== '' && strpos($contentType, 'image/') !== 0) return ['stored_url' => null, 'rejected' => true];

        $lenH = trim((string)$resp->getHeaderLine('Content-Length'));
        $len = is_numeric($lenH) ? (int)$lenH : 0;
        if ($len > 12 * 1024 * 1024) return ['stored_url' => null, 'rejected' => false];

        $binary = (string)$resp->getBody();
        if ($binary === '') return ['stored_url' => null, 'rejected' => false];
        if (strlen($binary) > 12 * 1024 * 1024) return ['stored_url' => null, 'rejected' => false];

        $info = @getimagesizefromstring($binary);
        if (is_array($info)) {
            $w = (int)($info[0] ?? 0);
            $h = (int)($info[1] ?? 0);
            if (($w > 0 && $w <= 80) || ($h > 0 && $h <= 80)) return ['stored_url' => null, 'rejected' => true];
        }

        $ext = $this->pickImageExt($u, $contentType, $binary);
        if ($ext === null) return ['stored_url' => null, 'rejected' => true];

        try {
            return ['stored_url' => $imageService->storeBinary($binary, $ext, $prefix), 'rejected' => false];
        } catch (\Throwable $e) {
            return ['stored_url' => null, 'rejected' => false];
        }
    }

    protected function stripUrlFragment(string $url): string
    {
        $s = trim((string)$url);
        if ($s === '') return '';
        $pos = strpos($s, '#');
        if ($pos === false) return $s;
        return substr($s, 0, $pos);
    }

    protected function resolveUrl(string $baseUrl, string $maybeUrl): string
    {
        $baseUrl = trim((string)$baseUrl);
        $maybeUrl = trim((string)$maybeUrl);
        if ($baseUrl === '' || $maybeUrl === '') return '';
        if (stripos($maybeUrl, 'data:') === 0) return '';
        if (stripos($maybeUrl, 'javascript:') === 0) return '';

        if (preg_match('/^https?:\/\//i', $maybeUrl)) return $maybeUrl;
        if (strpos($maybeUrl, '//') === 0) {
            $scheme = (string)(parse_url($baseUrl, PHP_URL_SCHEME) ?? 'https');
            if ($scheme !== 'http' && $scheme !== 'https') $scheme = 'https';
            return $scheme . ':' . $maybeUrl;
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts)) return '';
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if ($scheme !== 'http' && $scheme !== 'https') return '';
        if ($host === '') return '';

        $origin = $scheme . '://' . $host . ($port ? ':' . $port : '');
        if (strpos($maybeUrl, '/') === 0) {
            return $origin . $this->normalizePath($maybeUrl);
        }

        $basePath = (string)($parts['path'] ?? '/');
        if ($basePath === '') $basePath = '/';
        $dir = rtrim(substr($basePath, 0, strrpos($basePath, '/') !== false ? strrpos($basePath, '/') + 1 : 1), '/');
        $prefix = $dir === '' ? '/' : ($dir . '/');

        return $origin . $this->normalizePath($prefix . $maybeUrl);
    }

    protected function normalizePath(string $path): string
    {
        $path = (string)$path;
        if ($path === '') return '/';
        $segments = explode('/', $path);
        $out = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return '/' . implode('/', $out);
    }

    protected function zhipuWebRead(string $url, string $apiKey, string $endpoint): ?array
    {
        $trimmed = trim((string)$url);
        if ($trimmed === '') return null;
        if (!preg_match('/^(https?:\/\/)/i', $trimmed)) $trimmed = 'https://' . $trimmed;
        if (!$this->isAllowedRemoteUrl($trimmed)) return null;

        $client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify' => false,
        ]);

        try {
            $resp = $client->post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ],
                'json' => [
                    'url' => $trimmed,
                ],
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        $json = json_decode((string)$resp->getBody(), true);
        if (!is_array($json)) return null;

        $rr = null;
        if (isset($json['reader_result']) && is_array($json['reader_result'])) $rr = $json['reader_result'];
        if ($rr === null && isset($json['data']) && is_array($json['data'])) $rr = $json['data'];
        if ($rr === null) $rr = $json;

        if (!is_array($rr)) return null;
        $title = trim((string)($rr['title'] ?? $rr['site_title'] ?? $rr['site_name'] ?? ''));
        $content = (string)($rr['content'] ?? $rr['text'] ?? $rr['markdown'] ?? '');
        $finalUrl = (string)($rr['url'] ?? $rr['final_url'] ?? $rr['link'] ?? $trimmed);
        if ($finalUrl !== '' && !preg_match('/^(https?:\/\/)/i', $finalUrl)) $finalUrl = $trimmed;
        if ($finalUrl !== '' && !$this->isAllowedRemoteUrl($finalUrl)) $finalUrl = $trimmed;

        $content = $this->removeNoiseLines($this->normalizeText($content));
        if ($content === '') return null;
        return ['title' => $title, 'content' => $content, 'url' => $finalUrl];
    }

    protected function normalizeZhipuApiKey(string $apiKey, int $expireSeconds = 300): string
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') return '';
        if (strpos($apiKey, '.') === false) return $apiKey;
        if (strpos($apiKey, 'sk-') === 0) return $apiKey;

        $parts = explode('.', $apiKey);
        if (count($parts) !== 2) return $apiKey;
        $id = $parts[0];
        $secret = $parts[1];
        if ($id === '' || $secret === '') return $apiKey;

        $now = time() * 1000;
        $payload = [
            'api_key' => $id,
            'exp' => $now + ($expireSeconds * 1000),
            'timestamp' => $now,
        ];
        $header = [
            'alg' => 'HS256',
            'sign_type' => 'SIGN',
        ];

        $base64UrlEncode = function ($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        $base64UrlHeader = $base64UrlEncode(json_encode($header));
        $base64UrlPayload = $base64UrlEncode(json_encode($payload));
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = $base64UrlEncode($signature);
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    protected function getWebSearchConfig(): array
    {
        $cfg = [];
        try {
            $raw = Db::table('system_configs')->where('category', 'web_search')->value('config');
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $cfg = $decoded;
            }
        } catch (\Throwable $e) {}

        if (!$cfg) {
            try {
                $raw2 = Db::table('system_configs')->where('category', 'default_models')->value('config');
                if ($raw2) {
                    $decoded2 = json_decode($raw2, true);
                    if (is_array($decoded2) && isset($decoded2['web_search']) && is_array($decoded2['web_search'])) {
                        $cfg = $decoded2['web_search'];
                    }
                }
            } catch (\Throwable $e) {}
        }

        return $cfg ?: [];
    }

    protected function webSearchZhipu(string $query, int $userId): array
    {
        $q = trim($query);
        if ($q === '') return [];

        $cfg = $this->getWebSearchConfig();
        $apiKey = isset($cfg['api_key']) ? trim((string)$cfg['api_key']) : '';
        if ($apiKey === '') {
            $safeCfg = $cfg;
            if (isset($safeCfg['api_key'])) $safeCfg['api_key'] = '';
            $this->writeSystemErrorLog('llm', '联网搜索未配置 api_key', [
                'endpoint' => (string)($cfg['endpoint'] ?? ''),
                'code' => 'zhipu_web_search_missing_api_key',
                'request' => ['query' => $q, 'task_id' => (string)$this->wsTaskId, 'work_resource_id' => (string)$this->wsWorkResourceId, 'user_id' => (int)$this->wsUserId],
                'response' => ['config' => $safeCfg],
            ]);
            return [];
        }
        $apiKey = $this->normalizeZhipuApiKey($apiKey);

        $endpoint = isset($cfg['endpoint']) ? trim((string)$cfg['endpoint']) : '';
        if ($endpoint === '') $endpoint = 'https://open.bigmodel.cn/api/paas/v4/web_search';

        $count = isset($cfg['count']) ? (int)$cfg['count'] : 10;
        $count = max(1, min(50, $count));

        $body = [
            'search_query' => $q,
            'search_engine' => (string)($cfg['search_engine'] ?? 'search_std'),
            'search_intent' => (bool)($cfg['search_intent'] ?? false),
            'count' => $count,
            'search_recency_filter' => (string)($cfg['search_recency_filter'] ?? 'noLimit'),
            'content_size' => (string)($cfg['content_size'] ?? 'medium'),
            'user_id' => 'user_' . $userId,
        ];

        $domain = trim((string)($cfg['search_domain_filter'] ?? ''));
        if ($domain !== '') $body['search_domain_filter'] = $domain;

        $client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify' => false,
        ]);

        try {
            $resp = $client->post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ],
                'json' => $body,
            ]);
            $status = (int)$resp->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->writeSystemErrorLog('llm', '联网搜索接口返回非 2xx', [
                    'endpoint' => $endpoint,
                    'code' => 'zhipu_web_search_http_status',
                    'request' => ['query' => $q, 'task_id' => (string)$this->wsTaskId, 'work_resource_id' => (string)$this->wsWorkResourceId, 'user_id' => (int)$this->wsUserId],
                    'response' => ['status' => $status],
                ]);
                return [];
            }
            $rawBody = $resp->getBody()->getContents();
            $json = json_decode($rawBody, true);
            if (!is_array($json)) {
                $this->writeSystemErrorLog('llm', '联网搜索接口响应无法解析为 JSON', [
                    'endpoint' => $endpoint,
                    'code' => 'zhipu_web_search_bad_json',
                    'request' => ['query' => $q, 'task_id' => (string)$this->wsTaskId, 'work_resource_id' => (string)$this->wsWorkResourceId, 'user_id' => (int)$this->wsUserId],
                    'response' => ['raw' => $rawBody],
                ]);
                return [];
            }

            $items = [];
            $results = $json['search_result'] ?? null;
            if (is_array($results)) {
                foreach ($results as $r) {
                    if (!is_array($r)) continue;
                    $url = trim((string)($r['link'] ?? ''));
                    $title = trim((string)($r['title'] ?? ''));
                    if ($url === '') continue;
                    $items[] = ['url' => $url, 'title' => $title];
                    if (count($items) >= 8) break;
                }
            }
            return $items;
        } catch (\Throwable $e) {
            $this->writeSystemErrorLog('llm', '联网搜索接口请求失败', [
                'endpoint' => $endpoint,
                'code' => 'zhipu_web_search_exception',
                'request' => ['query' => $q, 'task_id' => (string)$this->wsTaskId, 'work_resource_id' => (string)$this->wsWorkResourceId, 'user_id' => (int)$this->wsUserId],
                'response' => ['error' => $e->getMessage()],
            ]);
            return [];
        }
    }

    protected function webSearchSearxng(string $query): array
    {
        $q = trim($query);
        if ($q === '') return [];

        $cfg = $this->getWebSearchConfig();
        $endpoint = trim((string)($cfg['searxng_endpoint'] ?? ''));
        if ($endpoint === '') {
            $this->writeSystemErrorLog('llm', 'SearXNG 未配置 endpoint', [
                'endpoint' => '',
                'code' => 'searxng_missing_endpoint',
                'request' => ['query' => $q],
                'response' => [],
            ]);
            return [];
        }

        $apiKey = trim((string)($cfg['searxng_api_key'] ?? ''));
        $engines = trim((string)($cfg['searxng_engines'] ?? ''));
        $count = (int)($cfg['searxng_result_count'] ?? 10);
        $count = max(1, min(50, $count));

        $queryParams = [
            'q' => $q,
            'format' => 'json',
        ];
        if ($engines !== '') $queryParams['engines'] = $engines;

        $headers = [
            'Accept' => 'application/json',
        ];
        if ($apiKey !== '') $headers['X-API-Key'] = $apiKey;

        $client = new Client([
            'timeout' => 25,
            'connect_timeout' => 10,
            'verify' => false,
        ]);

        try {
            $resp = $client->get($endpoint, [
                'headers' => $headers,
                'query' => $queryParams,
            ]);
            $status = (int)$resp->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->writeSystemErrorLog('llm', 'SearXNG 搜索接口返回非 2xx', [
                    'endpoint' => $endpoint,
                    'code' => 'searxng_http_status',
                    'request' => ['query' => $q, 'engines' => $engines, 'count' => $count],
                    'response' => ['status' => $status],
                ]);
                return [];
            }

            $rawBody = $resp->getBody()->getContents();
            $json = json_decode($rawBody, true);
            if (!is_array($json)) {
                $this->writeSystemErrorLog('llm', 'SearXNG 搜索接口响应无法解析为 JSON', [
                    'endpoint' => $endpoint,
                    'code' => 'searxng_bad_json',
                    'request' => ['query' => $q],
                    'response' => ['raw' => $rawBody],
                ]);
                return [];
            }

            $items = [];
            $results = $json['results'] ?? null;
            if (is_array($results)) {
                foreach ($results as $r) {
                    if (!is_array($r)) continue;
                    $url = trim((string)($r['url'] ?? ''));
                    $title = trim((string)($r['title'] ?? ''));
                    if ($url === '') continue;
                    $items[] = ['url' => $url, 'title' => $title];
                    if (count($items) >= 8) break;
                }
            }

            if (!$items) {
                $this->writeSystemErrorLog('llm', 'SearXNG 返回无结果', [
                    'endpoint' => $endpoint,
                    'code' => 'searxng_no_results',
                    'request' => ['query' => $q, 'engines' => $engines, 'count' => $count],
                    'response' => ['keys' => array_keys($json)],
                ]);
            }

            return $items;
        } catch (\Throwable $e) {
            $this->writeSystemErrorLog('llm', 'SearXNG 搜索接口请求失败', [
                'endpoint' => $endpoint,
                'code' => 'searxng_exception',
                'request' => ['query' => $q, 'engines' => $engines, 'count' => $count],
                'response' => ['error' => $e->getMessage()],
            ]);
            return [];
        }
    }

    protected function webSearchVolcengine(string $query): array
    {
        $q = trim($query);
        if ($q === '') return [];

        $cfg = $this->getWebSearchConfig();
        $apiKey = trim((string)($cfg['volc_api_key'] ?? ''));
        $endpoint = trim((string)($cfg['volc_endpoint'] ?? ''));
        $model = trim((string)($cfg['volc_model'] ?? ''));

        if ($apiKey === '' || $model === '') {
            $this->writeSystemErrorLog('llm', '火山引擎 Web Search 配置缺失', [
                'endpoint' => $endpoint,
                'code' => 'volc_web_search_missing_config',
                'request' => ['query' => $q],
                'response' => ['has_api_key' => $apiKey !== '', 'has_model' => $model !== ''],
            ]);
            return [];
        }
        if ($endpoint === '') $endpoint = 'https://ark.cn-beijing.volces.com/api/v3/responses';
        $endpoint = preg_replace('/\s+/u', '', $endpoint);
        $endpointLower = strtolower($endpoint);
        if (strpos($endpointLower, '/chat/completions') !== false) {
            $endpoint = preg_replace('#/chat/completions$#i', '/responses', $endpoint);
        } elseif (preg_match('#/api/v3$#i', $endpoint)) {
            $endpoint = rtrim($endpoint, '/') . '/responses';
        } elseif (!preg_match('#/responses$#i', $endpoint)) {
            $endpoint = rtrim($endpoint, '/') . '/responses';
        }

        $client = new Client([
            'timeout' => 30,
            'connect_timeout' => 12,
            'verify' => false,
        ]);

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        $maxKeyword = (int)($cfg['volc_max_keyword'] ?? ($cfg['max_keyword'] ?? 2));
        $maxKeyword = max(1, min(8, $maxKeyword));

        $body = [
            'model' => $model,
            'stream' => false,
            'thinking' => [
                'type' => 'disabled',
            ],
            'tools' => [
                [
                    'type' => 'web_search',
                    'max_keyword' => $maxKeyword,
                ]
            ],
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $q]
                    ],
                ]
            ],
        ];

        try {
            $resp = $client->post($endpoint, [
                'headers' => $headers,
                'json' => $body,
            ]);
            $status = (int)$resp->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->writeSystemErrorLog('llm', '火山引擎 Web Search 接口返回非 2xx', [
                    'endpoint' => $endpoint,
                    'code' => 'volc_web_search_http_status',
                    'request' => ['query' => $q],
                    'response' => ['status' => $status],
                ]);
                return [];
            }

            $rawBody = $resp->getBody()->getContents();
            $json = json_decode($rawBody, true);
            if (!is_array($json)) {
                $this->writeSystemErrorLog('llm', '火山引擎 Web Search 响应无法解析为 JSON', [
                    'endpoint' => $endpoint,
                    'code' => 'volc_web_search_bad_json',
                    'request' => ['query' => $q],
                    'response' => ['raw' => $rawBody],
                ]);
                return [];
            }

            $inline = $this->extractVolcengineInlineReferenceText($json);
            if ($inline !== '') {
                if ($this->volcInlineReferenceText === '') {
                    $this->volcInlineReferenceText = $inline;
                } elseif (mb_strpos($this->volcInlineReferenceText, $inline, 0, 'UTF-8') === false) {
                    $this->volcInlineReferenceText .= "\n\n" . $inline;
                }
                if (mb_strlen($this->volcInlineReferenceText, 'UTF-8') > 12000) {
                    $this->volcInlineReferenceText = mb_substr($this->volcInlineReferenceText, 0, 12000, 'UTF-8');
                }
            }

            $items = $this->extractVolcengineWebSearchResults($json);
            if (count($items) > 8) $items = array_slice($items, 0, 8);

            if (!$items) {
                $err = null;
                if (isset($json['error']) && is_array($json['error'])) $err = $json['error'];
                $outputTypes = [];
                if (isset($json['output']) && is_array($json['output'])) {
                    foreach ($json['output'] as $o) {
                        if (!is_array($o)) continue;
                        $t = isset($o['type']) ? trim((string)$o['type']) : '';
                        if ($t !== '') $outputTypes[] = $t;
                    }
                }
                $output0 = null;
                if (isset($json['output'][0]) && is_array($json['output'][0])) {
                    $output0 = [
                        'type' => (string)($json['output'][0]['type'] ?? ''),
                        'keys' => array_keys($json['output'][0]),
                    ];
                }
                $this->writeSystemErrorLog('llm', '火山引擎 Web Search 返回无结果', [
                    'endpoint' => $endpoint,
                    'code' => 'volc_web_search_no_results',
                    'request' => ['query' => $q],
                    'response' => [
                        'keys' => array_keys($json),
                        'error' => $err,
                        'output_types' => array_values(array_unique($outputTypes)),
                        'output0' => $output0,
                    ],
                ]);
            }

            return $items;
        } catch (\Throwable $e) {
            $this->writeSystemErrorLog('llm', '火山引擎 Web Search 请求失败', [
                'endpoint' => $endpoint,
                'code' => 'volc_web_search_exception',
                'request' => ['query' => $q],
                'response' => ['error' => $e->getMessage()],
            ]);
            return [];
        }
    }

    protected function extractVolcengineWebSearchResults($json): array
    {
        if (!is_array($json)) return [];

        $items = [];
        $push = function ($url, $title = '') use (&$items) {
            $u = trim((string)$url);
            if ($u === '' || !preg_match('/^https?:\/\//i', $u)) return;
            $items[] = ['url' => $u, 'title' => trim((string)$title)];
        };

        if (isset($json['output']) && is_array($json['output'])) {
            foreach ($json['output'] as $o) {
                if (!is_array($o)) continue;
                if (($o['type'] ?? '') !== 'message') continue;
                $content = $o['content'] ?? null;
                if (!is_array($content)) continue;
                foreach ($content as $c) {
                    if (!is_array($c)) continue;
                    $ann = $c['annotations'] ?? null;
                    if (!is_array($ann)) continue;
                    foreach ($ann as $a) {
                        if (!is_array($a)) continue;
                        $url = $a['url'] ?? $a['link'] ?? $a['source_url'] ?? $a['sourceUrl'] ?? null;
                        $title = $a['title'] ?? $a['name'] ?? $a['source_title'] ?? $a['sourceTitle'] ?? '';
                        if ($url) $push($url, $title);
                    }
                }
            }
        }

        $walk = function ($node) use (&$walk, $push) {
            if (!is_array($node)) return;

            if (isset($node['results']) && is_array($node['results'])) {
                foreach ($node['results'] as $r) {
                    if (!is_array($r)) continue;
                    $url = $r['url'] ?? $r['link'] ?? $r['source_url'] ?? $r['sourceUrl'] ?? null;
                    $title = $r['title'] ?? $r['name'] ?? $r['source_title'] ?? $r['sourceTitle'] ?? '';
                    if ($url) $push($url, $title);
                }
            }

            if (isset($node['citations']) && is_array($node['citations'])) {
                foreach ($node['citations'] as $c) {
                    if (!is_array($c)) continue;
                    $url = $c['url'] ?? $c['link'] ?? $c['source_url'] ?? $c['sourceUrl'] ?? null;
                    $title = $c['title'] ?? $c['name'] ?? $c['source_title'] ?? $c['sourceTitle'] ?? '';
                    if ($url) $push($url, $title);
                }
            }

            if (isset($node['annotations']) && is_array($node['annotations'])) {
                foreach ($node['annotations'] as $a) {
                    if (!is_array($a)) continue;
                    $url = $a['url'] ?? $a['link'] ?? $a['source_url'] ?? $a['sourceUrl'] ?? null;
                    $title = $a['title'] ?? $a['name'] ?? $a['source_title'] ?? $a['sourceTitle'] ?? '';
                    if ($url) $push($url, $title);
                }
            }

            $maybeUrl = $node['url'] ?? $node['link'] ?? null;
            $maybeTitle = $node['title'] ?? $node['name'] ?? null;
            if ($maybeUrl && $maybeTitle && is_string($maybeTitle)) {
                $push($maybeUrl, $maybeTitle);
            }

            foreach ($node as $v) {
                if (is_array($v)) $walk($v);
            }
        };

        $walk($json);

        if (!$items) {
            $texts = [];
            $collectText = function ($node) use (&$collectText, &$texts) {
                if (count($texts) >= 12) return;
                if (!is_array($node)) return;
                foreach (['text', 'content', 'message'] as $k) {
                    if (isset($node[$k]) && is_string($node[$k])) {
                        $t = trim((string)$node[$k]);
                        if ($t !== '') $texts[] = $t;
                    }
                }
                foreach ($node as $v) {
                    if (is_array($v)) $collectText($v);
                }
            };
            $collectText($json);
            $raw = trim(implode("\n", $texts));
            if ($raw !== '' && preg_match_all('/https?:\/\/[^\s\)\]\}<>"]+/i', $raw, $m)) {
                foreach ($m[0] as $u) $push($u, '');
            }
        }

        if (!$items) return [];

        $dedup = [];
        $out = [];
        foreach ($items as $it) {
            $u = trim((string)($it['url'] ?? ''));
            if ($u === '') continue;
            $k = strtolower($u);
            if (isset($dedup[$k])) continue;
            $dedup[$k] = 1;
            $out[] = ['url' => $u, 'title' => (string)($it['title'] ?? '')];
            if (count($out) >= 50) break;
        }
        return $out;
    }

    protected function extractVolcengineInlineReferenceText($json): string
    {
        if (!is_array($json)) return '';
        $texts = [];
        if (isset($json['output']) && is_array($json['output'])) {
            foreach ($json['output'] as $o) {
                if (!is_array($o)) continue;
                if (($o['type'] ?? '') !== 'message') continue;
                $content = $o['content'] ?? null;
                if (!is_array($content)) continue;
                foreach ($content as $c) {
                    if (!is_array($c)) continue;
                    $type = (string)($c['type'] ?? '');
                    if ($type !== 'output_text' && $type !== 'text') continue;
                    $t = trim((string)($c['text'] ?? ''));
                    if ($t === '') continue;
                    $texts[] = $t;
                    if (count($texts) >= 3) break 2;
                }
            }
        }
        $joined = trim(implode("\n\n", $texts));
        return $joined !== '' ? $joined : '';
    }

    protected function webSearch(string $query, int $userId): array
    {
        $q = trim($query);
        if ($q === '') return [];

        $cfg = $this->getWebSearchConfig();
        $provider = trim((string)($cfg['search_provider_toggle'] ?? ''));
        if ($provider === '' || $provider === 'current') $provider = 'zhipu';

        if ($provider === 'searxng') return $this->webSearchSearxng($q);
        if ($provider === 'volcengine') {
            $items = $this->webSearchVolcengine($q);
            if (!empty($items)) return $items;
            $this->writeSystemErrorLog('llm', '火山引擎 Web Search 返回无结果，切换到智谱搜索', [
                'endpoint' => (string)($cfg['volc_endpoint'] ?? ''),
                'code' => 'volc_web_search_fallback_zhipu',
                'request' => ['query' => $q, 'task_id' => (string)$this->wsTaskId, 'work_resource_id' => (string)$this->wsWorkResourceId, 'user_id' => (int)$this->wsUserId],
                'response' => [],
            ]);
            return $this->webSearchZhipu($q, $userId);
        }
        return $this->webSearchZhipu($q, $userId);
    }

    protected function chunkText(string $text, int $maxLen): string
    {
        $t = trim($text);
        if ($t === '') return '';
        if (mb_strlen($t, 'UTF-8') <= $maxLen) return $t;
        return mb_substr($t, 0, $maxLen, 'UTF-8');
    }

    protected function splitParagraphs(string $text): array
    {
        $t = trim($text);
        if ($t === '') return [];
        $parts = preg_split("/\n{2,}/u", $t);
        if (!is_array($parts)) return [$t];
        $out = [];
        foreach ($parts as $p) {
            $pp = trim((string)$p);
            if ($pp === '') continue;
            $out[] = $pp;
        }
        return $out;
    }

    protected function assessResearchCoverage(array $fetched, int $wordCount, int $minSources): array
    {
        $nonEmptyCount = 0;
        $totalChars = 0;
        $perSourceChars = [];
        foreach ($fetched as $it) {
            if (!is_array($it)) continue;
            $content = trim((string)($it['content_excerpt'] ?? ''));
            if ($content === '') continue;
            $nonEmptyCount++;
            $len = mb_strlen($content, 'UTF-8');
            $totalChars += $len;
            $perSourceChars[] = $len;
        }
        sort($perSourceChars);
        $avgChars = $nonEmptyCount > 0 ? (int)round($totalChars / max(1, $nonEmptyCount)) : 0;
        $p50 = $perSourceChars ? $perSourceChars[(int)floor((count($perSourceChars) - 1) * 0.5)] : 0;
        $p20 = $perSourceChars ? $perSourceChars[(int)floor((count($perSourceChars) - 1) * 0.2)] : 0;

        $minSourceCount = (int)$minSources;
        if ($minSourceCount <= 0) $minSourceCount = 5;
        $minSourceCount = max(3, min(12, $minSourceCount));

        $minTotalChars = 1200;
        if ($wordCount > 0) {
            $minTotalChars = (int)round($wordCount * 1.1);
            $minTotalChars = max(1200, min(18000, $minTotalChars));
        }

        $reasons = [];
        if ($nonEmptyCount < $minSourceCount) $reasons[] = '有效资料条数不足';
        if ($totalChars < $minTotalChars) $reasons[] = '有效资料总量不足';
        if ($p20 > 0 && $p20 < 180) $reasons[] = '多数资料片段过短';

        $ok = ($nonEmptyCount >= $minSourceCount) && ($totalChars >= $minTotalChars);

        return [
            'ok' => $ok,
            'non_empty_sources' => $nonEmptyCount,
            'min_sources' => $minSourceCount,
            'total_chars' => $totalChars,
            'min_total_chars' => $minTotalChars,
            'avg_chars_per_source' => $avgChars,
            'p50_chars' => $p50,
            'p20_chars' => $p20,
            'reasons' => $reasons,
        ];
    }

    protected function buildSupplementalSearchQueries(string $topic, ?array $needAssess, ?array $queryPlan, array $fetched, int $wordCount): array
    {
        $existing = [];
        if (is_array($queryPlan)) {
            $sq = $queryPlan['search_queries'] ?? null;
            if (is_array($sq)) {
                foreach ($sq as $q) {
                    if (!is_array($q)) continue;
                    $qq = trim((string)($q['query'] ?? ''));
                    if ($qq !== '') $existing[$qq] = 1;
                    $alts = $q['query_alt'] ?? null;
                    if (is_array($alts)) {
                        foreach ($alts as $a) {
                            $aa = trim((string)$a);
                            if ($aa !== '') $existing[$aa] = 1;
                        }
                    }
                }
            }
        }

        $push = function (string $q) use (&$existing) {
            $qq = trim($q);
            if ($qq === '') return null;
            if (mb_strlen($qq, 'UTF-8') > 80) $qq = mb_substr($qq, 0, 80, 'UTF-8');
            if (isset($existing[$qq])) return null;
            $existing[$qq] = 1;
            return $qq;
        };

        $out = [];
        $base = trim($topic);
        if ($base === '') return [];

        if (is_array($needAssess)) {
            $unknowns = $needAssess['unknowns'] ?? null;
            if (is_array($unknowns)) {
                foreach ($unknowns as $u) {
                    $uu = trim((string)$u);
                    if ($uu === '') continue;
                    $q = $push($base . ' ' . $uu);
                    if ($q !== null) $out[] = $q;
                    if (count($out) >= 8) return $out;
                }
            }

            $types = $needAssess['required_evidence_types'] ?? null;
            if (is_array($types)) {
                foreach ($types as $t) {
                    $tt = trim((string)$t);
                    if ($tt === '') continue;
                    $q = $push($base . ' ' . $tt);
                    if ($q !== null) $out[] = $q;
                    if (count($out) >= 8) return $out;
                }
            }
        }

        $seed = ['数据', '统计', '案例', '定义', '报告', '白皮书', '研究', '指南', '标准', '政策', '价格', '对比', '优缺点', '原理', '框架', '最佳实践'];
        foreach ($seed as $s) {
            $q = $push($base . ' ' . $s);
            if ($q !== null) $out[] = $q;
            if (count($out) >= 8) return $out;
        }

        if ($wordCount >= 2500) {
            $more = ['行业', '市场规模', '趋势', '风险', '落地', '实施'];
            foreach ($more as $s2) {
                $q = $push($base . ' ' . $s2);
                if ($q !== null) $out[] = $q;
                if (count($out) >= 8) return $out;
            }
        }

        return $out;
    }

    protected function ngramSet(string $text, int $n = 5): array
    {
        $t = preg_replace('/\s+/u', '', (string)$text);
        $t = (string)$t;
        $len = mb_strlen($t, 'UTF-8');
        if ($len <= $n) return [];
        $set = [];
        for ($i = 0; $i <= $len - $n; $i++) {
            $g = mb_substr($t, $i, $n, 'UTF-8');
            $set[$g] = 1;
            if (count($set) > 4000) break;
        }
        return $set;
    }

    protected function overlapRatio(array $a, array $b): float
    {
        $na = count($a);
        if ($na === 0) return 0.0;
        $hit = 0;
        foreach ($a as $k => $_) {
            if (isset($b[$k])) $hit++;
        }
        return $hit / max(1, $na);
    }

    protected function loadSourceArticleTexts(int $userId, ?int $tenantId, array $sourceArticles): array
    {
        $texts = [];
        if (!is_array($sourceArticles)) return $texts;
        $ids = [];
        foreach ($sourceArticles as $a) {
            if (!is_array($a)) continue;
            $rid = trim((string)($a['article_id'] ?? ''));
            if ($rid !== '') $ids[] = $rid;
        }
        if (!$ids) return $texts;
        $ids = array_values(array_unique($ids));
        try {
            $query = Db::table('resources')->where('user_id', $userId)->whereIn('resource_id', $ids);
            if ($tenantId !== null) $query = $query->where('tenant_id', $tenantId);
            $rows = $query->select()->toArray();
            foreach ($rows as $r) {
                $rid = (string)($r['resource_id'] ?? '');
                $content = (string)($r['content'] ?? '');
                if ($rid !== '' && $content !== '') {
                    $texts[$rid] = $this->chunkText($content, 20000);
                }
            }
        } catch (\Throwable $e) {
        }
        return $texts;
    }

    protected function riskCheck(string $styledDraft, int $userId, ?int $tenantId, ?array $styleProfile): array
    {
        $threshold = 0.28;
        $nv = is_array($styleProfile) ? ($styleProfile['risk_controls']['no_verbatim_threshold'] ?? null) : null;
        if (is_numeric($nv)) {
            $nvf = (float)$nv;
            if ($nvf >= 0.0 && $nvf <= 1.0) {
                $threshold = max(0.2, min(0.45, 0.16 + 0.15 * $nvf));
            }
        }
        $sourceArticles = is_array($styleProfile) && is_array($styleProfile['source_articles'] ?? null) ? $styleProfile['source_articles'] : [];
        $sourceTexts = $this->loadSourceArticleTexts($userId, $tenantId, $sourceArticles);
        if (!$sourceTexts) return ['rewrite_required' => false, 'flagged_segments' => []];

        $paras = $this->splitParagraphs($styledDraft);
        $flagged = [];
        foreach ($paras as $idx => $p) {
            if (mb_strlen($p, 'UTF-8') < 180) continue;
            $pSet = $this->ngramSet($p, 5);
            if (!$pSet) continue;
            $best = ['score' => 0.0, 'article_id' => ''];
            foreach ($sourceTexts as $rid => $txt) {
                $sSet = $this->ngramSet($txt, 5);
                $score = $this->overlapRatio($pSet, $sSet);
                if ($score > $best['score']) {
                    $best = ['score' => $score, 'article_id' => $rid];
                }
                if ($best['score'] >= $threshold) break;
            }
            if ($best['score'] >= $threshold) {
                $flagged[] = [
                    'segment_id' => 'p' . ($idx + 1),
                    'reason' => 'n-gram overlap',
                    'nearest_source_article_id' => $best['article_id'],
                    'similarity_score' => round($best['score'], 4),
                    'excerpt' => $this->chunkText($p, 260),
                ];
                if (count($flagged) >= 6) break;
            }
        }
        return [
            'rewrite_required' => count($flagged) > 0,
            'flagged_segments' => $flagged,
        ];
    }

    public function fire(Job $job, $data)
    {
        $this->ensureTables();

        $taskId = isset($data['task_id']) ? (string)$data['task_id'] : '';
        $tenantId = $data['tenant_id'] ?? null;
        $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        $workResourceId = isset($data['work_resource_id']) ? trim((string)$data['work_resource_id']) : '';
        $title = isset($data['title']) ? trim((string)$data['title']) : '';
        $topic = isset($data['topic']) ? trim((string)$data['topic']) : '';
        $genre = isset($data['genre']) ? trim((string)$data['genre']) : '';
        $wordCount = isset($data['word_count']) ? (int)$data['word_count'] : 0;
        if ($wordCount < 0) $wordCount = 0;
        $wordCountText = $wordCount > 0 ? (string)$wordCount : '未指定';
        $styleId = isset($data['style_id']) ? trim((string)$data['style_id']) : 'default';
        $styleProfileId = isset($data['style_profile_id']) ? (int)$data['style_profile_id'] : 0;
        $modelIdentity = isset($data['model_identity']) ? trim((string)$data['model_identity']) : '';
        $requirements = isset($data['requirements']) ? trim((string)$data['requirements']) : '';
        $writingPurpose = isset($data['writing_purpose']) ? trim((string)$data['writing_purpose']) : '';
        $targetAudience = isset($data['target_audience']) ? trim((string)$data['target_audience']) : '';
        $isFactualWriting = $this->isFactualWritingGenre($genre);

        $this->wsUserId = $userId;
        $this->wsTaskId = $taskId;
        $this->wsWorkResourceId = $workResourceId;
        $this->wsTenantId = $tenantId !== null ? (int)$tenantId : null;

        if ($taskId === '' || $userId <= 0 || $topic === '') {
            if ($taskId !== '') {
                $taskKey = 'writing_task:' . $taskId;
                $redis = $this->getRedis();
                $this->setTaskStatus($redis, $taskKey, [
                    'status' => 'FAILED',
                    'stage' => 'QUEUED',
                    'progress' => '0',
                    'error_message' => 'Missing required params',
                ]);
                $this->updateTaskRow($taskId, [
                    'status' => 'FAILED',
                    'error_message' => 'Missing required params',
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $job->delete();
            return;
        }

        $taskKey = 'writing_task:' . $taskId;
        $redis = $this->getRedis();
        $now = date('Y-m-d H:i:s');

        $this->updateTaskRow($taskId, [
            'status' => 'STAGE_COMPILE_STYLE',
            'stage' => 'STAGE_COMPILE_STYLE',
            'started_at' => $now,
        ]);
        $this->setTaskStatus($redis, $taskKey, [
            'status' => 'STAGE_COMPILE_STYLE',
            'stage' => 'STAGE_COMPILE_STYLE',
            'progress' => '5',
        ]);

        try {
            if ($this->isCancelled($redis, $taskId)) {
                $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_COMPILE_STYLE', 'progress' => '5']);
                $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                $job->delete();
                return;
            }

            $profile = null;
            if ($styleProfileId > 0) {
                $styleProfileRow = $this->readStyleProfile($userId, $tenantId !== null ? (int)$tenantId : null, $styleProfileId);
                if (!$styleProfileRow) {
                    throw new \Exception('Style Profile not found');
                }
                $profile = $styleProfileRow['profile'] ?? null;
                if (!is_array($profile)) {
                    throw new \Exception('Style Profile invalid');
                }
            }

            $styleRuntime = $this->compileStyleRuntimeConfig($profile);
            $this->saveArtifact($taskId, 'style_runtime_config', $styleRuntime, null, 1);

            if ($this->isCancelled($redis, $taskId)) {
                $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_COMPILE_STYLE', 'progress' => '8']);
                $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                $job->delete();
                return;
            }

            $promptRow = $this->loadSystemPromptsRow();
            $resumeFromNeutral = !empty($data['resume_from_neutral']);
            $neutralDraft = '';
            $skeletonOutlineText = '';
            $skeletonOutlineJson = null;
            if ($resumeFromNeutral) {
                $neutralDraft = $this->loadNeutralDraftForResume($taskId, $workResourceId, $userId, $tenantId !== null ? (int)$tenantId : null);
                if ($neutralDraft === '') {
                    throw new \Exception('Neutral draft not found');
                }
            } else {
            $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_SKELETON_OUTLINE', 'stage' => 'STAGE_SKELETON_OUTLINE', 'progress' => '10']);
            $this->updateTaskRow($taskId, ['status' => 'STAGE_SKELETON_OUTLINE', 'stage' => 'STAGE_SKELETON_OUTLINE']);

            $skSystem = $this->pickDbPrompt($promptRow, 'article_sm_skeleton_outline_system_prompt', "你是文章骨架规划器。你可以输出 Markdown，但必须先输出一段严格 JSON（只包含 skeleton_outline_json 字段），然后换行再输出 Markdown 骨架。\n\n要求：\n- 骨架必须由“写作目的/目标受众/体裁”驱动\n- 骨架只定义结构与每节写作意图，不需要任何外部事实或数据\n- 每节都要标注：需要什么证据（数据/案例/定义/争议/政策等）\n- 不输出任何 URL\n- 骨架要覆盖：问题-背景-现状-机制-案例-争议-建议-结论（可按体裁做合理增删/改名）");
            $skUserTpl = $this->pickDbPrompt($promptRow, 'article_sm_skeleton_outline_user_prompt', "标题：{{TITLE}}\n主题/要点：{{TOPIC}}\n体裁：{{GENRE}}\n写作目的：{{WRITING_PURPOSE}}\n目标受众：{{TARGET_AUDIENCE}}\n字数目标：{{WORD_COUNT}}\n\n额外要求：{{REQUIREMENTS}}\n\nstyle_runtime_config：\n{{STYLE_RUNTIME_JSON}}\n\n输出：\n1) JSON：{\"skeleton_outline_json\": {\"sections\": [{\"title\":\"\",\"intent\":\"\",\"needed_evidence\":[]}]}}\n2) Markdown 骨架（用二级标题分节）");
            $skUser = $this->renderPromptTemplate($skUserTpl, [
                'TITLE' => ($title !== '' ? $title : $topic),
                'TOPIC' => $topic,
                'GENRE' => $genre,
                'WRITING_PURPOSE' => $writingPurpose !== '' ? $writingPurpose : '未指定',
                'TARGET_AUDIENCE' => $targetAudience !== '' ? $targetAudience : '大众读者',
                'WORD_COUNT' => $wordCountText,
                'REQUIREMENTS' => $requirements !== '' ? $requirements : '无',
                'STYLE_RUNTIME_JSON' => json_encode($styleRuntime, JSON_UNESCAPED_UNICODE),
            ]);
            $skModelIdentity = $this->pickDbModelIdentity($promptRow, 'article_sm_skeleton_outline_model_identity', $modelIdentity);
            $skText = $this->llmText($userId, [
                ['role' => 'system', 'content' => $skSystem],
                ['role' => 'user', 'content' => $skUser],
            ], [
                'model_identity' => $skModelIdentity,
                'temperature' => 0.25,
            ]);
            $skeletonOutlineJson = $this->extractJson($skText);
            $skeletonOutlineText = trim((string)$skText);
            if ($skeletonOutlineText === '') {
                $skeletonOutlineText = "## 问题与结论先行\n\n## 背景\n\n## 现状\n\n## 机制/原理\n\n## 案例/场景\n\n## 争议与风险\n\n## 建议与落地\n\n## 结论";
            }
            $this->saveArtifact($taskId, 'skeleton_outline', is_array($skeletonOutlineJson) ? $skeletonOutlineJson : null, $skeletonOutlineText, 1);
            $previewSections = [];
            if (is_array($skeletonOutlineJson) && is_array($skeletonOutlineJson['skeleton_outline_json'] ?? null) && is_array($skeletonOutlineJson['skeleton_outline_json']['sections'] ?? null)) {
                foreach (array_slice($skeletonOutlineJson['skeleton_outline_json']['sections'], 0, 8) as $sec) {
                    if (!is_array($sec)) continue;
                    $tt = trim((string)($sec['title'] ?? ''));
                    if ($tt !== '') $previewSections[] = $tt;
                }
            }
            $this->setTaskStatus($redis, $taskKey, [
                'preview' => [
                    'type' => 'skeleton_outline',
                    'artifact_ready' => true,
                    'sections' => $previewSections,
                ],
            ]);

            if ($this->isCancelled($redis, $taskId)) {
                $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_SKELETON_OUTLINE', 'progress' => '12']);
                $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                $job->delete();
                return;
            }

            $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_NEED_ASSESS', 'stage' => 'STAGE_NEED_ASSESS', 'progress' => '14']);
            $this->updateTaskRow($taskId, ['status' => 'STAGE_NEED_ASSESS', 'stage' => 'STAGE_NEED_ASSESS']);

            $needAssessSystem = $this->pickDbPrompt($promptRow, 'article_sm_need_assess_system_prompt', "你是写作前的资料评估器。你必须只输出严格 JSON，不得输出任何其他文本。");
            $needAssessUserTpl = $this->pickDbPrompt($promptRow, 'article_sm_need_assess_user_prompt', "请判断生成这篇文章是否需要联网补充资料。\n\n输入：\n- 主题/要点：{{TOPIC}}\n- 体裁：{{GENRE}}\n- 写作目的：{{WRITING_PURPOSE}}\n- 目标受众：{{TARGET_AUDIENCE}}\n- 字数：{{WORD_COUNT}}\n- 骨架（结构先行）：\n{{SKELETON_OUTLINE_TEXT}}\n- 时效：若涉及“最新/2026/最近/对比/排行榜/政策/版本/价格/发布”，则需要。\n\n输出 JSON：{\n  \"research_needed\": false,\n  \"reasons\": [],\n  \"unknowns\": [],\n  \"required_evidence_types\": [],\n  \"time_range\": \"any\",\n  \"min_sources\": 5,\n  \"risk_notes\": []\n}");
            $needAssessUser = $this->renderPromptTemplate($needAssessUserTpl, [
                'TOPIC' => $topic,
                'GENRE' => $genre,
                'WRITING_PURPOSE' => $writingPurpose !== '' ? $writingPurpose : '未指定',
                'TARGET_AUDIENCE' => $targetAudience !== '' ? $targetAudience : '大众读者',
                'WORD_COUNT' => $wordCountText,
                'SKELETON_OUTLINE_TEXT' => $skeletonOutlineText,
            ]);
            $needAssessModelIdentity = $this->pickDbModelIdentity($promptRow, 'article_sm_need_assess_model_identity', $modelIdentity);
            $needAssess = $this->llmJson($userId, [
                ['role' => 'system', 'content' => $needAssessSystem],
                ['role' => 'user', 'content' => $needAssessUser],
            ], [
                'model_identity' => $needAssessModelIdentity,
                'temperature' => 0.2,
            ]);

            if (!is_array($needAssess) || !isset($needAssess['research_needed'])) {
                $needAssess = [
                    'research_needed' => true,
                    'reasons' => ['模型输出不可解析，按需要检索处理'],
                    'unknowns' => [],
                    'required_evidence_types' => [],
                    'time_range' => 'any',
                    'min_sources' => 5,
                    'risk_notes' => [],
                ];
            }
            $this->saveArtifact($taskId, 'need_assess', $needAssess, null, 1);

            $researchNeeded = (bool)($needAssess['research_needed'] ?? false);
            $minSources = (int)($needAssess['min_sources'] ?? 5);
            if ($minSources <= 0) $minSources = 5;
            if ($minSources > 12) $minSources = 12;

            $queryPlan = null;
            $sources = [];
            $factPack = null;
            $fetched = [];
            $rawExcerptPool = [];
            $citationSources = [];
            $urlToId = [];

            if ($researchNeeded) {
                if ($this->isCancelled($redis, $taskId)) {
                    $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_NEED_ASSESS', 'progress' => '15']);
                    $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                    $job->delete();
                    return;
                }

                $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_QUERY_PLAN', 'stage' => 'STAGE_QUERY_PLAN', 'progress' => '18']);
                $this->updateTaskRow($taskId, ['status' => 'STAGE_QUERY_PLAN', 'stage' => 'STAGE_QUERY_PLAN']);

                $qpSystem = $this->pickDbPrompt($promptRow, 'article_sm_query_plan_system_prompt', "你是检索计划生成器。你必须只输出严格 JSON，不得输出任何其他文本。");
                $qpUserTpl = $this->pickDbPrompt($promptRow, 'article_sm_query_plan_user_prompt', "主题/要点：{{TOPIC}}\n写作目的：{{WRITING_PURPOSE}}\n目标受众：{{TARGET_AUDIENCE}}\n体裁：{{GENRE}}\n字数：{{WORD_COUNT}}\n\n骨架（结构先行）：\n{{SKELETON_OUTLINE_TEXT}}\n\nneed_assess：\n{{NEED_ASSESS_JSON}}\n\n请生成检索计划 JSON：{\n  \"subtopics\": [{\"id\":\"s1\",\"goal\":\"\"}],\n  \"search_queries\": [{\"subtopic_id\":\"s1\",\"query\":\"\",\"query_alt\":[],\"include\":[],\"exclude\":[],\"time_range\":\"any\"}]\n}\n\n要求：\n- 检索目标要覆盖骨架中每节所需证据\n- 优先生成能找到：定义/统计数据/权威报告/标准/政策/典型案例/争议与反方观点 的查询");
                $qpUser = $this->renderPromptTemplate($qpUserTpl, [
                    'TOPIC' => $topic,
                    'WRITING_PURPOSE' => $writingPurpose !== '' ? $writingPurpose : '未指定',
                    'TARGET_AUDIENCE' => $targetAudience !== '' ? $targetAudience : '大众读者',
                    'GENRE' => $genre,
                    'WORD_COUNT' => $wordCountText,
                    'SKELETON_OUTLINE_TEXT' => $skeletonOutlineText,
                    'NEED_ASSESS_JSON' => json_encode($needAssess, JSON_UNESCAPED_UNICODE),
                ]);
                $queryPlanModelIdentity = $this->pickDbModelIdentity($promptRow, 'article_sm_query_plan_model_identity', $modelIdentity);
                $queryPlan = $this->llmJson($userId, [
                    ['role' => 'system', 'content' => $qpSystem],
                    ['role' => 'user', 'content' => $qpUser],
                ], [
                    'model_identity' => $queryPlanModelIdentity,
                    'temperature' => 0.2,
                ]);
                if (!is_array($queryPlan) || !isset($queryPlan['search_queries']) || !is_array($queryPlan['search_queries'])) {
                    $queryPlan = [
                        'subtopics' => [['id' => 's1', 'goal' => $topic]],
                        'search_queries' => [['subtopic_id' => 's1', 'query' => $topic, 'query_alt' => [], 'include' => [], 'exclude' => [], 'time_range' => 'any']],
                    ];
                }
                $this->saveArtifact($taskId, 'query_plan', $queryPlan, null, 1);
                $qpSubtopics = isset($queryPlan['subtopics']) && is_array($queryPlan['subtopics']) ? $queryPlan['subtopics'] : [];
                $qpQueries = isset($queryPlan['search_queries']) && is_array($queryPlan['search_queries']) ? $queryPlan['search_queries'] : [];
                $this->setTaskStatus($redis, $taskKey, [
                    'preview' => [
                        'type' => 'query_plan',
                        'artifact_ready' => true,
                        'subtopics' => array_slice($qpSubtopics, 0, 6),
                        'search_queries' => array_slice($qpQueries, 0, 10),
                    ],
                ]);

                if ($this->isCancelled($redis, $taskId)) {
                    $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_QUERY_PLAN', 'progress' => '22']);
                    $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                    $job->delete();
                    return;
                }

                $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_WEB_SEARCH', 'stage' => 'STAGE_WEB_SEARCH', 'progress' => '25']);
                $this->updateTaskRow($taskId, ['status' => 'STAGE_WEB_SEARCH', 'stage' => 'STAGE_WEB_SEARCH']);

                $allUrls = [];
                $queries = $queryPlan['search_queries'] ?? [];
                $qCount = 0;
                foreach ($queries as $q) {
                    if (!is_array($q)) continue;
                    $query = trim((string)($q['query'] ?? ''));
                    if ($query === '') continue;
                    $qCount++;
                    $hits = $this->webSearch($query, (int)$userId);
                    foreach ($hits as $h) {
                        $u = (string)($h['url'] ?? '');
                        if ($u === '') continue;
                        $key = strtolower($u);
                        if (isset($allUrls[$key])) continue;
                        $allUrls[$key] = $h;
                        if (count($allUrls) >= max(10, $minSources * 3)) break 2;
                    }
                    if ($qCount >= 6) break;
                    if ($this->isCancelled($redis, $taskId)) break;
                }

                $sources = array_values($allUrls);
                $this->saveArtifact($taskId, 'sources', ['items' => $sources], null, 1);
                $srcPreviewItems = array_slice($sources, 0, 5);
                $this->setTaskStatus($redis, $taskKey, [
                    'preview' => [
                        'type' => 'sources',
                        'artifact_ready' => true,
                        'totalCount' => count($sources),
                        'items' => $srcPreviewItems,
                        'moreCount' => max(0, count($sources) - count($srcPreviewItems)),
                    ],
                ]);

                if ($this->isCancelled($redis, $taskId)) {
                    $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_WEB_SEARCH', 'progress' => '30']);
                    $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                    $job->delete();
                    return;
                }

                $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_FETCH_SOURCES', 'stage' => 'STAGE_FETCH_SOURCES', 'progress' => '35']);
                $this->updateTaskRow($taskId, ['status' => 'STAGE_FETCH_SOURCES', 'stage' => 'STAGE_FETCH_SOURCES']);

                $fetched = [];
                $fetchedAll = [];
                $inlineRefText = trim((string)$this->volcInlineReferenceText);
                if ($inlineRefText !== '') {
                    $inlineRefText = $this->stripUrlsFromMarkdown($inlineRefText);
                    $inlineRefText = $this->chunkText($inlineRefText, 4500);
                    if ($inlineRefText !== '') {
                        $inlineItem = [
                            'url' => 'volcengine://web_search/answer',
                            'title' => '火山引擎搜索回答',
                            'content_excerpt' => $inlineRefText,
                            'images' => [],
                            'images_raw' => [],
                            'images_stored' => [],
                        ];
                        $fetchedAll[] = $inlineItem;
                        $fetched[] = $inlineItem;
                    }
                }
                $imgClient = new Client([
                    'timeout' => 20,
                    'connect_timeout' => 8,
                    'verify' => false,
                ]);
                $imageService = new ImageService();
                $storedByRawUrl = [];
                $storedTotal = 0;
                $maxImagesTotal = 60;
                $maxImagesPerSource = 12;
                $alreadyFetchedUrlKeys = [];
                foreach ($fetchedAll as $it0) {
                    if (!is_array($it0)) continue;
                    $u0 = trim((string)($it0['url'] ?? ''));
                    if ($u0 === '') continue;
                    $alreadyFetchedUrlKeys[strtolower($u0)] = 1;
                }

                $coverageMinSources = max(3, min(12, (int)$minSources));
                $coverageMinTotalChars = 1200;
                if ($wordCount > 0) {
                    $coverageMinTotalChars = (int)round($wordCount * 1.1);
                    $coverageMinTotalChars = max(1200, min(18000, $coverageMinTotalChars));
                }
                $coverageNonEmpty = 0;
                $coverageChars = 0;
                foreach ($fetched as $it0) {
                    if (!is_array($it0)) continue;
                    $c0 = trim((string)($it0['content_excerpt'] ?? ''));
                    if ($c0 === '') continue;
                    $coverageNonEmpty++;
                    $coverageChars += mb_strlen($c0, 'UTF-8');
                }

                $coverageHistory = [];
                $supplementRound = 0;
                $maxSupplementRounds = 2;

                while (true) {
                    $sources = array_values($allUrls);
                    $limit = count($sources) + count($fetchedAll);
                    $earlyEnough = false;
                    foreach ($sources as $s) {
                        $u = isset($s['url']) ? (string)$s['url'] : '';
                        $u = trim((string)$u);
                        if ($u === '') continue;
                        $uk = strtolower($u);
                        if (isset($alreadyFetchedUrlKeys[$uk])) continue;
                        $alreadyFetchedUrlKeys[$uk] = 1;

                        $scraped = $this->scrapeReadable($u);
                        $content = $this->chunkText((string)($scraped['content'] ?? ''), 4500);
                        $title2 = trim((string)($scraped['title'] ?? ''));
                        $url2 = (string)($scraped['url'] ?? $u);
                        $url2 = trim((string)$url2);
                        if ($url2 !== '') $alreadyFetchedUrlKeys[strtolower($url2)] = 1;

                        $images = $scraped['images'] ?? [];
                        if (!is_array($images)) $images = [];

                        $rawImages = [];
                        $storedImages = [];
                        $storedThisSource = 0;
                        foreach ($images as $imgUrl) {
                            $imgUrl2 = trim((string)$imgUrl);
                            if ($imgUrl2 === '') continue;
                            if ($this->isLikelyNonContentImageUrl($imgUrl2)) continue;

                            $k = strtolower($this->stripUrlFragment($imgUrl2));
                            if ($k === '') continue;
                            if (isset($storedByRawUrl[$k])) {
                                $existingStored = (string)$storedByRawUrl[$k];
                                if ($existingStored !== '' && $existingStored !== '__REJECT__') $storedImages[] = $existingStored;
                                continue;
                            }

                            if ($storedTotal < $maxImagesTotal && $storedThisSource < $maxImagesPerSource) {
                                if ($this->isCancelled($redis, $taskId)) break;

                                $r = $this->storeRemoteImageUrlResult($imgClient, $imageService, $imgUrl2, $url2 !== '' ? $url2 : $u, 'scraped');
                                $storedUrl = is_array($r) ? (string)($r['stored_url'] ?? '') : '';
                                $rejected = is_array($r) ? (bool)($r['rejected'] ?? false) : false;
                                $storedByRawUrl[$k] = $storedUrl !== '' ? $storedUrl : ($rejected ? '__REJECT__' : '');
                                if ($storedUrl !== '') {
                                    $storedTotal++;
                                    $storedThisSource++;
                                    $storedImages[] = $storedUrl;
                                    try {
                                        Db::table('image_assets')->insert([
                                            'tenant_id' => $tenantId,
                                            'user_id' => $userId,
                                            'category' => 'scraped',
                                            'type' => 'web_scrape',
                                            'url' => $storedUrl,
                                            'created_at' => date('Y-m-d H:i:s'),
                                        ]);
                                    } catch (\Throwable $e) {
                                    }
                                }
                                if ($rejected) continue;
                            } else {
                                $storedByRawUrl[$k] = '';
                            }

                            $rawImages[] = $imgUrl2;

                            if ($this->isCancelled($redis, $taskId)) break;
                        }

                        $rawImages = array_values(array_unique($rawImages));
                        $storedImages = array_values(array_unique($storedImages));
                        $imagesOut = !empty($storedImages) ? $storedImages : $rawImages;
                        $item = [
                            'url' => $url2 !== '' ? $url2 : $u,
                            'title' => $title2,
                            'content_excerpt' => $content,
                            'images' => $imagesOut,
                            'images_raw' => $rawImages,
                            'images_stored' => $storedImages,
                        ];
                        $fetchedAll[] = $item;
                        if ($content !== '') {
                            $fetched[] = $item;
                            $coverageNonEmpty++;
                            $coverageChars += mb_strlen($content, 'UTF-8');
                            if ($coverageNonEmpty >= $coverageMinSources && $coverageChars >= $coverageMinTotalChars) {
                                $earlyEnough = true;
                                break;
                            }
                        }
                        if (count($fetchedAll) >= $limit) break;
                        if ($this->isCancelled($redis, $taskId)) break;
                    }

                    $coverage = $this->assessResearchCoverage($fetched, $wordCount, $minSources);
                    $coverage['round'] = $supplementRound;
                    $coverageHistory[] = $coverage;
                    $this->saveArtifact($taskId, 'research_coverage', $coverage, null, $supplementRound + 1);
                    $this->setTaskStatus($redis, $taskKey, [
                        'preview' => [
                            'type' => 'research_coverage',
                            'artifact_ready' => true,
                            'round' => $supplementRound,
                            'ok' => (bool)($coverage['ok'] ?? false),
                            'nonEmptySources' => (int)($coverage['non_empty_sources'] ?? 0),
                            'minSources' => (int)($coverage['min_sources'] ?? 0),
                            'totalChars' => (int)($coverage['total_chars'] ?? 0),
                            'minTotalChars' => (int)($coverage['min_total_chars'] ?? 0),
                            'reasons' => $coverage['reasons'] ?? [],
                        ],
                    ]);

                    if ((bool)($coverage['ok'] ?? false)) break;
                    if ($supplementRound >= $maxSupplementRounds) break;
                    if ($this->isCancelled($redis, $taskId)) break;

                    $extraQueries = $this->buildSupplementalSearchQueries($topic, is_array($needAssess) ? $needAssess : null, is_array($queryPlan) ? $queryPlan : null, $fetched, $wordCount);
                    if (!$extraQueries) break;

                    $this->setTaskStatus($redis, $taskKey, [
                        'preview' => [
                            'type' => 'supplement_search',
                            'artifact_ready' => false,
                            'round' => $supplementRound + 1,
                            'queries' => $extraQueries,
                        ],
                    ]);

                    $beforeCount = count($allUrls);
                    foreach ($extraQueries as $sq) {
                        if ($this->isCancelled($redis, $taskId)) break;
                        $hits2 = $this->webSearch((string)$sq, (int)$userId);
                        foreach ($hits2 as $h2) {
                            $u5 = (string)($h2['url'] ?? '');
                            $u5 = trim((string)$u5);
                            if ($u5 === '') continue;
                            $key5 = strtolower($u5);
                            if (isset($allUrls[$key5])) continue;
                            $allUrls[$key5] = $h2;
                            if (count($allUrls) >= max(18, $minSources * 5)) break 2;
                        }
                    }
                    $afterCount = count($allUrls);
                    if ($afterCount <= $beforeCount) break;

                    $sources = array_values($allUrls);
                    $this->saveArtifact($taskId, 'sources', ['items' => $sources], null, $supplementRound + 2);
                    $srcPreviewItems2 = array_slice($sources, 0, 5);
                    $this->setTaskStatus($redis, $taskKey, [
                        'preview' => [
                            'type' => 'sources',
                            'artifact_ready' => true,
                            'totalCount' => count($sources),
                            'items' => $srcPreviewItems2,
                            'moreCount' => max(0, count($sources) - count($srcPreviewItems2)),
                        ],
                    ]);

                    $supplementRound++;
                }

                $citationSources = $this->buildCitationSources($fetched);
                foreach ($citationSources as $cs) {
                    $u3 = isset($cs['url']) ? trim((string)$cs['url']) : '';
                    if ($u3 === '') continue;
                    $urlToId[strtolower($u3)] = (int)($cs['id'] ?? 0);
                }
                $fetchedForFactPack = [];
                foreach ($fetched as $fs) {
                    if (!is_array($fs)) continue;
                    $u4 = trim((string)($fs['url'] ?? ''));
                    if ($u4 === '') continue;
                    $sid = $urlToId[strtolower($u4)] ?? 0;
                    if ($sid <= 0) continue;
                    $fetchedForFactPack[] = [
                        'source_id' => (int)$sid,
                        'title' => trim((string)($fs['title'] ?? '')),
                        'content_excerpt' => (string)($fs['content_excerpt'] ?? ''),
                    ];
                }
                $rawExcerptPool = $this->buildRawExcerptPool($fetched, $urlToId);
                if ($rawExcerptPool) {
                    $this->saveArtifact($taskId, 'raw_excerpt_pool', ['items' => $rawExcerptPool], null, 1);
                    $this->setTaskStatus($redis, $taskKey, [
                        'preview' => [
                            'type' => 'raw_excerpt_pool',
                            'artifact_ready' => true,
                            'totalCount' => count($rawExcerptPool),
                            'items' => array_slice($rawExcerptPool, 0, 3),
                            'moreCount' => max(0, count($rawExcerptPool) - 3),
                        ],
                    ]);
                }

                $this->saveArtifact($taskId, 'fetched_sources', ['items' => $fetchedAll], null, 1);
                $fsPreviewRaw = array_slice($fetchedAll, 0, 5);
                $fsPreviewItems = [];
                foreach ($fsPreviewRaw as $it) {
                    if (!is_array($it)) continue;
                    $fsPreviewItems[] = [
                        'url' => (string)($it['url'] ?? ''),
                        'title' => (string)($it['title'] ?? ''),
                        'content_excerpt' => (string)($it['content_excerpt'] ?? ''),
                    ];
                }
                $this->setTaskStatus($redis, $taskKey, [
                    'preview' => [
                        'type' => 'fetched_sources',
                        'artifact_ready' => true,
                        'totalCount' => count($fetchedAll),
                        'items' => $fsPreviewItems,
                        'moreCount' => max(0, count($fetchedAll) - count($fsPreviewItems)),
                    ],
                ]);

                if ($this->isCancelled($redis, $taskId)) {
                    $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_FETCH_SOURCES', 'progress' => '42']);
                    $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                    $job->delete();
                    return;
                }

                $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_FACT_PACK', 'stage' => 'STAGE_FACT_PACK', 'progress' => '48']);
                $this->updateTaskRow($taskId, ['status' => 'STAGE_FACT_PACK', 'stage' => 'STAGE_FACT_PACK']);

                $fpSystem = $this->pickDbPrompt($promptRow, 'article_sm_fact_pack_system_prompt', "你是事实包提炼器。你必须只输出严格 JSON，不得输出任何其他文本。");
                $fpUserTpl = $this->pickDbPrompt($promptRow, 'article_sm_fact_pack_user_prompt', "主题/要点：{{TOPIC}}\n写作目的：{{WRITING_PURPOSE}}\n目标受众：{{TARGET_AUDIENCE}}\n体裁：{{GENRE}}\n\n骨架（结构先行）：\n{{SKELETON_OUTLINE_TEXT}}\n\n资料摘录（每条包含 source_id/title/content_excerpt；禁止输出任何 URL）：\n{{FETCHED_FOR_FACT_PACK_JSON}}\n\n请输出 fact_pack JSON：{\n  \"facts\": [{\"statement\":\"\",\"source_ids\":[1],\"date\":null,\"confidence\":0.5}],\n  \"definitions\": [],\n  \"stats\": [],\n  \"cases\": [],\n  \"quotes\": [],\n  \"conflicts\": [],\n  \"open_questions\": [],\n  \"grounding_rules\": {\n    \"allowed_assertions\": [],\n    \"must_hedge_topics\": [],\n    \"citation_policy\": {\"max_citations_per_paragraph\": 2, \"format\": \"[n]\"}\n  }\n}\n\n要求：\n- 事实包只收录“可引用的结论/数据/案例/定义/原话”，不要写解释性长段\n- 禁止输出任何 URL（包含 http/https/域名）\n- 引用只能用 source_ids（取值来自资料摘录里的 source_id）\n- statement/term/definition 等字段内不得包含 URL\n- 不确定或推测内容不要写入事实包");
                $fpUser = $this->renderPromptTemplate($fpUserTpl, [
                    'TOPIC' => $topic,
                    'WRITING_PURPOSE' => $writingPurpose !== '' ? $writingPurpose : '未指定',
                    'TARGET_AUDIENCE' => $targetAudience !== '' ? $targetAudience : '大众读者',
                    'GENRE' => $genre,
                    'SKELETON_OUTLINE_TEXT' => $skeletonOutlineText,
                    'FETCHED_FOR_FACT_PACK_JSON' => json_encode($fetchedForFactPack, JSON_UNESCAPED_UNICODE),
                ]);
                $factPackModelIdentity = $this->pickDbModelIdentity($promptRow, 'article_sm_fact_pack_model_identity', $modelIdentity);
                $factPack = $this->llmJson($userId, [
                    ['role' => 'system', 'content' => $fpSystem],
                    ['role' => 'user', 'content' => $fpUser],
                ], [
                    'model_identity' => $factPackModelIdentity,
                    'temperature' => 0.2,
                ]);
                if (!is_array($factPack)) {
                    $factPack = [
                        'facts' => [],
                        'definitions' => [],
                        'stats' => [],
                        'cases' => [],
                        'quotes' => [],
                        'conflicts' => [],
                        'open_questions' => [],
                        'grounding_rules' => [
                            'allowed_assertions' => [],
                            'must_hedge_topics' => [],
                            'citation_policy' => ['max_citations_per_paragraph' => 2, 'format' => '[n]'],
                        ],
                    ];
                }
                if (is_array($factPack)) $factPack = $this->replaceFactPackSourceUrlsWithIds($factPack, $urlToId);
                $this->saveArtifact($taskId, 'fact_pack', $factPack, null, 1);
                $fpFacts = isset($factPack['facts']) && is_array($factPack['facts']) ? $factPack['facts'] : [];
                $fpStats = isset($factPack['stats']) && is_array($factPack['stats']) ? $factPack['stats'] : [];
                $fpCases = isset($factPack['cases']) && is_array($factPack['cases']) ? $factPack['cases'] : [];
                $fpDefs = isset($factPack['definitions']) && is_array($factPack['definitions']) ? $factPack['definitions'] : [];
                $fpQuotes = isset($factPack['quotes']) && is_array($factPack['quotes']) ? $factPack['quotes'] : [];
                $fpConflicts = isset($factPack['conflicts']) && is_array($factPack['conflicts']) ? $factPack['conflicts'] : [];
                $fpOpens = isset($factPack['open_questions']) && is_array($factPack['open_questions']) ? $factPack['open_questions'] : [];
                $topFacts = [];
                foreach (array_slice($fpFacts, 0, 8) as $f) {
                    if (!is_array($f)) continue;
                    $s = trim((string)($f['statement'] ?? ''));
                    if ($s !== '') $topFacts[] = $s;
                }
                $summary = '事实包已生成：facts ' . count($fpFacts) . ' / stats ' . count($fpStats) . ' / cases ' . count($fpCases) . ' / definitions ' . count($fpDefs) . ' / quotes ' . count($fpQuotes) . ' / conflicts ' . count($fpConflicts) . ' / open_questions ' . count($fpOpens);
                $this->setTaskStatus($redis, $taskKey, [
                    'preview' => [
                        'type' => 'fact_pack',
                        'artifact_ready' => true,
                        'summary' => $summary,
                        'topFacts' => $topFacts,
                    ],
                ]);
            }

            if ($this->isCancelled($redis, $taskId)) {
                $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_FACT_PACK', 'progress' => '55']);
                $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                $job->delete();
                return;
            }

            $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_OUTLINE', 'stage' => 'STAGE_OUTLINE', 'progress' => '58']);
            $this->updateTaskRow($taskId, ['status' => 'STAGE_OUTLINE', 'stage' => 'STAGE_OUTLINE']);

            $outlineSystem = $this->pickDbPrompt($promptRow, 'article_sm_outline_system_prompt', "你是文章大纲生成器。你可以输出 Markdown，但必须先输出一段严格 JSON（只包含 outline_json 字段），然后换行再输出 Markdown 大纲。");
            $outlineUserTpl = $this->pickDbPrompt($promptRow, 'article_sm_outline_user_prompt', "主题/要点：{{TOPIC}}\n体裁：{{GENRE}}\n写作目的：{{WRITING_PURPOSE}}\n目标受众：{{TARGET_AUDIENCE}}\n字数：{{WORD_COUNT}}\n\n骨架（结构先行）：\n{{SKELETON_OUTLINE_TEXT}}\n\nstyle_runtime_config：\n{{STYLE_RUNTIME_JSON}}\n\nfact_pack：\n{{FACT_PACK_JSON}}\n\n要求：\n- 在骨架基础上细化为可写的大纲（每节给出写作意图、要点、建议引用的事实包条目类型）\n- 先输出 JSON：{\"outline_json\": {\"sections\": [{\"title\":\"\",\"intent\":\"\"}]}}\n- 再输出 Markdown 大纲\n- 不输出任何 URL");
            $outlineUser = $this->renderPromptTemplate($outlineUserTpl, [
                'TOPIC' => $topic,
                'GENRE' => $genre,
                'WRITING_PURPOSE' => $writingPurpose !== '' ? $writingPurpose : '未指定',
                'TARGET_AUDIENCE' => $targetAudience !== '' ? $targetAudience : '大众读者',
                'WORD_COUNT' => $wordCountText,
                'SKELETON_OUTLINE_TEXT' => $skeletonOutlineText,
                'STYLE_RUNTIME_JSON' => json_encode($styleRuntime, JSON_UNESCAPED_UNICODE),
                'FACT_PACK_JSON' => json_encode($factPack ?: [], JSON_UNESCAPED_UNICODE),
            ]);
            $outlineModelIdentity = $this->pickDbModelIdentity($promptRow, 'article_sm_outline_model_identity', $modelIdentity);
            $outlineText = $this->llmText($userId, [
                ['role' => 'system', 'content' => $outlineSystem],
                ['role' => 'user', 'content' => $outlineUser],
            ], [
                'model_identity' => $outlineModelIdentity,
                'temperature' => 0.3,
            ]);
            $outlineJson = $this->extractJson($outlineText);
            $this->saveArtifact($taskId, 'outline', is_array($outlineJson) ? $outlineJson : null, $outlineText, 1);

            if ($this->isCancelled($redis, $taskId)) {
                $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_OUTLINE', 'progress' => '64']);
                $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                $job->delete();
                return;
            }

            $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_NEUTRAL_DRAFT', 'stage' => 'STAGE_NEUTRAL_DRAFT', 'progress' => '68']);
            $this->updateTaskRow($taskId, ['status' => 'STAGE_NEUTRAL_DRAFT', 'stage' => 'STAGE_NEUTRAL_DRAFT']);

            if (empty($citationSources)) $citationSources = $this->buildCitationSources($fetched ?? []);
            if (empty($urlToId)) {
                foreach ($citationSources as $cs) {
                    $u3 = isset($cs['url']) ? trim((string)$cs['url']) : '';
                    if ($u3 === '') continue;
                    $urlToId[strtolower($u3)] = (int)($cs['id'] ?? 0);
                }
            }
            $citationSourcesForPrompt = [];
            foreach ($citationSources as $cs) {
                $citationSourcesForPrompt[] = [
                    'id' => (int)($cs['id'] ?? 0),
                    'title' => trim((string)($cs['title'] ?? '')),
                ];
            }
            $factPackForDraft = is_array($factPack) ? $this->replaceFactPackSourceUrlsWithIds($factPack, $urlToId) : [];

            $neutralSystemDefault = $isFactualWriting
                ? ("你是写作引擎。请根据大纲与资料生成中性稿（Markdown）。\n\n定位：当前为“偏事实类写作”（如科普/新闻/评测/教程/行业分析）。\n\n核心原则：\n- 事实包优先：可验证的事实/数据/时间点/真实案例，优先来自 fact_pack；没有证据就不要写成确定事实\n- 可控扩写：允许写解释性背景/方法论/常识性阐释/推测性分析，但必须显式标注为【常识性阐释】或【推测性分析】，且这类内容不得带引用\n- 双层资料：raw_excerpt_pool 只能用于扩写细节与语境描述；引用仍只能来自 citation_sources 的编号 [n]\n\n格式与安全：\n- 禁止输出任何 URL（包含 http/https/域名）\n- 引用只能使用编号 [n]（n 必须来自 citation_sources），每段最多 2 个\n- 若无可靠来源，不要引用\n- 未证实点必须弱化表达\n\n输出必须是严格 JSON：{\"title\":\"\",\"mainText\":\"Markdown 正文\"}")
                : ("你是写作引擎。请根据大纲与资料生成内容（Markdown）。\n\n定位：当前为“通用创作写作”（可能包含故事/软文/品牌内容/创意表达）。fact_pack 与 raw_excerpt_pool 仅作为参考资料。\n\n核心原则：\n- 可以创作：允许虚构人物/情节/场景/对话，但不要把虚构内容伪装成可验证的现实新闻、真实数据或真实事件\n- 使用资料时要对齐：当你引用或复述资料中的现实信息，请以 fact_pack 为准，并用 citation_sources 的编号 [n] 标注；不要杜撅具体数字/来源\n- 推测与常识：当你对现实问题做推测/背景解释时，必须用【常识性阐释】或【推测性分析】标注，且这类内容不得带引用\n\n格式与安全：\n- 禁止输出任何 URL（包含 http/https/域名）\n- 引用只能使用编号 [n]（n 必须来自 citation_sources），每段最多 2 个\n\n输出必须是严格 JSON：{\"title\":\"\",\"mainText\":\"Markdown 正文\"}");
            $neutralSystem = $this->pickDbPrompt($promptRow, 'article_sm_neutral_draft_system_prompt', $neutralSystemDefault);
            $neutralUserTpl = $this->pickDbPrompt($promptRow, 'article_sm_neutral_draft_user_prompt', "标题：{{TITLE}}\n主题/要点：{{TOPIC}}\n体裁：{{GENRE}}\n写作目的：{{WRITING_PURPOSE}}\n目标受众：{{TARGET_AUDIENCE}}\n字数目标：{{WORD_COUNT}}\n\n额外要求：{{REQUIREMENTS}}\n\ncitation_sources：\n{{CITATION_SOURCES_JSON}}\n\n骨架（结构先行）：\n{{SKELETON_OUTLINE_TEXT}}\n\n大纲：\n{{OUTLINE_TEXT}}\n\nfact_pack（可引用事实包）：\n{{FACT_PACK_JSON}}\n\nraw_excerpt_pool（原始摘录池，用于细节扩写；每条含 source_id/title/excerpts；禁止出现 URL）：\n{{RAW_EXCERPTS_JSON}}");
            $neutralUser = $this->renderPromptTemplate($neutralUserTpl, [
                'TITLE' => ($title !== '' ? $title : $topic),
                'TOPIC' => $topic,
                'GENRE' => $genre,
                'WRITING_PURPOSE' => $writingPurpose !== '' ? $writingPurpose : '未指定',
                'TARGET_AUDIENCE' => $targetAudience !== '' ? $targetAudience : '大众读者',
                'WORD_COUNT' => $wordCountText,
                'REQUIREMENTS' => $requirements !== '' ? $requirements : '无',
                'CITATION_SOURCES_JSON' => json_encode($citationSourcesForPrompt, JSON_UNESCAPED_UNICODE),
                'SKELETON_OUTLINE_TEXT' => $skeletonOutlineText,
                'OUTLINE_TEXT' => $outlineText,
                'FACT_PACK_JSON' => json_encode($factPackForDraft, JSON_UNESCAPED_UNICODE),
                'RAW_EXCERPTS_JSON' => json_encode($rawExcerptPool ?: [], JSON_UNESCAPED_UNICODE),
            ]);
            $neutralDraftModelIdentity = $this->pickDbModelIdentity($promptRow, 'article_sm_neutral_draft_model_identity', $modelIdentity);
            $neutralOptions = [
                'model_identity' => $neutralDraftModelIdentity,
                'temperature' => 0.4,
                'response_format' => ['type' => 'json_object'],
            ];
            $neutralDraftJson = $this->llmJson($userId, [
                ['role' => 'system', 'content' => $neutralSystem],
                ['role' => 'user', 'content' => $neutralUser],
            ], $neutralOptions);
            $neutralDraftTextFallback = '';
            if (!is_array($neutralDraftJson)) {
                $neutralDraftTextFallback = $this->llmText($userId, [
                    ['role' => 'system', 'content' => $neutralSystem],
                    ['role' => 'user', 'content' => $neutralUser],
                ], $neutralOptions);
                $parsedFallback = $this->extractJson($neutralDraftTextFallback);
                if (is_array($parsedFallback)) $neutralDraftJson = $parsedFallback;
            }

            $draftTitle = is_array($neutralDraftJson) ? trim((string)($neutralDraftJson['title'] ?? '')) : '';
            $draftMainText = '';
            if (is_array($neutralDraftJson)) {
                if (isset($neutralDraftJson['mainText'])) $draftMainText = (string)$neutralDraftJson['mainText'];
                elseif (isset($neutralDraftJson['main_text'])) $draftMainText = (string)$neutralDraftJson['main_text'];
                elseif (isset($neutralDraftJson['content'])) $draftMainText = (string)$neutralDraftJson['content'];
            }
            if (trim($draftMainText) === '') {
                $draftMainText = (string)$neutralDraftTextFallback;
            }

            if ($draftTitle !== '') $title = $draftTitle;
            $neutralDraft = (string)$draftMainText;
            $this->saveArtifact($taskId, 'neutral_draft', is_array($neutralDraftJson) ? $neutralDraftJson : null, $neutralDraft, 1);
            $this->updateWorkResource($workResourceId, (int)$userId, $tenantId !== null ? (int)$tenantId : null, $draftTitle !== '' ? $draftTitle : ($title ?: null), $neutralDraft);
            }

            if ($this->isCancelled($redis, $taskId)) {
                $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_NEUTRAL_DRAFT', 'progress' => '75']);
                $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                $job->delete();
                return;
            }

            $neutralRevisionEnabled = false;
            if (is_array($promptRow) && array_key_exists('article_sm_neutral_revision_enabled', $promptRow)) {
                $rawEnabled = $promptRow['article_sm_neutral_revision_enabled'];
                $sEnabled = is_string($rawEnabled) ? trim($rawEnabled) : trim((string)$rawEnabled);
                $lowerEnabled = strtolower($sEnabled);
                $neutralRevisionEnabled = ($lowerEnabled === '1' || $lowerEnabled === 'true' || $lowerEnabled === 'on' || $lowerEnabled === 'yes');
            }

            if ($neutralRevisionEnabled) {
                $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_NEUTRAL_REVISION', 'stage' => 'STAGE_NEUTRAL_REVISION', 'progress' => '76']);
                $this->updateTaskRow($taskId, ['status' => 'STAGE_NEUTRAL_REVISION', 'stage' => 'STAGE_NEUTRAL_REVISION']);

                $revisionSystem = $this->pickDbPrompt($promptRow, 'article_sm_neutral_revision_system_prompt', "你是专业写作者与严谨编辑。你的任务是对“中性稿”进行二次修订，提升结构、逻辑、表达与可读性，并让文章更贴合用户要求（主题/写作目的/目标受众/体裁/字数/额外要求）。\n\n硬性约束：\n- 不能改变事实、时间点、数据含义与因果关系；不确定内容必须弱化表达\n- 不得新增未经证实的具体事实、数字、结论、机构或事件\n- 若需要补充背景或解释，只能写【常识性阐释】或【推测性分析】，且这类内容不得带引用\n- 严禁输出任何 URL（包含 http/https/域名）\n- 文章中已存在的引用编号 [n] 必须原样保留（不改编号、不造新编号）；允许在不改变事实的前提下移动引用到更合理的位置，但不要新增引用");
                $revisionUserTpl = $this->pickDbPrompt($promptRow, 'article_sm_neutral_revision_user_prompt', "标题：{{TITLE}}\n主题/要点：{{TOPIC}}\n体裁：{{GENRE}}\n写作目的：{{WRITING_PURPOSE}}\n目标受众：{{TARGET_AUDIENCE}}\n字数目标：{{WORD_COUNT}}\n额外要求：{{REQUIREMENTS}}\n\n中性稿（待修订）：\n{{NEUTRAL_DRAFT}}");
                $revisionUser = $this->renderPromptTemplate($revisionUserTpl, [
                    'TITLE' => ($title !== '' ? $title : $topic),
                    'TOPIC' => $topic,
                    'GENRE' => $genre,
                    'WRITING_PURPOSE' => $writingPurpose !== '' ? $writingPurpose : '未指定',
                    'TARGET_AUDIENCE' => $targetAudience !== '' ? $targetAudience : '大众读者',
                    'WORD_COUNT' => $wordCountText,
                    'REQUIREMENTS' => $requirements !== '' ? $requirements : '无',
                    'NEUTRAL_DRAFT' => $neutralDraft,
                ]);
                $neutralRevisionModelIdentity = $this->pickDbModelIdentity($promptRow, 'article_sm_neutral_revision_model_identity', $modelIdentity);
                $revisionOptions = [
                    'model_identity' => $neutralRevisionModelIdentity,
                    'temperature' => 0.35,
                    'response_format' => ['type' => 'json_object'],
                ];
                $revisedJson = $this->llmJson($userId, [
                    ['role' => 'system', 'content' => $revisionSystem],
                    ['role' => 'user', 'content' => $revisionUser],
                ], $revisionOptions);
                $revisedTextFallback = '';
                if (!is_array($revisedJson)) {
                    $revisedTextFallback = $this->llmText($userId, [
                        ['role' => 'system', 'content' => $revisionSystem],
                        ['role' => 'user', 'content' => $revisionUser],
                    ], $revisionOptions);
                    $parsedFallback = $this->extractJson($revisedTextFallback);
                    if (is_array($parsedFallback)) $revisedJson = $parsedFallback;
                }

                $valToText = function ($v): string {
                    if (is_string($v)) return $v;
                    if (is_scalar($v)) return (string)$v;
                    if (is_array($v)) {
                        if (isset($v['text']) && is_string($v['text'])) return (string)$v['text'];
                        $allStr = true;
                        $parts = [];
                        foreach ($v as $it) {
                            if (is_string($it) || is_scalar($it)) {
                                $parts[] = (string)$it;
                            } else {
                                $allStr = false;
                                break;
                            }
                        }
                        if ($allStr && $parts) return implode("\n", $parts);
                        $j = json_encode($v, JSON_UNESCAPED_UNICODE);
                        return is_string($j) ? $j : '';
                    }
                    $j2 = json_encode($v, JSON_UNESCAPED_UNICODE);
                    return is_string($j2) ? $j2 : '';
                };

                $revisedTitle = is_array($revisedJson) ? trim($valToText($revisedJson['title'] ?? '')) : '';
                $revisedAltTitle = is_array($revisedJson) ? trim($valToText($revisedJson['alternativeTitle'] ?? '')) : '';
                $revisedMainText = '';
                if (is_array($revisedJson)) {
                    if (isset($revisedJson['mainText'])) $revisedMainText = $valToText($revisedJson['mainText']);
                    elseif (isset($revisedJson['main_text'])) $revisedMainText = $valToText($revisedJson['main_text']);
                    elseif (isset($revisedJson['content'])) $revisedMainText = $valToText($revisedJson['content']);
                }
                if (trim($revisedMainText) === '') $revisedMainText = (string)$revisedTextFallback;
                $revisedMainText = trim($revisedMainText);

                if ($revisedMainText !== '') {
                    $revisedMainText = $this->stripUrlsFromMarkdown($revisedMainText);
                }
                if ($revisedMainText !== '') {
                    $neutralDraft = $revisedMainText;
                    if ($revisedTitle !== '') {
                        $title = $revisedTitle;
                    } elseif ($revisedAltTitle !== '') {
                        $title = $revisedAltTitle;
                    }
                    $artifactPayload = is_array($revisedJson) ? $revisedJson : null;
                    $this->saveArtifact($taskId, 'neutral_draft', $artifactPayload, $neutralDraft, 2);
                    $this->updateWorkResource($workResourceId, (int)$userId, $tenantId !== null ? (int)$tenantId : null, $title ?: null, $neutralDraft);
                }

                if ($this->isCancelled($redis, $taskId)) {
                    $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_NEUTRAL_REVISION', 'progress' => '77']);
                    $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                    $job->delete();
                    return;
                }
            }

            if ($styleProfileId <= 0) {
                $waitStage = $neutralRevisionEnabled ? 'STAGE_NEUTRAL_REVISION' : 'STAGE_NEUTRAL_DRAFT';
                $waitProgress = $neutralRevisionEnabled ? '77' : '75';
                $this->setTaskStatus($redis, $taskKey, [
                    'status' => 'WAIT_STYLE_TRANSFER',
                    'stage' => $waitStage,
                    'progress' => $waitProgress,
                ]);
                $this->updateTaskRow($taskId, [
                    'status' => 'WAIT_STYLE_TRANSFER',
                    'stage' => $waitStage,
                ]);
                $job->delete();
                return;
            }

            $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_STYLE_TRANSFER', 'stage' => 'STAGE_STYLE_TRANSFER', 'progress' => '78']);
            $this->updateTaskRow($taskId, ['status' => 'STAGE_STYLE_TRANSFER', 'stage' => 'STAGE_STYLE_TRANSFER']);

            $transferSystem = $this->pickDbPrompt($promptRow, 'article_sm_style_transfer_system_prompt', "你是风格迁移改写器。只能改写表达与节奏，不能改变事实、逻辑与结构。引用编号必须原样保留。必须严格遵守禁止项。输出 Markdown 正文。");
            $styleGuide = $this->formatStyleRuntimeGuide($styleRuntime);
            $transferUserTpl = $this->pickDbPrompt($promptRow, 'article_sm_style_transfer_user_prompt', "风格指南（严格执行）：\n{{STYLE_GUIDE}}\n\n中性稿（待改写）：\n{{NEUTRAL_DRAFT}}\n\n要求：\n- 不新增未经证实的信息\n- 禁止输出任何 URL（包含 http/https/域名）\n- 每段引用上限 2 个，引用编号格式保持不变\n- 使用短句、口语化与节奏感，尽量贴近作者口吻\n- 模板用于语气与节奏，不要机械复读");
            $transferUser = $this->renderPromptTemplate($transferUserTpl, [
                'STYLE_GUIDE' => $styleGuide,
                'NEUTRAL_DRAFT' => $neutralDraft,
            ]);
            $styleTransferModelIdentity = $this->pickDbModelIdentity($promptRow, 'article_sm_style_transfer_model_identity', $modelIdentity);
            $styledDraftRaw = $this->llmText($userId, [
                ['role' => 'system', 'content' => $transferSystem],
                ['role' => 'user', 'content' => $transferUser],
            ], [
                'model_identity' => $styleTransferModelIdentity,
                'temperature' => 0.7,
            ]);
            $styledDraft = (string)$styledDraftRaw;
            $this->saveArtifact($taskId, 'styled_draft', null, $styledDraft, 1);

            if ($this->isCancelled($redis, $taskId)) {
                $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_STYLE_TRANSFER', 'progress' => '82']);
                $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                $job->delete();
                return;
            }

            $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_STYLE_QA', 'stage' => 'STAGE_STYLE_QA', 'progress' => '85']);
            $this->updateTaskRow($taskId, ['status' => 'STAGE_STYLE_QA', 'stage' => 'STAGE_STYLE_QA']);

            $qaSystem = $this->pickDbPrompt($promptRow, 'article_sm_style_qa_system_prompt', "你是风格评审器。你必须只输出严格 JSON，不得输出任何其他文本。");
            $qaUserTpl = $this->pickDbPrompt($promptRow, 'article_sm_style_qa_user_prompt', "风格评审清单：\n{{STYLE_JUDGE_CHECKLIST_JSON}}\n\n禁止项 must_not：\n{{MUST_NOT_JSON}}\n\n正文：\n{{STYLED_DRAFT}}\n\n输出 JSON：{\n  \"dimension_scores\": [{\"name\":\"\",\"score\":0.0}],\n  \"violations\": [],\n  \"weak_segments\": [],\n  \"rewrite_tasks\": [{\"segment_hint\":\"\",\"instruction\":\"\"}],\n  \"pass\": true\n}");
            $qaUser = $this->renderPromptTemplate($qaUserTpl, [
                'STYLE_JUDGE_CHECKLIST_JSON' => json_encode($styleRuntime['style_judge_checklist'] ?? [], JSON_UNESCAPED_UNICODE),
                'MUST_NOT_JSON' => json_encode($styleRuntime['must_not'] ?? [], JSON_UNESCAPED_UNICODE),
                'STYLED_DRAFT' => $styledDraft,
            ]);
            $styleQaModelIdentity = $this->pickDbModelIdentity($promptRow, 'article_sm_style_qa_model_identity', $modelIdentity);
            $styleReport = $this->llmJson($userId, [
                ['role' => 'system', 'content' => $qaSystem],
                ['role' => 'user', 'content' => $qaUser],
            ], [
                'model_identity' => $styleQaModelIdentity,
                'temperature' => 0.2,
            ]);
            if (!is_array($styleReport)) $styleReport = ['pass' => true, 'dimension_scores' => [], 'violations' => [], 'weak_segments' => [], 'rewrite_tasks' => []];
            $this->saveArtifact($taskId, 'style_report', $styleReport, null, 1);

            $styledV2 = $styledDraft;
            if (!(bool)($styleReport['pass'] ?? true) && is_array($styleReport['rewrite_tasks'] ?? null) && count($styleReport['rewrite_tasks']) > 0) {
                $rewriteSystem = $this->pickDbPrompt($promptRow, 'article_sm_style_rewrite_system_prompt', "你是局部重写器。只重写被指出的问题段落，保持事实与引用编号不变。禁止输出任何 URL。输出完整 Markdown 正文。");
                $rewriteUserTpl = $this->pickDbPrompt($promptRow, 'article_sm_style_rewrite_user_prompt', "重写任务：\n{{REWRITE_TASKS_JSON}}\n\n原正文：\n{{STYLED_DRAFT}}");
                $rewriteUser = $this->renderPromptTemplate($rewriteUserTpl, [
                    'REWRITE_TASKS_JSON' => json_encode($styleReport['rewrite_tasks'], JSON_UNESCAPED_UNICODE),
                    'STYLED_DRAFT' => $styledDraft,
                ]);
                $styleRewriteModelIdentity = $this->pickDbModelIdentity($promptRow, 'article_sm_style_rewrite_model_identity', $modelIdentity);
                $styledV2Raw = $this->llmText($userId, [
                    ['role' => 'system', 'content' => $rewriteSystem],
                    ['role' => 'user', 'content' => $rewriteUser],
                ], [
                    'model_identity' => $styleRewriteModelIdentity,
                    'temperature' => 0.7,
                ]);
                $styledV2 = (string)$styledV2Raw;
                $this->saveArtifact($taskId, 'styled_draft', null, $styledV2, 2);
            }

            if ($this->isCancelled($redis, $taskId)) {
                $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_STYLE_QA', 'progress' => '88']);
                $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                $job->delete();
                return;
            }

            $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_RISK_CHECK', 'stage' => 'STAGE_RISK_CHECK', 'progress' => '90']);
            $this->updateTaskRow($taskId, ['status' => 'STAGE_RISK_CHECK', 'stage' => 'STAGE_RISK_CHECK']);

            $riskReport = $this->riskCheck($styledV2, $userId, $tenantId !== null ? (int)$tenantId : null, $profile);
            $this->saveArtifact($taskId, 'risk_report', $riskReport, null, 1);

            $finalDraft = $styledV2;
            if ((bool)($riskReport['rewrite_required'] ?? false) && is_array($riskReport['flagged_segments'] ?? null) && count($riskReport['flagged_segments']) > 0) {
                $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_REWRITE_SEGMENTS', 'stage' => 'STAGE_REWRITE_SEGMENTS', 'progress' => '93']);
                $this->updateTaskRow($taskId, ['status' => 'STAGE_REWRITE_SEGMENTS', 'stage' => 'STAGE_REWRITE_SEGMENTS']);

                $rSystem = $this->pickDbPrompt($promptRow, 'article_sm_risk_rewrite_system_prompt', "你是风险段落重写器。目标：降低与作者语料的表达相似度，但保持事实与逻辑不变。引用编号必须原样保留。禁止输出任何 URL。输出完整 Markdown 正文。");
                $rUserTpl = $this->pickDbPrompt($promptRow, 'article_sm_risk_rewrite_user_prompt', "风险报告：\n{{RISK_REPORT_JSON}}\n\n原正文：\n{{STYLED_DRAFT}}");
                $rUser = $this->renderPromptTemplate($rUserTpl, [
                    'RISK_REPORT_JSON' => json_encode($riskReport, JSON_UNESCAPED_UNICODE),
                    'STYLED_DRAFT' => $styledV2,
                ]);
                $riskRewriteModelIdentity = $this->pickDbModelIdentity($promptRow, 'article_sm_risk_rewrite_model_identity', $modelIdentity);
                $finalDraftRaw = $this->llmText($userId, [
                    ['role' => 'system', 'content' => $rSystem],
                    ['role' => 'user', 'content' => $rUser],
                ], [
                    'model_identity' => $riskRewriteModelIdentity,
                    'temperature' => 0.7,
                ]);
                $finalDraft = (string)$finalDraftRaw;
                $this->saveArtifact($taskId, 'styled_draft', null, $finalDraft, 3);
            }

            if ($this->isCancelled($redis, $taskId)) {
                $this->setTaskStatus($redis, $taskKey, ['status' => 'CANCELLED', 'stage' => 'STAGE_FINALIZE', 'progress' => '96']);
                $this->updateTaskRow($taskId, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
                $job->delete();
                return;
            }

            $this->setTaskStatus($redis, $taskKey, ['status' => 'STAGE_FINALIZE', 'stage' => 'STAGE_FINALIZE', 'progress' => '97']);
            $this->updateTaskRow($taskId, ['status' => 'STAGE_FINALIZE', 'stage' => 'STAGE_FINALIZE']);

            $final = trim((string)$finalDraft);
            if ($final === '') $final = trim((string)$styledDraft);

            $this->saveArtifact($taskId, 'final_article', null, $final, 1);

            $this->updateWorkResource($workResourceId, (int)$userId, $tenantId !== null ? (int)$tenantId : null, $title ?: null, $final);

            $this->setTaskStatus($redis, $taskKey, [
                'status' => 'SUCCEEDED',
                'stage' => 'SUCCEEDED',
                'progress' => '100',
            ]);
            $this->updateTaskRow($taskId, [
                'status' => 'SUCCEEDED',
                'stage' => 'SUCCEEDED',
                'finished_at' => date('Y-m-d H:i:s'),
                'error_message' => null,
            ]);

            $job->delete();
            return;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $cur = $this->getTaskStatus($redis, $taskKey);
            $curStage = is_array($cur) ? (string)($cur['stage'] ?? '') : '';
            $curProgress = is_array($cur) ? (string)($cur['progress'] ?? '0') : '0';
            $this->setTaskStatus($redis, $taskKey, [
                'status' => 'FAILED',
                'stage' => $curStage,
                'progress' => $curProgress,
                'error_message' => $msg,
            ]);
            $this->updateTaskRow($taskId, [
                'status' => 'FAILED',
                'error_message' => $msg,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            Log::error('WritingTaskJob failed: ' . $msg);
            $job->delete();
            return;
        }
    }
}
