<?php

namespace app\controller\api;

use app\BaseController;
use app\model\SmsLog;
use app\model\User;
use app\model\LoginLog;
use app\model\SaasInstance;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\facade\Request;
use thans\jwt\facade\JWTAuth;

class Auth extends BaseController
{
    public function login()
    {
        // 1. Validate input
        $data = Request::post();
        $validate = $this->validate($data, [
            'username' => 'require',
            'password' => 'require'
        ]);
        
        if ($validate !== true) {
            return json(['code' => 400, 'msg' => $validate, 'data' => null], 400);
        }

        $username = $data['username'];
        $password = $data['password'];
        $ip = Request::ip();
        $userAgent = Request::header('user-agent');

        $currentInstance = null;
        try {
            $currentInstance = $this->resolveInstance($this->request);
        } catch (\Exception $e) {
            $this->logLogin($username, $ip, $userAgent, 0, 'Tenant check error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '系统错误，请稍后重试', 'data' => null], 500);
        }

        if ($currentInstance && !$this->isLoginMethodEnabled($currentInstance, 'account')) {
            $this->logLogin($username, $ip, $userAgent, 0, 'Login method disabled: account');
            return json(['code' => 403, 'msg' => '该站点已关闭账号密码登录', 'data' => null], 403);
        }

        // 2. Check User (Query by username only, tenant_id is retrieved from user record)
        $user = User::where('username', $username)->find();
        
        if (!$user) {
            $this->logLogin($username, $ip, $userAgent, 0, 'User not found');
            return json(['code' => 401, 'msg' => '用户名或密码错误', 'data' => null], 401);
        }

        // 3. Check Status
        if ($user->status !== 1) {
            $this->logLogin($username, $ip, $userAgent, 0, 'Account disabled');
            return json(['code' => 403, 'msg' => '账号已被禁用', 'data' => null], 403);
        }

        $shouldApplyLock = !$currentInstance || ((int)$user->tenant_id === (int)$currentInstance->id);
        $tenantId = (int)$user->tenant_id;
        $userId = (int)$user->id;

        if ($shouldApplyLock) {
            $lockKey = $this->getLoginLockKey($tenantId, $userId);
            $lockUntil = (int)Cache::get($lockKey, 0);
            $now = time();
            if ($lockUntil > $now) {
                $remainSeconds = $lockUntil - $now;
                $remainMinutes = (int)ceil($remainSeconds / 60);
                $this->logLogin($username, $ip, $userAgent, 0, 'Account locked');
                return json(['code' => 423, 'msg' => '密码错误次数过多，账号已锁定' . $remainMinutes . '分钟', 'data' => null], 423);
            }
        }

        // 4. Verify Password (using salt)
        $hashedPassword = hash('sha256', $password . $user->salt);
        
        if ($hashedPassword !== $user->password) {
            if ($shouldApplyLock) {
                $maxFail = 5;
                $lockSeconds = 3600;
                $failKey = $this->getLoginFailKey($tenantId, $userId);
                $cur = Cache::get($failKey);
                if ($cur === null || $cur === false || $cur === '') {
                    $next = 1;
                    Cache::set($failKey, 1, $lockSeconds);
                } else {
                    $curInt = (int)$cur;
                    if ($curInt < 0) $curInt = 0;
                    $next = Cache::inc($failKey);
                    if ($next === false) {
                        $next = $curInt + 1;
                        Cache::set($failKey, $next, $lockSeconds);
                    }
                }

                if ((int)$next >= $maxFail) {
                    $lockKey = $this->getLoginLockKey($tenantId, $userId);
                    Cache::set($lockKey, time() + $lockSeconds, $lockSeconds);
                    Cache::delete($failKey);
                    $this->logLogin($username, $ip, $userAgent, 0, 'Password incorrect - locked');
                    return json(['code' => 423, 'msg' => '密码错误次数过多，账号已锁定1小时', 'data' => null], 423);
                }
            }
            $this->logLogin($username, $ip, $userAgent, 0, 'Password incorrect');
            return json(['code' => 401, 'msg' => '用户名或密码错误', 'data' => null], 401);
        }

        // 5. Check Tenant Match
        if ($currentInstance && $user->tenant_id != $currentInstance->id) {
            $this->logLogin($username, $ip, $userAgent, 0, 'Tenant mismatch: User Tenant ' . $user->tenant_id . ' vs Site Tenant ' . $currentInstance->id);
            return json(['code' => 403, 'msg' => '你的账户与站点不匹配，请在注册站点使用', 'data' => null], 403);
        }

        // 6. Success
        if ($shouldApplyLock) {
            Cache::delete($this->getLoginFailKey($tenantId, $userId));
            Cache::delete($this->getLoginLockKey($tenantId, $userId));
        }
        
        // Update last_login_time
        $user->last_login_time = date('Y-m-d H:i:s');
        $user->save();

        // Generate Token
        $token = JWTAuth::builder(['id' => $user->id, 'tenant_id' => $user->tenant_id]); 

        $this->logLogin($username, $ip, $userAgent, 1);

        return json([
            'code' => 200,
            'msg' => '登录成功',
            'data' => [
                'token' => 'Bearer ' . $token,
                'expires_in' => 604800, // 7 days
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'tenant_id' => $user->tenant_id
                ]
            ]
        ]);
    }

