<?php
namespace app\controller\api;

use app\BaseController;
use app\service\ImageService;
use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use andreskrey\Readability\Configuration;
use andreskrey\Readability\ParseException;
use andreskrey\Readability\Readability;
use Symfony\Component\DomCrawler\Crawler;
use think\facade\Db;
use think\facade\Filesystem;
use think\facade\Log;
use think\Request;

class Resource extends BaseController
{
    protected function ensureTable()
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
            Log::error('Ensure resources table failed: ' . $e->getMessage());
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

    protected function normalizeUrl($url)
    {
        $trimmed = trim((string)$url);
        if ($trimmed === '') return '';
        if (preg_match('/^(https?:\/\/)/i', $trimmed)) return $trimmed;
        return 'https://' . $trimmed;
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

    protected function isPrivateOrReservedIp(string $ip): bool
    {
        $ranges = [
            '0.0.0.0/8',
            '10.0.0.0/8',
            '100.64.0.0/10',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.0.0.0/24',
            '192.0.2.0/24',
            '192.168.0.0/16',
            '198.18.0.0/15',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '224.0.0.0/4',
            '240.0.0.0/4',
        ];
        foreach ($ranges as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) return true;
        }
        return false;
    }

    protected function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) return false;
        [$subnet, $bitsStr] = $parts;
        $bits = (int)$bitsStr;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;
        if ($bits <= 0) return true;
        if ($bits >= 32) return $ipLong === $subnetLong;
        $mask = -1 << (32 - $bits);
        return (($ipLong & $mask) === ($subnetLong & $mask));
    }

    protected function isPrivateOrReservedIpv6(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) return true;

        if ($bin === str_repeat("\0", 16)) return true;
        if ($bin === str_repeat("\0", 15) . "\x01") return true;

        $b0 = ord($bin[0]);
        $b1 = ord($bin[1]);

        if (($b0 & 0xFE) === 0xFC) return true;
        if ($b0 === 0xFE && (($b1 & 0xC0) === 0x80)) return true;

        return false;
    }

    protected function fetchHtml(string $url): array
    {
        if (!$this->isAllowedRemoteUrl($url)) {
            return ['html' => null, 'url' => null];
        }

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
            $res = $client->request('GET', $url, [
                'http_errors' => false,
                'allow_redirects' => ['max' => 5, 'track_redirects' => true],
                'on_stats' => function (TransferStats $stats) use (&$effectiveUrl) {
                    $effectiveUrl = (string)$stats->getEffectiveUri();
                },
            ]);
        } catch (\Throwable $e) {
            return ['html' => null, 'url' => null];
        }

        $finalUrl = $effectiveUrl ?: $url;
        if (!$this->isAllowedRemoteUrl($finalUrl)) {
            return ['html' => null, 'url' => null];
        }

        $status = (int)$res->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return ['html' => null, 'url' => null];
        }

        $contentType = strtolower((string)$res->getHeaderLine('Content-Type'));
        if ($contentType !== '' && (strpos($contentType, 'text/html') === false) && (strpos($contentType, 'application/xhtml+xml') === false)) {
            return ['html' => null, 'url' => null];
        }

        $html = (string)$res->getBody();
        if ($html === '') return ['html' => null, 'url' => null];

        $maxBytes = 1500000;
        if (strlen($html) > $maxBytes) {
            $html = substr($html, 0, $maxBytes);
        }

        return ['html' => $html, 'url' => $finalUrl];
    }

    protected function normalizeText(string $text): string
    {
        $s = str_replace(["\xC2\xA0", "\xE3\x80\x80"], ' ', (string)$text);
        $s = preg_replace("/\r\n|\r/", "\n", $s);
        $s = preg_replace("/[ \t]+/u", " ", $s);
        $s = preg_replace("/[ ]+\n/u", "\n", $s);
        $s = preg_replace("/\n[ ]+/u", "\n", $s);
        $s = preg_replace("/\n{3,}/u", "\n\n", $s);
        $s = preg_replace('/([\x{4e00}-\x{9fff}])\s+([\x{4e00}-\x{9fff}])/u', '$1$2', $s);
        $s = preg_replace('/([\x{4e00}-\x{9fff}])\s+([，。！？；：、])/u', '$1$2', $s);
        $s = preg_replace('/([，。！？；：、])\s+([\x{4e00}-\x{9fff}])/u', '$1$2', $s);
        return trim((string)$s);
    }

    protected function removeNoiseLines(string $text): string
    {
        $lines = preg_split("/\n/u", (string)$text);
        if (!is_array($lines)) return trim((string)$text);

        $patterns = [
            '/关注\s*(?:我们|公众号|微信公众号|微信公众平台|订阅号|服务号)/u',
            '/(?:长按|扫码).{0,20}(?:识别|关注)/u',
            '/点击\s*(?:阅读|查看)?\s*原文/u',
            '/阅读\s*原文/u',
            '/本文\s*(?:转载自|转自|来源于|整理自)/u',
            '/转载\s*声明/u',
            '/版权\s*声明/u',
            '/免责声明/u',
            '/广告/u',
            '/推荐\s*阅读/u',
            '/更多\s*(?:精彩|内容|文章)/u',
            '/上一篇|下一篇/u',
            '/责任编辑[:：]/u',
            '/原标题[:：]/u',
        ];

        $kept = [];
        foreach ($lines as $line) {
            $t = trim((string)$line);
            if ($t === '') {
                $kept[] = '';
                continue;
            }
            $drop = false;
            foreach ($patterns as $p) {
                if (preg_match($p, $t)) {
                    $drop = true;
                    break;
                }
            }
            if ($drop) continue;
            $kept[] = $t;
        }

        $out = implode("\n", $kept);
        $out = preg_replace("/\n{3,}/u", "\n\n", (string)$out);
        return trim((string)$out);
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

        $blocks = $root->filter('h1,h2,h3,h4,p,li,blockquote,pre,img');
        if ($blocks->count() === 0) {
            $plain = $root->text('', true);
            $buf = $plain;
            try {
                $imgs = $root->filter('img');
                foreach ($imgs as $img) {
                    if (!$img instanceof \DOMElement) continue;
                    $src = trim((string)$img->getAttribute('src'));
                    if ($src === '') $src = trim((string)$img->getAttribute('data-src'));
                    if ($src === '') $src = trim((string)$img->getAttribute('data-original'));
                    if ($src === '' || stripos($src, 'data:') === 0) continue;
                    $alt = trim((string)$img->getAttribute('alt'));
                    $alt = $this->normalizeText(html_entity_decode($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $buf .= "\n\n![" . $alt . "](" . $src . ")\n";
                }
            } catch (\Throwable $e) {
            }
            return $this->removeNoiseLines($this->normalizeText($buf));
        }

        $buf = '';
        foreach ($blocks as $node) {
            if (!$node instanceof \DOMElement) continue;
            $tag = strtolower($node->tagName);
            if ($tag === 'img') {
                $src = trim((string)$node->getAttribute('src'));
                if ($src === '') $src = trim((string)$node->getAttribute('data-src'));
                if ($src === '') $src = trim((string)$node->getAttribute('data-original'));
                if ($src === '' || stripos($src, 'data:') === 0) continue;
                $alt = trim((string)$node->getAttribute('alt'));
                $alt = $this->normalizeText(html_entity_decode($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $buf .= "\n\n![" . $alt . "](" . $src . ")\n";
                continue;
            }

            $text = trim((string)$node->textContent);
            if ($text === '') continue;

            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = $this->normalizeText($text);
            if ($text === '') continue;

            if ($tag === 'h1') $buf .= "\n\n# " . $text . "\n";
            else if ($tag === 'h2') $buf .= "\n\n## " . $text . "\n";
            else if ($tag === 'h3') $buf .= "\n\n### " . $text . "\n";
            else if ($tag === 'h4') $buf .= "\n\n#### " . $text . "\n";
            else if ($tag === 'pre') $buf .= "\n\n" . $text . "\n";
            else if ($tag === 'blockquote') $buf .= "\n\n> " . $text . "\n";
            else $buf .= $text . "\n";
        }

        $buf = $this->normalizeText($buf);
        $buf = $this->removeNoiseLines($buf);
        return $buf;
    }

    protected function scrapeReadable(string $url): array
    {
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
            $fallbackTitle = $this->extractTitleFromHtml($html);
            $fallbackContent = $this->htmlToCleanText($html);
            return ['title' => $fallbackTitle, 'content' => $fallbackContent, 'url' => $finalUrl];
        } catch (\Throwable $e) {
            return ['title' => '', 'content' => '', 'url' => $finalUrl];
        }

        $title = trim((string)$readability->getTitle());
        $contentHtml = (string)$readability->getContent();
        $content = $this->htmlToCleanText($contentHtml);
        if ($content === '') {
            $content = $this->htmlToCleanText($html);
        }

        if ($title === '') {
            $title = $this->extractTitleFromHtml($html);
        }

        return ['title' => $title, 'content' => $content, 'url' => $finalUrl];
    }

    protected function extractTitleFromHtml(string $html): string
    {
        $title = '';
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        try {
            if (@$dom->loadHTML($html)) {
                $nodes = $dom->getElementsByTagName('title');
                if ($nodes && $nodes->length > 0) {
                    $title = trim((string)$nodes->item(0)->textContent);
                }
            }
        } catch (\Throwable $e) {
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        return $title;
    }

    protected function mapRow($row)
    {
        return [
            'resource_id' => $row['resource_id'] ?? '',
            'style_id' => $row['style_id'] ?? '',
            'type' => $row['type'] ?? '',
            'title' => $row['title'] ?? '',
            'url' => $row['url'] ?? '',
            'task_id' => $row['task_id'] ?? '',
            'task_status_json' => $row['task_status_json'] ?? '',
            'style_profile_id' => $row['style_profile_id'] ?? null,
            'topic' => $row['topic'] ?? '',
            'genre' => $row['genre'] ?? '',
            'word_count' => $row['word_count'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public function list(Request $request)
    {
        $this->ensureTable();
        try {
            $tenantId = $request->tenantId ?? null;
            $userId = $request->userId ?? null;
            $styleId = (string)$request->param('style_id', '');

            $query = Db::table('resources')->order('id', 'desc')->where('status', 'normal');
            if ($tenantId) $query = $query->where('tenant_id', $tenantId);
            if ($userId) $query = $query->where('user_id', $userId);
            if ($styleId !== '') $query = $query->where('style_id', $styleId);

            $rows = $query->select()->toArray();
            foreach ($rows as &$row) {
                if (empty($row['resource_id'])) {
                    try {
                        $newUid = bin2hex(random_bytes(16));
                        Db::table('resources')->where('id', $row['id'])->update([
                            'resource_id' => $newUid,
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                        $row['resource_id'] = $newUid;
                    } catch (\Throwable $e) {}
                }
            }
            unset($row);

            $items = array_map(function ($r) { return $this->mapRow($r); }, $rows);
            return json(['code' => 200, 'msg' => 'Success', 'data' => ['items' => $items]]);
        } catch (\Throwable $e) {
            Log::error('Resource list failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function detail(Request $request)
    {
        $this->ensureTable();
        try {
            $tenantId = $request->tenantId ?? null;
            $userId = $request->userId ?? null;
            $resourceId = (string)$request->param('resource_id', '');
            if ($resourceId === '') {
                return json(['code' => 400, 'msg' => 'Missing resource_id', 'data' => null]);
            }

            $query = Db::table('resources')->where('resource_id', $resourceId)->where('status', 'normal');
            if ($tenantId) $query = $query->where('tenant_id', $tenantId);
            if ($userId) $query = $query->where('user_id', $userId);
            $row = $query->find();
            if (!$row) {
                return json(['code' => 404, 'msg' => 'Resource not found', 'data' => null]);
            }

            $item = $this->mapRow($row);
            $item['content'] = $row['content'] ?? '';
            return json(['code' => 200, 'msg' => 'Success', 'data' => ['item' => $item]]);
        } catch (\Throwable $e) {
            Log::error('Resource detail failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function update(Request $request)
    {
        $this->ensureTable();
        try {
            $tenantId = $request->tenantId ?? null;
            $userId = $request->userId ?? null;
            $resourceId = trim((string)$request->post('resource_id', ''));
            if ($resourceId === '') {
                return json(['code' => 400, 'msg' => 'Missing resource_id', 'data' => null]);
            }

            $query = Db::table('resources')->where('resource_id', $resourceId)->where('status', 'normal');
            if ($tenantId) $query = $query->where('tenant_id', $tenantId);
            if ($userId) $query = $query->where('user_id', $userId);
            $row = $query->find();
            if (!$row) {
                return json(['code' => 404, 'msg' => 'Resource not found', 'data' => null]);
            }

            $title = $request->post('title', null);
            $content = $request->post('content', null);
            $updates = ['updated_at' => date('Y-m-d H:i:s')];
            if ($title !== null) {
                $updates['title'] = trim((string)$title);
            }
            if ($content !== null) {
                $updates['content'] = (string)$content;
            }

            Db::table('resources')->where('id', $row['id'])->update($updates);

            $fresh = Db::table('resources')->where('id', $row['id'])->find();
            $item = $this->mapRow($fresh ?: $row);
            $item['content'] = ($fresh['content'] ?? $row['content'] ?? '');
            return json(['code' => 200, 'msg' => 'Success', 'data' => ['item' => $item]]);
        } catch (\Throwable $e) {
            Log::error('Resource update failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function createNote(Request $request)
    {
        return $this->createBasic($request, 'note');
    }

    public function createWork(Request $request)
    {
        $this->ensureTable();
        try {
            $tenantId = $request->tenantId ?? null;
            $userId = $request->userId ?? null;
            if (!$userId) {
                return json(['code' => 401, 'msg' => 'Unauthorized', 'data' => null]);
            }

            $styleId = (string)$request->post('style_id', '');
            $title = trim((string)$request->post('title', ''));
            if ($title === '') $title = '未命名作品';

            $uid = bin2hex(random_bytes(16));
            $now = date('Y-m-d H:i:s');
            Db::table('resources')->insert([
                'resource_id' => $uid,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'style_id' => $styleId !== '' ? $styleId : null,
                'type' => 'work',
                'title' => $title,
                'url' => null,
                'content' => '',
                'task_id' => null,
                'task_status_json' => null,
                'style_profile_id' => null,
                'topic' => '',
                'genre' => '',
                'word_count' => null,
                'status' => 'normal',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = Db::table('resources')->where('resource_id', $uid)->find();
            $item = $this->mapRow($row ?: ['resource_id' => $uid, 'title' => $title, 'type' => 'work']);
            $item['content'] = '';
            return json(['code' => 200, 'msg' => 'Success', 'data' => ['item' => $item]]);
        } catch (\Throwable $e) {
            Log::error('Resource create work failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function createGroup(Request $request)
    {
        return $this->createBasic($request, 'group');
    }

    protected function createBasic(Request $request, string $type)
    {
        $this->ensureTable();
        try {
            $tenantId = $request->tenantId ?? null;
            $userId = $request->userId ?? null;
            $styleId = (string)$request->post('style_id', '');
            $title = trim((string)$request->post('title', ''));

            if ($styleId === '') {
                return json(['code' => 400, 'msg' => 'Missing style_id', 'data' => null]);
            }
            if ($title === '') {
                $title = $type === 'group' ? '未命名分组' : '新建笔记';
            }

            $uid = bin2hex(random_bytes(16));
            $now = date('Y-m-d H:i:s');
            Db::table('resources')->insert([
                'resource_id' => $uid,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'style_id' => $styleId,
                'type' => $type,
                'title' => $title,
                'url' => null,
                'content' => '',
                'status' => 'normal',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return json(['code' => 200, 'msg' => 'Success', 'data' => [
                'item' => [
                    'resource_id' => $uid,
                    'style_id' => $styleId,
                    'type' => $type,
                    'title' => $title,
                    'url' => '',
                    'content' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]]);
        } catch (\Throwable $e) {
            Log::error('Resource create failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function createLink(Request $request)
    {
        $this->ensureTable();
        try {
            $tenantId = $request->tenantId ?? null;
            $userId = $request->userId ?? null;
            $styleId = (string)$request->post('style_id', '');
            $url = $this->normalizeUrl($request->post('url', ''));
            $title = trim((string)$request->post('title', ''));

            if ($styleId === '') {
                return json(['code' => 400, 'msg' => 'Missing style_id', 'data' => null]);
            }
            if ($url === '') {
                return json(['code' => 400, 'msg' => 'Missing url', 'data' => null]);
            }
            if (!$this->isAllowedRemoteUrl($url)) {
                return json(['code' => 400, 'msg' => 'Invalid url', 'data' => null]);
            }

            $scrapedTitle = '';
            $scrapedContent = '';
            try {
                $scraped = $this->scrapeReadable($url);
                $scrapedTitle = trim((string)($scraped['title'] ?? ''));
                $scrapedContent = (string)($scraped['content'] ?? '');
                $url = (string)($scraped['url'] ?? $url);
            } catch (\Throwable $e) {
            }

            if ($title === '' || $title === '链接') {
                $title = $scrapedTitle !== '' ? $scrapedTitle : '链接';
            }

            $uid = bin2hex(random_bytes(16));
            $now = date('Y-m-d H:i:s');
            Db::table('resources')->insert([
                'resource_id' => $uid,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'style_id' => $styleId,
                'type' => 'link',
                'title' => $title,
                'url' => $url,
                'content' => $scrapedContent,
                'status' => 'normal',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return json(['code' => 200, 'msg' => 'Success', 'data' => [
                'item' => [
                    'resource_id' => $uid,
                    'style_id' => $styleId,
                    'type' => 'link',
                    'title' => $title,
                    'url' => $url,
                    'content' => $scrapedContent,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]]);
        } catch (\Throwable $e) {
            Log::error('Resource create link failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function uploadFile(Request $request)
    {
        $this->ensureTable();
        try {
            $tenantId = $request->tenantId ?? null;
            $userId = $request->userId ?? null;
            $styleId = (string)$request->post('style_id', '');
            $file = $request->file('file');

            if ($styleId === '') {
                return json(['code' => 400, 'msg' => 'Missing style_id', 'data' => null]);
            }
            if (!$file) {
                return json(['code' => 400, 'msg' => 'No file uploaded', 'data' => null]);
            }

            $name = method_exists($file, 'getOriginalName') ? $file->getOriginalName() : '';
            $ext = $name ? (pathinfo($name, PATHINFO_EXTENSION) ?: 'bin') : 'bin';
            $path = method_exists($file, 'getRealPath') ? $file->getRealPath() : (method_exists($file, 'getPathname') ? $file->getPathname() : null);

            $url = null;
            if ($path && is_readable($path)) {
                $binary = file_get_contents($path);
                $service = app(ImageService::class);
                $url = $service->storeBinary($binary, $ext, 'resources');
            }
            if (!$url) {
                $p = Filesystem::disk('public')->putFile('resources', $file);
                if ($p) {
                    $url = rtrim(request()->domain(), '/') . config('filesystem.disks.public.url') . '/' . str_replace('\\', '/', $p);
                }
            }
            if (!$url) {
                return json(['code' => 500, 'msg' => 'Upload failed', 'data' => null]);
            }

            $uid = bin2hex(random_bytes(16));
            $now = date('Y-m-d H:i:s');
            Db::table('resources')->insert([
                'resource_id' => $uid,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'style_id' => $styleId,
                'type' => 'file',
                'title' => $name ?: '文件',
                'url' => $url,
                'content' => '',
                'status' => 'normal',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return json(['code' => 200, 'msg' => 'Success', 'data' => [
                'item' => [
                    'resource_id' => $uid,
                    'style_id' => $styleId,
                    'type' => 'file',
                    'title' => $name ?: '文件',
                    'url' => $url,
                    'content' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]]);
        } catch (\Throwable $e) {
            Log::error('Resource upload file failed: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }
}
