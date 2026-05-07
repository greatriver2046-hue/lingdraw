<?php
namespace app\admin\controller;

use app\BaseController;
use app\admin\model\AdminUser;
use think\facade\Cache;
use thans\jwt\facade\JWTAuth;

class Auth extends BaseController
{
    public function login()
    {
        // Validate Input
        $post = $this->request->post();
        try {
            $this->validate($post, [
                'username' => 'require',
                'password' => 'require'
            ]);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $username = $post['username'];
        $password = $post['password'];

        // Rate Limiting
        $ip = $this->request->ip();
        $cacheKey = "login_fail:{$ip}:{$username}";
        $failCount = Cache::get($cacheKey, 0);

        if ($failCount >= 5) {
            return $this->error('登录失败次数过多，请15分钟后再试', 403);
        }

        // Find User
        $user = AdminUser::where('username', $username)->find();

        if (!$user) {
            Cache::set($cacheKey, $failCount + 1, 900);
            return $this->error('用户名或密码错误', 401);
        }

        // Verify Password
        if (!password_verify($password, $user->password)) {
            Cache::set($cacheKey, $failCount + 1, 900);
            $user->login_failure = $user->login_failure + 1;
            $user->save();
            return $this->error('用户名或密码错误', 401);
        }

        if ($user->status !== 1) {
            return $this->error('账号已被禁用', 403);
        }

        // Success - Generate Token
        try {
            $token = JWTAuth::builder(['uid' => $user->id, 'username' => $user->username]);
        } catch (\Exception $e) {
            return $this->error('Token生成失败', 500);
        }

        // Update Login Info
        $user->last_login_time = time();
        $user->last_login_ip = $ip;
        $user->login_failure = 0;
        $user->save();
        
        // Clear failure cache
        Cache::delete($cacheKey);

        return $this->success([
            'token' => $token,
            'userInfo' => [
                'id' => $user->id,
                'username' => $user->username,
                'status' => $user->status,
                'role' => 'super_admin' // Mock role for now
            ]
        ], '登录成功');
    }

    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::token());
        } catch (\Exception $e) {
            // Ignore
        }
        return $this->success([], '退出成功');
    }
    
    public function info()
    {
        $uid = $this->request->uid;
        $user = AdminUser::find($uid);
        if (!$user) {
             return $this->error('用户不存在', 404);
        }
        
        return $this->success([
             'id' => $user->id,
             'username' => $user->username,
             'status' => $user->status,
             'role' => 'super_admin'
        ]);
    }
}