    private function getLoginFailKey(int $tenantId, int $userId): string
    {
        $tid = $tenantId > 0 ? $tenantId : 0;
        $uid = $userId > 0 ? $userId : 0;
        return "login_fail:user:{$tid}:{$uid}";
    }

    private function getLoginLockKey(int $tenantId, int $userId): string
    {
        $tid = $tenantId > 0 ? $tenantId : 0;
        $uid = $userId > 0 ? $userId : 0;
        return "login_lock:user:{$tid}:{$uid}";
    }

    public function phoneLogin()
    {
        $data = Request::post();
        $phone = trim((string)($data['phone'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));
        $ip = Request::ip();
        $userAgent = Request::header('user-agent');

        if ($phone === '' || !preg_match('/^1\\d{10}$/', $phone)) {
            return json(['code' => 400, 'msg' => '请输入正确的手机号', 'data' => null], 400);
        }
        if ($code === '' || !preg_match('/^\\d{4,8}$/', $code)) {
            return json(['code' => 400, 'msg' => '请输入正确的验证码', 'data' => null], 400);
        }

        $instance = $this->resolveInstance($this->request);
        if (!$instance) {
            return json(['code' => 404, 'msg' => 'Tenant not found', 'data' => null], 404);
        }
        $tenantId = (int)$instance->id;

        if (!$this->isLoginMethodEnabled($instance, 'phone')) {
            return json(['code' => 403, 'msg' => '该站点已关闭手机登录', 'data' => null], 403);
        }

        $codeKey = $this->getSmsCodeKey($tenantId, $phone);
        $cached = (string)Cache::get($codeKey, '');
        if ($cached === '' || $cached !== $code) {
            $this->logLogin($phone, $ip, $userAgent, 0, 'SMS code invalid or expired');
            return json(['code' => 400, 'msg' => '验证码错误或已过期', 'data' => null], 400);
        }

        $user = User::where('tenant_id', $tenantId)->where('phone', $phone)->find();
        if (!$user) {
            $this->logLogin($phone, $ip, $userAgent, 0, 'Phone not registered');
            return json(['code' => 404, 'msg' => '该手机号未注册', 'data' => null], 404);
        }

        if ($user->status !== 1) {
            $this->logLogin($user->username, $ip, $userAgent, 0, 'Account disabled');
            return json(['code' => 403, 'msg' => '账号已被禁用', 'data' => null], 403);
        }

        $user->last_login_time = date('Y-m-d H:i:s');
        $user->save();

        Cache::delete($codeKey);

        $token = JWTAuth::builder(['id' => $user->id, 'tenant_id' => $user->tenant_id]);

        $this->logLogin($user->username, $ip, $userAgent, 1);

        return json([
            'code' => 200,
            'msg' => '登录成功',
            'data' => [
                'token' => 'Bearer ' . $token,
                'expires_in' => 604800,
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'tenant_id' => $user->tenant_id
                ]
            ]
        ]);
    }

