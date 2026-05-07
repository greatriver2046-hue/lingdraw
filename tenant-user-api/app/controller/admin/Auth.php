<?php
namespace app\controller\admin;

use app\BaseController;
use app\model\SaasInstance;
use thans\jwt\facade\JWTAuth;
use think\facade\Request;

class Auth extends BaseController
{
    public function login()
    {
        $data = Request::post();
        $validate = $this->validate($data, [
            'username' => 'require',
            'password' => 'require'
        ]);

        if ($validate !== true) {
            return json(['code' => 400, 'msg' => $validate], 400);
        }

        $username = $data['username'];
        $password = $data['password'];

        // Find tenant by admin_email
        $instance = SaasInstance::where('admin_email', $username)->find();

        if (!$instance) {
            return json(['code' => 401, 'msg' => '账号或密码错误'], 401);
        }

        if ($instance->status !== 1) {
            return json(['code' => 403, 'msg' => '账号已被禁用'], 403);
        }

        // Verify password (Bcrypt)
        // Note: Password in saas_instances is hashed using password_hash() (Bcrypt)
        if (!password_verify($password, $instance->password)) {
            return json(['code' => 401, 'msg' => '账号或密码错误'], 401);
        }

        // Generate Token
        // Payload: tenantId=instance_id, role=tenant_admin
        try {
            $token = JWTAuth::builder(['tenantId' => $instance->id, 'role' => 'tenant_admin']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Token生成失败'], 500);
        }

        return json([
            'code' => 200,
            'msg' => '登录成功',
            'data' => [
                'token' => 'Bearer ' . $token,
                'userInfo' => [
                    'id' => $instance->id,
                    'username' => $instance->name,
                    'email' => $instance->admin_email,
                    'role' => 'tenant_admin'
                ]
            ]
        ]);
    }

    public function sso()
    {
        $secret = (string)env('SSO_SECRET', '');
        if ($secret === '') {
            $secret = (string)env('SSO.SSO_SECRET', '');
        }
        if ($secret === '') {
            try {
                app()->loadEnv();
            } catch (\Throwable $e) {
            }
            $secret = (string)env('SSO_SECRET', '');
            if ($secret === '') {
                $secret = (string)env('SSO.SSO_SECRET', '');
            }
        }
        if ($secret === '') {
            return json(['code' => 500, 'msg' => 'SSO_SECRET未配置'], 500);
        }

        $headerSecret = (string)Request::header('x-sso-secret', '');
        if ($headerSecret === '' || !hash_equals($secret, $headerSecret)) {
            return json(['code' => 403, 'msg' => 'SSO认证失败'], 403);
        }

        $data = Request::post();
        $tenantId = (int)($data['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            return json(['code' => 400, 'msg' => 'tenant_id不能为空'], 400);
        }

        $instance = SaasInstance::find($tenantId);
        if (!$instance) {
            return json(['code' => 404, 'msg' => '租户不存在'], 404);
        }
        if ((int)$instance->status !== 1) {
            return json(['code' => 403, 'msg' => '租户已停用'], 403);
        }

        try {
            $token = JWTAuth::builder(['tenantId' => $instance->id, 'role' => 'tenant_admin']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Token生成失败'], 500);
        }

        return json([
            'code' => 200,
            'msg' => '登录成功',
            'data' => [
                'token' => 'Bearer ' . $token,
                'userInfo' => [
                    'id' => $instance->id,
                    'username' => $instance->name,
                    'email' => $instance->admin_email,
                    'role' => 'tenant_admin'
                ]
            ]
        ]);
    }
}
