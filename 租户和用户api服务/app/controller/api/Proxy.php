<?php
namespace app\controller\api;

use app\BaseController;
use think\Request;
use GuzzleHttp\Client;

class Proxy extends BaseController
{
    public function image(Request $request)
    {
        $url = trim((string)$request->get('url'));
        if ($url === '') {
            return response('Bad Request', 400);
        }

        if (!$this->isAllowedRemoteUrl($url)) {
            return response('Forbidden', 403);
        }

        $client = new Client(['timeout' => 20, 'verify' => false]);
        $effectiveUrl = null;
        try {
            $resp = $client->request('GET', $url, [
                'http_errors' => false,
                'allow_redirects' => ['max' => 3, 'track_redirects' => true],
                'on_stats' => function ($stats) use (&$effectiveUrl) {
                    $effectiveUrl = (string)$stats->getEffectiveUri();
                },
            ]);
            $finalUrl = $effectiveUrl ?: $url;
            if (!$this->isAllowedRemoteUrl($finalUrl)) {
                return response('Forbidden', 403);
            }

            $status = (int)$resp->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return response('Upstream Error', 502);
            }

            $ctype = $resp->getHeaderLine('Content-Type') ?: 'application/octet-stream';
            $lowerType = strtolower($ctype);
            if (strpos($lowerType, 'image/') !== 0) {
                $path = (string)(parse_url($finalUrl, PHP_URL_PATH) ?? '');
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $known = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'svg'], true);
                if (!($known && ($lowerType === '' || $lowerType === 'application/octet-stream'))) {
                    return response('Unsupported Media Type', 415);
                }
            }

            $body = $resp->getBody()->getContents();
            return response($body, 200, [
                'Content-Type' => $ctype,
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'max-age=300'
            ]);
        } catch (\Throwable $e) {
            return response('Upstream Error', 502);
        }
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

        $port = isset($parsed['port']) ? (int)$parsed['port'] : null;
        if ($port !== null && $port !== 80 && $port !== 443) return false;

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
            ['192.0.2.0', '192.0.2.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['198.18.0.0', '198.19.255.255'],
            ['198.51.100.0', '198.51.100.255'],
            ['203.0.113.0', '203.0.113.255'],
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
}