    public function sendLoginCode()
    {
        $data = Request::post();
        $phone = trim((string)($data['phone'] ?? ''));

        if ($phone === '' || !preg_match('/^1\\d{10}$/', $phone)) {
            return json(['code' => 400, 'msg' => '请输入正确的手机号', 'data' => null], 400);
        }

        $instance = $this->resolveInstance($this->request);
        if (!$instance) {
            return json(['code' => 404, 'msg' => 'Tenant not found', 'data' => null], 404);
        }
        $tenantId = (int)$instance->id;

        if (!$this->isLoginMethodEnabled($instance, 'phone')) {
            return json(['code' => 403, 'msg' => '该站点已关闭手机登录', 'data' => null], 403);
        }

        $user = User::where('tenant_id', $tenantId)->where('phone', $phone)->find();
        if (!$user) {
            return json(['code' => 404, 'msg' => '该手机号未注册', 'data' => null], 404);
        }
        if ($user->status !== 1) {
            return json(['code' => 403, 'msg' => '账号已被禁用', 'data' => null], 403);
        }

        return $this->sendCode(array_merge($data, ['type' => 'phone_login']));
    }

    public function sendCode(?array $overrideData = null)
    {
        $data = is_array($overrideData) ? $overrideData : Request::post();
        $phone = trim((string)($data['phone'] ?? ''));

        if ($phone === '' || !preg_match('/^1\\d{10}$/', $phone)) {
            return json(['code' => 400, 'msg' => '请输入正确的手机号', 'data' => null], 400);
        }

        $instance = $this->resolveInstance($this->request);
        if (!$instance) {
            return json(['code' => 404, 'msg' => 'Tenant not found', 'data' => null], 404);
        }
        $tenantId = (int)$instance->id;
        $ip = Request::ip();
        $type = $this->normalizeSmsType((string)($data['type'] ?? 'register'));

        $minuteKey = $this->getSmsRateKey('ip_minute', $tenantId, $ip, date('YmdHi'));
        if ($this->rateLimitExceeded($minuteKey, $this->secondsToNextMinute(), 2)) {
            return json(['code' => 429, 'msg' => '操作频繁，请稍后再试', 'data' => null], 429);
        }

        $dayKeyIp = $this->getSmsRateKey('ip_day', $tenantId, $ip, date('Ymd'));
        if ($this->rateLimitExceeded($dayKeyIp, $this->secondsToEndOfDay(), 20)) {
            return json(['code' => 429, 'msg' => '今日操作次数已达上限，请明天再试', 'data' => null], 429);
        }

        $dayKeyPhone = $this->getSmsRateKey('phone_day', $tenantId, $phone, date('Ymd'));
        if ($this->rateLimitExceeded($dayKeyPhone, $this->secondsToEndOfDay(), 10)) {
            return json(['code' => 429, 'msg' => '今日该手机号获取次数已达上限，请明天再试', 'data' => null], 429);
        }

        $cooldownKey = "sms:cooldown:{$tenantId}:{$phone}";
        if (Cache::get($cooldownKey)) {
            return json(['code' => 429, 'msg' => '操作频繁，请稍后再试', 'data' => null], 429);
        }

        $codeKey = $this->getSmsCodeKey($tenantId, $phone);
        if (Cache::get($codeKey)) {
            return json(['code' => 429, 'msg' => '验证码已发送，请稍后再试', 'data' => null], 429);
        }

        $cfg = $this->getSystemSmsConfig();
        if (!$cfg) {
            $this->writeSystemErrorLog('sms', 'SMS config missing or incomplete', [
                'tenant_id' => $tenantId,
                'endpoint' => $this->request->url(true),
                'code' => 'sms_config_incomplete',
                'source' => '租户和用户api服务',
                'phone' => $this->maskPhone($phone),
            ]);
            return json(['code' => 500, 'msg' => '短信配置未完成，请联系管理员', 'data' => null], 500);
        }

        if (!$instance->updateSmsQuota(1)) {
            return json(['code' => 400, 'msg' => '短信额度不足，请联系商户充值', 'data' => null], 400);
        }

        $code = (string)random_int(100000, 999999);
        Cache::set($codeKey, $code, 300);
        $content = $this->buildSmsContent($type, $code);

        try {
            $this->sendAliyunSms($cfg, $phone, $code);
            $this->writeSmsLog([
                'tenant_id' => $tenantId,
                'phone' => $phone,
                'content' => $content,
                'user_ip' => $ip,
                'type' => $type,
                'status' => 'success',
                'request_payload' => [
                    'sign_name' => $cfg['sign_name'] ?? '',
                    'template_code' => $cfg['template_code'] ?? '',
                    'region_id' => $cfg['region_id'] ?? '',
                    'endpoint' => $cfg['endpoint'] ?? '',
                ],
            ]);
        } catch (\Throwable $e) {
            Cache::delete($codeKey);
            $instance->updateSmsQuota(-1);
            Log::error('Aliyun SMS send failed: ' . $e->getMessage());
            $this->writeSmsLog([
                'tenant_id' => $tenantId,
                'phone' => $phone,
                'content' => $content,
                'user_ip' => $ip,
                'type' => $type,
                'status' => 'failed',
                'request_payload' => [
                    'error' => $e->getMessage(),
                    'sign_name' => $cfg['sign_name'] ?? '',
                    'template_code' => $cfg['template_code'] ?? '',
                    'region_id' => $cfg['region_id'] ?? '',
                    'endpoint' => $cfg['endpoint'] ?? '',
                ],
            ]);
            $this->writeSystemErrorLog('sms', 'Aliyun SMS send failed: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'endpoint' => $this->request->url(true),
                'code' => 'aliyun_sms_send_failed',
                'source' => '租户和用户api服务',
                'phone' => $this->maskPhone($phone),
                'sign_name' => $cfg['sign_name'] ?? null,
                'template_code' => $cfg['template_code'] ?? null,
                'region_id' => $cfg['region_id'] ?? null,
                'sms_endpoint' => $cfg['endpoint'] ?? null,
            ]);
            return json(['code' => 500, 'msg' => '短信发送失败，请稍后重试', 'data' => null], 500);
        }

