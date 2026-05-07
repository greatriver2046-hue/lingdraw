<?php

namespace app\middleware;

use thans\jwt\facade\JWTAuth as JwtFacade;
use thans\jwt\exception\JWTException;

class TenantAdminAuth
{
    public function handle($request, \Closure $next)
    {
        if ($request->method() == 'OPTIONS') {
            return $next($request);
        }

        try {
            $payload = JwtFacade::auth();
            
            // Handle payload as array or object
            $tenantId = null;
            $role = null;

            if (is_array($payload)) {
                $tenantId = $this->extractClaim($payload, 'tenantId');
                $role = $this->extractClaim($payload, 'role');
            } elseif (is_object($payload)) {
                $tenantId = $payload->tenantId ?? null;
                $role = $payload->role ?? null;
                
                if (is_object($tenantId) && method_exists($tenantId, 'getValue')) $tenantId = $tenantId->getValue();
                if (is_object($role) && method_exists($role, 'getValue')) $role = $role->getValue();
            }

            if (!$tenantId) {
                return json(['code' => 401, 'msg' => 'Invalid Token: Tenant ID not found'], 401);
            }

            if ($role !== 'tenant_admin') {
                return json(['code' => 403, 'msg' => 'Access Denied: Tenant Admin role required'], 403);
            }

            // Inject tenant admin info into request
            $request->tenantAdminId = $tenantId; // This is the instance_id
            $request->tenantId = $tenantId;      // For tenant admin, their ID is the tenant ID
            
        } catch (JWTException $e) {
            return json(['code' => 401, 'msg' => $e->getMessage()], 401);
        }

        return $next($request);
    }

    private function extractClaim($payload, $key)
    {
        $claim = $payload[$key] ?? null;
        if (is_object($claim) && method_exists($claim, 'getValue')) {
            return $claim->getValue();
        } elseif (is_array($claim) && isset($claim['value'])) {
            return $claim['value'];
        }
        return $claim;
    }
}
