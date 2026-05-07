<?php
namespace app\job;

use think\queue\Job;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use app\service\LlmService;

class StyleProfileGenerateJob
{
    protected function ensureResourcesTable()
    {
        try { Db::execute("CREATE TABLE IF NOT EXISTS `resources` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `resource_id` VARCHAR(32) NULL,
            `tenant_id` INT NULL,
            `user_id` INT NULL,
            `style_id` VARCHAR(50) NULL,
            `type` VARCHAR(20) NULL,
            `title` VARCHAR(255) NULL,
            `url` VARCHAR(1024) NULL,
            `content` LONGTEXT NULL,
            `status` VARCHAR(20) DEFAULT 'normal',
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_user_style` (`user_id`, `style_id`),
            INDEX `idx_tenant_user_style` (`tenant_id`, `user_id`, `style_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `resource_id` VARCHAR(32) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `tenant_id` INT NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `user_id` INT NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `style_id` VARCHAR(50) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `type` VARCHAR(20) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `title` VARCHAR(255) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `url` VARCHAR(1024) NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `content` LONGTEXT NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `status` VARCHAR(20) DEFAULT 'normal' COMMENT 'normal, hidden, deleted'"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `created_at` DATETIME NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD COLUMN `updated_at` DATETIME NULL"); } catch (\Throwable $e) {}
        try { Db::execute("ALTER TABLE `resources` ADD INDEX `idx_user_style` (`user_id`, `style_id`)"); } catch (\Throwable $e) {}
    }

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

    protected function setTaskStatus($redis, $taskKey, array $data)
    {
        $data['updated_at'] = isset($data['updated_at']) ? (string)$data['updated_at'] : (string)time();
        if ($redis) {
            try {
                $redis->hMSet($taskKey, $data);
                return;
            } catch (\Throwable $e) {
            }
        }
        $existing = Cache::get($taskKey);
        if (is_array($existing)) {
            $data = array_merge($existing, $data);
        }
        Cache::set($taskKey, $data, 3600);
    }

    protected function getCorpusRows($tenantId, $userId, $styleId)
    {
        $q = Db::table('resources')->where('status', 'normal')->where('style_id', $styleId);
        if ($tenantId !== null) {
            $q = $q->where('tenant_id', $tenantId);
        }
        if ($userId !== null) {
            $q = $q->where('user_id', $userId);
        }
        $q = $q->whereIn('type', ['note', 'link'])->order('id', 'desc');
        return $q->select()->toArray();
    }

    protected function buildCorpus(array $rows, int $maxDocChars = 6000, int $maxTotalChars = 32000)
    {
        $docs = [];
        $total = 0;
        foreach ($rows as $r) {
            $rid = isset($r['resource_id']) ? (string)$r['resource_id'] : '';
            $title = isset($r['title']) ? (string)$r['title'] : '';
            $content = isset($r['content']) ? (string)$r['content'] : '';
            $content = trim($content);
            if ($rid === '' || $content === '') {
                continue;
            }
            if (mb_strlen($content) > $maxDocChars) {
                $content = mb_substr($content, 0, $maxDocChars);
            }
            $block = "ARTICLE_ID: {$rid}\nTITLE: {$title}\nCONTENT:\n{$content}\n";
            $len = mb_strlen($block);
            if ($total + $len > $maxTotalChars) {
                break;
            }
            $total += $len;
            $docs[] = [
                'resource_id' => $rid,
                'title' => $title,
                'content' => $content,
                'block' => $block,
            ];
        }
        return $docs;
    }

