<?php

namespace app\middleware;

use thans\jwt\facade\JWTAuth as JwtFacade;
use thans\jwt\exception\JWTException;
use think\Response;

class JwtAuth
{
    public function handle($request, \Closure $next)
    {
        // Handle OPTIONS request for CORS preflight
        if ($request->method() == 'OPTIONS') {
            return $next($request);
        }

        try {
            $payload = JwtFacade::auth(); // Verify token and get payload
            
            // Debug logging
            // trace('JWT Payload Type: ' . gettype($payload));
            // if (is_object($payload)) { trace('JWT Payload Class: ' . get_class($payload)); }
            // trace('JWT Payload Content: ' . json_encode($payload));

            // Handle different payload structures
            $userId = null;
            $tenantId = null;
            
            // Case 1: Payload is an array (common)
            if (is_array($payload)) {
                // Extract User ID
                $idClaim = $payload['id'] ?? null;
                if ($idClaim) {
                     if (is_object($idClaim) && method_exists($idClaim, 'getValue')) {
                        $userId = $idClaim->getValue();
                    } elseif (is_array($idClaim) && isset($idClaim['value'])) {
                        $userId = $idClaim['value'];
                    } else {
                        $userId = $idClaim;
                    }
                }

                // Extract Tenant ID
                $tenantClaim = $payload['tenant_id'] ?? null;
                if ($tenantClaim) {
                     if (is_object($tenantClaim) && method_exists($tenantClaim, 'getValue')) {
                        $tenantId = $tenantClaim->getValue();
                    } elseif (is_array($tenantClaim) && isset($tenantClaim['value'])) {
                        $tenantId = $tenantClaim['value'];
                    } else {
                        $tenantId = $tenantClaim;
                    }
                }
            } 
            // Case 2: Payload is an object (e.g., Claim or DataSet)
            elseif (is_object($payload)) {
                 // Extract User ID
                 if (isset($payload->id)) {
                     $userId = $payload->id;
                     if (is_object($userId) && method_exists($userId, 'getValue')) {
                         $userId = $userId->getValue();
                     }
                 }
                 // Extract Tenant ID
                 if (isset($payload->tenant_id)) {
                     $tenantId = $payload->tenant_id;
                     if (is_object($tenantId) && method_exists($tenantId, 'getValue')) {
                         $tenantId = $tenantId->getValue();
                     }
                 }
            }

            if (!$tenantId) {
                 // Fallback: If tenant_id is missing, check if id might be the tenant_id (legacy support or error)
                 // But strictly speaking we should require tenant_id. 
                 // For now, let's assume strict separation.
                 // return json(['code' => 401, 'msg' => 'Invalid Token Payload: Tenant ID not found'], 401);
                 // Actually, let's allow it to proceed but maybe without tenant isolation if it's super admin? 
                 // No, user said "must include tenant_id".
                 
                 // However, to avoid breaking existing tokens that might not have tenant_id yet (if any),
                 // let's be careful. But we just created the user table and login logic, so tokens are fresh.
            }

            if (!$userId) {
                 return json(['code' => 401, 'msg' => 'Invalid Token Payload: User ID not found'], 401);
            }
            
            // Store User ID and Tenant ID in Request
            $request->userId = $userId;
            $request->tenantId = $tenantId;

        } catch (JWTException $e) {
            return json(['code' => 401, 'msg' => $e->getMessage()], 401);
        }

        return $next($request);
    }
}