        Cache::set($cooldownKey, 1, 60);

        $out = null;
        if (env('APP_DEBUG', false)) {
            $out = ['debug_code' => $code];
        }

        return json(['code' => 200, 'msg' => '验证码已发送', 'data' => $out]);
    }

    public function register()
    {
        $data = Request::post();
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $phone = trim((string)($data['phone'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));

        if ($username === '' || mb_strlen($username) < 3) {
            return json(['code' => 400, 'msg' => '用户名长度不能少于3位', 'data' => null], 400);
        }
        if ($password === '' || strlen($password) < 6) {
            return json(['code' => 400, 'msg' => '密码长度不能少于6位', 'data' => null], 400);
        }
        if ($phone === '' || !preg_match('/^1\\d{10}$/', $phone)) {
            return json(['code' => 400, 'msg' => '请输入正确的手机号', 'data' => null], 400);
        }
        if ($code === '' || !preg_match('/^\\d{4,8}$/', $code)) {
            return json(['code' => 400, 'msg' => '请输入正确的验证码', 'data' => null], 400);
        }

        $instance = $this->resolveInstance($this->request);
        if (!$instance) {
            return json(['code' => 404, 'msg' => 'Tenant not found', 'data' => null], 404);
        }
        $tenantId = (int)$instance->id;
        $ip = Request::ip();

        $codeKey = $this->getSmsCodeKey($tenantId, $phone);
        $cached = (string)Cache::get($codeKey, '');
        if ($cached === '' || $cached !== $code) {
            return json(['code' => 400, 'msg' => '验证码错误或已过期', 'data' => null], 400);
        }

        $exists = User::where('username', $username)->find();
        if ($exists) {
            return json(['code' => 400, 'msg' => '用户名已存在，请使用其他用户名', 'data' => null], 400);
        }

        $phoneExists = User::where('tenant_id', $tenantId)->where('phone', $phone)->find();
        if ($phoneExists) {
            return json(['code' => 400, 'msg' => '手机号已被注册', 'data' => null], 400);
        }

        $salt = uniqid();
        $user = User::create([
            'tenant_id' => $tenantId,
            'username' => $username,
            'salt' => $salt,
            'password' => hash('sha256', $password . $salt),
            'phone' => $phone,
            'register_time' => date('Y-m-d H:i:s'),
            'status' => 1,
            'period_points' => 0,
            'extra_points' => 0,
        ]);

        Cache::delete($codeKey);

        $token = JWTAuth::builder(['id' => $user->id, 'tenant_id' => $tenantId]);
        $this->bindSmsLogUser($tenantId, $phone, 'register', (int)$user->id, $ip);

        return json([
            'code' => 200,
            'msg' => '注册成功',
            'data' => [
                'token' => 'Bearer ' . $token,
                'expires_in' => 604800,
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'tenant_id' => $tenantId
                ]
            ]
        ]);
    }

    private function logLogin($username, $ip, $userAgent, $status, $reason = null)
    {
        LoginLog::create([
            'username' => $username,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'status' => $status,
            'fail_reason' => $reason
        ]);
    }

    private function extractHostFromRequest($request)
    {
        $candidates = [
            $request->header('x-forwarded-host'),
            $request->header('origin'),
            $request->header('referer'),
            $request->header('host'),
        ];

        foreach ($candidates as $value) {
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }

            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }

            if (strpos($value, ',') !== false) {
                $value = trim(explode(',', $value)[0]);
            }

            if (stripos($value, 'http://') === 0 || stripos($value, 'https://') === 0) {
                $parsed = parse_url($value);
                $host = $parsed['host'] ?? '';
                $port = $parsed['port'] ?? null;
                
                if (is_string($host) && $host !== '') {
                    return $port ? "$host:$port" : $host;
                }
                continue;
            }

            // Don't strip port blindly
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveInstance($request)
    {
        $tenantId = $request->param('tenant_id');
        if ($tenantId) {
            return SaasInstance::find($tenantId);
        }

        $host = strtolower($this->extractHostFromRequest($request));
        $host = trim($host, " \t\n\r\0\x0B.");
        
        // Prepare variations for matching
        $variations = [$host]; 
        
        // host without port
        $hostNoPort = preg_replace('/:\\d+$/', '', $host);
        if ($hostNoPort !== $host) {
            $variations[] = $hostNoPort;
        }
        
        // host without www
        $hostNoWww = preg_replace('/^www\\./', '', $hostNoPort);
        if ($hostNoWww !== $hostNoPort) {
             $variations[] = $hostNoWww;
        }

        // Try exact match with variations
        $instance = SaasInstance::where('status', 1)
            ->where(function ($query) use ($variations) {
                foreach ($variations as $v) {
                    $query->whereOr('domain', $v)
                          ->whereOr('domain', 'http://' . $v)
                          ->whereOr('domain', 'https://' . $v)
                          ->whereOr('domain', 'http://' . $v . '/')
                          ->whereOr('domain', 'https://' . $v . '/');
                }
            })
            ->order('id', 'asc')
            ->find();

        if ($instance) {
            return $instance;
        }
        
        // Fallback: Default to ID 1 if not found (assuming main site)
        // This is important because if we can't find the tenant, we might be on the main domain or a dev environment
        // that defaults to tenant 1.
        return SaasInstance::find(1);
    }

    private function normalizeHomeConfigToArray($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_object($raw)) {
            return json_decode(json_encode($raw), true) ?: [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function isLoginMethodEnabled($instance, string $methodKey): bool
    {
        if (!$instance) {
            return true;
        }

        $cfg = $this->normalizeHomeConfigToArray($instance->home_config ?? []);
        $methods = $cfg['login_methods'] ?? null;
        if (!is_array($methods)) {
            return true;
        }

        $val = $methods[$methodKey] ?? null;
        if ($val === null) {
            return true;
        }
        if ($val === 0 || $val === '0' || $val === false) {
            return false;
        }
        return true;
    }

    private function getSmsCodeKey($tenantId, $phone)
    {
        return "sms:code:{$tenantId}:{$phone}";
    }

    private function normalizeSmsType(string $type): string
    {
        $type = trim($type);
        $allowed = ['register', 'forgot_password', 'phone_login', 'bind_phone'];
        return in_array($type, $allowed, true) ? $type : 'register';
    }

    private function buildSmsContent(string $type, string $code): string
    {
        $prefixMap = [
            'register' => '注册账号',
            'forgot_password' => '找回密码',
            'phone_login' => '手机登录',
            'bind_phone' => '绑定手机号',
        ];
        $prefix = $prefixMap[$type] ?? '短信验证';
        return sprintf('%s验证码：%s，5分钟内有效。', $prefix, $code);
    }

    private function writeSmsLog(array $data): void
    {
        try {
            SmsLog::create([
                'tenant_id' => isset($data['tenant_id']) ? (int)$data['tenant_id'] : null,
                'user_id' => isset($data['user_id']) ? (int)$data['user_id'] : null,
                'phone' => (string)($data['phone'] ?? ''),
                'content' => (string)($data['content'] ?? ''),
                'user_ip' => (string)($data['user_ip'] ?? ''),
                'type' => $this->normalizeSmsType((string)($data['type'] ?? 'register')),
                'status' => (string)($data['status'] ?? 'success'),
                'request_payload' => json_encode($data['request_payload'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            Log::error('sms_logs insert failed: ' . $e->getMessage());
        }
    }

    private function bindSmsLogUser(int $tenantId, string $phone, string $type, int $userId, string $userIp = ''): void
    {
        if ($tenantId <= 0 || $userId <= 0 || $phone === '') {
            return;
        }

        try {
            $query = SmsLog::where('tenant_id', $tenantId)
                ->where('phone', $phone)
                ->where('type', $this->normalizeSmsType($type))
                ->where('status', 'success')
                ->whereNull('user_id');

            if ($userIp !== '') {
                $query->where('user_ip', $userIp);
            }

            $log = $query->order('id', 'desc')->find();
            if (!$log) {
                return;
            }

            $createdAt = (int)($log->create_time ?? 0);
            if ($createdAt > 0 && $createdAt < time() - 1800) {
                return;
            }

            $log->save(['user_id' => $userId]);
        } catch (\Throwable $e) {
            Log::warning('bind sms log user failed: ' . $e->getMessage());
        }
    }

    private function getSystemSmsConfig(): ?array
    {
        try {
            $row = Db::table('system_configs')->where('category', 'sms')->find();
            if (!$row) return null;

            $raw = $row['config'] ?? null;
            $cfg = is_array($raw) ? $raw : (is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null);
            if (!is_array($cfg)) return null;

            $accessKeyId = trim((string)($cfg['access_key_id'] ?? ''));
            $accessKeySecret = trim((string)($cfg['access_key_secret'] ?? ''));
            $signName = trim((string)($cfg['sign_name'] ?? ''));
            $templateCode = trim((string)($cfg['template_code'] ?? ''));
            $regionId = trim((string)($cfg['region_id'] ?? 'cn-hangzhou'));
            $endpoint = trim((string)($cfg['endpoint'] ?? 'dysmsapi.aliyuncs.com'));

            if ($accessKeyId === '' || $accessKeySecret === '' || $signName === '' || $templateCode === '') {
                return null;
            }

            return [
                'access_key_id' => $accessKeyId,
                'access_key_secret' => $accessKeySecret,
                'sign_name' => $signName,
                'template_code' => $templateCode,
                'region_id' => $regionId !== '' ? $regionId : 'cn-hangzhou',
                'endpoint' => $endpoint !== '' ? $endpoint : 'dysmsapi.aliyuncs.com',
            ];
        } catch (\Throwable $e) {
            Log::warning('Read system sms config failed: ' . $e->getMessage());
            $this->writeSystemErrorLog('sms', 'Read system sms config failed: ' . $e->getMessage(), [
                'endpoint' => $this->request->url(true),
                'code' => 'read_sms_config_failed',
                'source' => '租户和用户api服务',
            ]);
            return null;
        }
    }

    private function sendAliyunSms(array $cfg, string $phone, string $code): void
    {
        $endpoint = (string)$cfg['endpoint'];
        $endpoint = preg_replace('/^https?:\\/\\//i', '', $endpoint);
        $endpoint = trim((string)$endpoint, "/ \t\n\r\0\x0B");
        if ($endpoint === '') {
            throw new \RuntimeException('SMS endpoint missing');
        }

        $params = [
            'AccessKeyId' => (string)$cfg['access_key_id'],
            'Action' => 'SendSms',
            'Format' => 'JSON',
            'PhoneNumbers' => $phone,
            'RegionId' => (string)$cfg['region_id'],
            'SignName' => (string)$cfg['sign_name'],
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => str_replace('.', '', uniqid('', true)),
            'SignatureVersion' => '1.0',
            'TemplateCode' => (string)$cfg['template_code'],
            'TemplateParam' => json_encode(['code' => $code], JSON_UNESCAPED_UNICODE),
            'Timestamp' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'Version' => '2017-05-25',
        ];

        $signedParams = $this->aliyunRpcSignParams($params, (string)$cfg['access_key_secret']);
        $url = 'https://' . $endpoint . '/?' . $this->aliyunBuildQuery($signedParams);

        $resp = $this->httpGetJson($url);

        $respCode = isset($resp['Code']) ? (string)$resp['Code'] : '';
        if ($respCode !== 'OK') {
            $message = isset($resp['Message']) ? (string)$resp['Message'] : 'Unknown error';
            $requestId = isset($resp['RequestId']) ? (string)$resp['RequestId'] : '';
            throw new \RuntimeException("Aliyun SMS error: {$respCode} {$message} {$requestId}");
        }
    }

    private function aliyunRpcSignParams(array $params, string $accessKeySecret): array
    {
        ksort($params);
        $canonicalized = [];
        foreach ($params as $k => $v) {
            $canonicalized[] = $this->aliyunPercentEncode((string)$k) . '=' . $this->aliyunPercentEncode((string)$v);
        }
        $canonicalizedQueryString = implode('&', $canonicalized);
        $stringToSign = 'GET&%2F&' . $this->aliyunPercentEncode($canonicalizedQueryString);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
        $params['Signature'] = $signature;
        return $params;
    }

    private function aliyunPercentEncode(string $str): string
    {
        $res = rawurlencode($str);
        $res = str_replace(['%7E', '+'], ['~', '%20'], $res);
        return $res;
    }

    private function aliyunBuildQuery(array $params): string
    {
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $this->aliyunPercentEncode((string)$k) . '=' . $this->aliyunPercentEncode((string)$v);
        }
        return implode('&', $pairs);
    }

    private function httpGetJson(string $url): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $verifySsl = !env('APP_DEBUG', false);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('HTTP request failed: ' . ($errno ? "{$errno} {$error}" : 'unknown'));
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException("HTTP status {$httpCode}: " . mb_substr((string)$body, 0, 500));
        }

        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Invalid JSON response: ' . mb_substr((string)$body, 0, 500));
        }
        return $json;
    }

    private function maskPhone(string $phone): string
    {
        $p = preg_replace('/\\s+/', '', $phone);
        if (!is_string($p)) return '';
        if (preg_match('/^1\\d{10}$/', $p)) {
            return substr($p, 0, 3) . '****' . substr($p, 7);
        }
        if (mb_strlen($p) >= 7) {
            return mb_substr($p, 0, 3) . '****' . mb_substr($p, -3);
        }
        return $p;
    }

    private function getSystemErrorLogColumns(): array
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

    private function writeSystemErrorLog(string $category, string $message, array $context = []): void
    {
        $cat = trim($category) !== '' ? trim($category) : 'general';
        $msg = trim($message);
        if ($msg === '') return;

        $cols = $this->getSystemErrorLogColumns();
        if (!$cols) return;

        $tenantId = $context['tenant_id'] ?? null;
        $userId = $context['user_id'] ?? null;
        $endpoint = isset($context['endpoint']) ? (string)$context['endpoint'] : '';
        $code = isset($context['code']) ? (string)$context['code'] : '';
        $source = isset($context['source']) ? (string)$context['source'] : '租户和用户api服务';

        $row = [];
        if (isset($cols['tenant_id']) && $tenantId !== null) $row['tenant_id'] = (int)$tenantId;
        if (isset($cols['user_id']) && $userId) $row['user_id'] = (int)$userId;

        if (isset($cols['category'])) $row['category'] = $cat;
        if (isset($cols['message'])) $row['message'] = mb_substr($msg, 0, 500);
        if (isset($cols['endpoint'])) $row['endpoint'] = $endpoint;
        if (isset($cols['code'])) $row['code'] = $code;
        if (isset($cols['source'])) $row['source'] = $source;
        if (isset($cols['context'])) $row['context'] = $code !== '' ? $code : $endpoint;
        if (isset($cols['payload'])) $row['payload'] = json_encode($context, JSON_UNESCAPED_UNICODE);
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

    private function getSmsRateKey(string $type, int $tenantId, string $identity, string $suffix): string
    {
        $id = trim($identity);
        return "sms:rate:{$type}:{$tenantId}:{$id}:{$suffix}";
    }

    private function rateLimitExceeded(string $key, int $ttlSeconds, int $limit): bool
    {
        $ttl = max(1, (int)$ttlSeconds);
        $max = max(1, (int)$limit);

        $cur = Cache::get($key);
        if ($cur === null || $cur === false || $cur === '') {
            Cache::set($key, 1, $ttl);
            return false;
        }

        $curInt = (int)$cur;
        if ($curInt >= $max) {
            return true;
        }

        $next = Cache::inc($key);
        if ($next === false) {
            Cache::set($key, $curInt + 1, $ttl);
        }
        return false;
    }

    private function secondsToEndOfDay(): int
    {
        $now = time();
        $end = strtotime(date('Y-m-d 23:59:59', $now));
        $ttl = (int)$end - $now + 1;
        return $ttl > 0 ? $ttl : 1;
    }

    private function secondsToNextMinute(): int
    {
        $now = time();
        $ttl = 60 - ($now % 60);
        return $ttl > 0 ? $ttl : 60;
    }
}