    protected function computeInputHash(array $docs)
    {
        $parts = [];
        foreach ($docs as $d) {
            $rid = isset($d['resource_id']) ? (string)$d['resource_id'] : '';
            $content = isset($d['content']) ? (string)$d['content'] : '';
            $parts[] = $rid . ':' . md5($content);
        }
        return hash('sha256', implode('|', $parts));
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

    protected function sanitizeStyleProfile($v, ?string $parentKey = null)
    {
        if (!is_array($v)) return $v;
        $out = [];
        foreach ($v as $k => $vv) {
            if ($k === 'evidence') continue;
            if ($k === 'source_articles') continue;
            if ($k === 'risk_controls') continue;
            if ($k === 'signature_phrases') continue;
            if ($parentKey === 'compiled_constraints' && ($k === 'must' || $k === 'templates')) continue;

            $out[$k] = $this->sanitizeStyleProfile($vv, (string)$k);
        }
        return $out;
    }

    public function fire(Job $job, $data)
    {
        $this->ensureTable();
        $this->ensureResourcesTable();

        $taskId = isset($data['task_id']) ? (string)$data['task_id'] : '';
        $tenantId = $data['tenant_id'] ?? null;
        $userId = $data['user_id'] ?? null;
        $styleId = isset($data['style_id']) ? (string)$data['style_id'] : '';

        $taskKey = 'style_profile_task:' . $taskId;
        $redis = $this->getRedis();

        try {
            $this->setTaskStatus($redis, $taskKey, [
                'status' => 'processing',
                'style_id' => $styleId,
                'stage' => 'loading_corpus',
                'phase' => 'loading_corpus',
                'progress' => '10',
            ]);

            $rows = $this->getCorpusRows($tenantId, $userId, $styleId);
            $docs = $this->buildCorpus($rows, 4000, 20000);
            if (count($docs) === 0) {
                $this->setTaskStatus($redis, $taskKey, [
                    'status' => 'failed',
                    'style_id' => $styleId,
                    'stage' => 'loading_corpus',
                    'phase' => 'loading_corpus',
                    'progress' => '10',
                    'error' => '没有可用于分析的资料内容（仅支持笔记/链接）',
                ]);
                $job->delete();
                return;
            }

            $inputHash = $this->computeInputHash($docs);

            $this->setTaskStatus($redis, $taskKey, [
                'status' => 'processing',
                'style_id' => $styleId,
                'stage' => 'analyzing',
                'phase' => 'analyzing',
                'progress' => '40',
                'progress_total' => (string)count($docs),
                'progress_done' => '0',
                'input_hash' => $inputHash,
            ]);

            $corpusText = implode("\n\n", array_map(function ($d) { return $d['block']; }, $docs));

            $promptRow = null;
            try {
                $promptRow = Db::table('system_prompts')->order('id', 'desc')->find();
            } catch (\Throwable $e) {
                $promptRow = null;
            }

            $systemPrompt = is_array($promptRow) ? trim((string)($promptRow['style_profile_system_prompt'] ?? '')) : '';
            if ($systemPrompt === '') {
                $systemPrompt = "你是作者写作风格分析引擎。你必须只输出严格 JSON，不得输出任何其他文本（不允许 Markdown，不允许解释，不允许代码块）。\n\n目标：根据给定语料，生成可被写作/改写系统直接消费的 Style Profile（精简版）。\n\n硬性要求：\n- 只根据语料内容推断，不得编造不存在的事实。\n- 禁止在输出中复刻、粘贴或引用语料原句/原段落；禁止输出任何引文/摘录/quote/证据。\n- 输出中不得包含：evidence、signature_phrases、risk_controls、source_articles；compiled_constraints 内不得包含 must、templates。\n- 所有规则必须可执行、可操作：用写作行为描述（应该怎么写/不要怎么写），避免空泛形容词堆砌。\n- 缺失字段用空字符串/空数组/0 填充；字段类型必须严格匹配结构。\n- author_tendency.score_0_10 与 knobs 的取值范围严格为 0~10（整数）；confidence 为 0~1（小数）。\n\n输出 JSON 结构：\n{\n  \"author_profile\": {\n    \"voice_persona\": \"\",\n    \"stance\": \"\",\n    \"reader_relationship\": \"\"\n  },\n  \"style_dimensions\": [\n    {\n      \"dimension_id\": \"\",\n      \"name\": \"\",\n      \"definition\": \"\",\n      \"author_tendency\": {\"description\": \"\", \"score_0_10\": 0},\n      \"rules\": {\"do\": [], \"dont\": []},\n      \"conditions\": [],\n      \"confidence\": 0\n    }\n  ],\n  \"lexicon\": {\n    \"preferred_connectors\": [{\"text\": \"\", \"count\": 0}],\n    \"taboo_patterns\": [{\"text\": \"\", \"count\": 0}]\n  },\n  \"metrics_baseline\": {\n    \"avg_sentence_length\": 0,\n    \"short_sentence_ratio\": 0,\n    \"question_ratio\": 0,\n    \"list_density\": 0,\n    \"connector_topk\": [{\"text\": \"\", \"count\": 0}]\n  },\n  \"compiled_constraints\": {\n    \"must_not\": [],\n    \"knobs\": [\n      {\"id\": \"\", \"name\": \"\", \"min\": 0, \"max\": 10, \"default\": 0, \"description\": \"\"}\n    ]\n  }\n}";
            }
            $systemPrompt .= "\n\n强制要求：输出 JSON 不得包含 evidence、signature_phrases、risk_controls、source_articles 字段；compiled_constraints 内不得包含 must、templates。";

            $userPrompt = "语料如下（每篇包含 ARTICLE_ID、TITLE、CONTENT）。请输出最终 JSON。\n\n语料：\n" . $corpusText;

            $llm = new LlmService();
            $res = $llm->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                [
                    'stream' => false,
                    'temperature' => 0.2,
                    'usage_type' => 'style_profile',
                    'timeout' => 300,
                    'connect_timeout' => 15,
                    'retry' => 1,
                    'retry_delay_ms' => 800,
                ],
                $userId
            );

            $this->setTaskStatus($redis, $taskKey, [
                'status' => 'processing',
                'style_id' => $styleId,
                'stage' => 'analyzing',
                'phase' => 'analyzing',
                'progress' => '70',
                'progress_total' => (string)count($docs),
                'progress_done' => (string)count($docs),
                'input_hash' => $inputHash,
            ]);

            $profileArr = $this->extractJson(isset($res['content']) ? (string)$res['content'] : '');
            if (!is_array($profileArr)) {
                $this->setTaskStatus($redis, $taskKey, [
                    'status' => 'failed',
                    'style_id' => $styleId,
                    'stage' => 'analyzing',
                    'phase' => 'analyzing',
                    'progress' => '70',
                    'error' => '模型返回非 JSON',
                ]);
                $job->delete();
                return;
            }

            $profileArr = $this->sanitizeStyleProfile($profileArr);

            $this->setTaskStatus($redis, $taskKey, [
                'status' => 'processing',
                'style_id' => $styleId,
                'stage' => 'saving',
                'phase' => 'saving',
                'progress' => '90',
                'input_hash' => $inputHash,
            ]);

            $profileJson = json_encode($profileArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($profileJson) || $profileJson === '') {
                $this->setTaskStatus($redis, $taskKey, [
                    'status' => 'failed',
                    'style_id' => $styleId,
                    'stage' => 'saving',
                    'phase' => 'saving',
                    'progress' => '90',
                    'error' => 'JSON 编码失败',
                ]);
                $job->delete();
                return;
            }

            $now = date('Y-m-d H:i:s');
            $insert = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'style_id' => $styleId,
                'input_hash' => $inputHash,
                'profile_json' => $profileJson,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $profileId = Db::table('style_profiles')->insertGetId($insert);

            $this->setTaskStatus($redis, $taskKey, [
                'status' => 'success',
                'style_id' => $styleId,
                'stage' => 'done',
                'phase' => 'done',
                'progress' => '100',
                'result' => json_encode(['profile_id' => (string)$profileId, 'input_hash' => $inputHash], JSON_UNESCAPED_UNICODE),
            ]);

            $job->delete();
        } catch (\Throwable $e) {
            Log::error('StyleProfileGenerateJob failed: ' . $e->getMessage());
            $this->setTaskStatus($redis, $taskKey, [
                'status' => 'failed',
                'style_id' => $styleId,
                'stage' => 'exception',
                'phase' => 'exception',
                'progress' => '0',
                'error' => $e->getMessage(),
            ]);
            $job->delete();
        }
    }
}
