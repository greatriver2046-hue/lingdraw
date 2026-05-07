<?php
namespace app\admin\middleware;

use thans\jwt\facade\JWTAuth;
use thans\jwt\exception\JWTException;
use app\traits\ResponseTrait;
use think\Response;
use think\facade\Cache;

class AuthToken
{
    use ResponseTrait;

    public function handle($request, \Closure $next)
    {
        // Handle OPTIONS requests
        if ($request->method() == 'OPTIONS') {
            return Response::create()->code(200);
        }

        // Skip auth for login route if somehow it goes through this middleware
        // (Usually login route is outside the group protected by this middleware)

        try {
            // Verify token
            // JWTAuth::auth() throws exception if invalid
            $payload = JWTAuth::auth(); 
            
            // Optional: Check if token is blacklisted (if you implement logout by blacklist)
            
            // Add user info to request for controllers to use
            $request->uid = $payload['uid'];
            $request->username = $payload['username'];

        } catch (JWTException $e) {
            return $this->error('认证失败: ' . $e->getMessage(), 401);
        } catch (\Exception $e) {
            return $this->error('无效的Token', 401);
        }

        return $next($request);
    }
}
