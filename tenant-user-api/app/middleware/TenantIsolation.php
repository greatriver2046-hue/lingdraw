<?php

namespace app\middleware;

class TenantIsolation
{
    public function handle($request, \Closure $next)
    {
        if (!isset($request->tenantId)) {
             return json(['code' => 403, 'msg' => 'Tenant ID missing'], 403);
        }

        // In a real multi-tenant app, you might set a global scope here
        // or configure the DB connection dynamically.
        // For now, we just ensure the ID is present and matches if specific resource is requested.
        // Since we don't have complex resources yet, we just pass.
        // Example logic:
        // if ($request->param('saas_instance_id') && $request->param('saas_instance_id') != $request->tenantId) {
        //    return json(['code' => 403, 'msg' => 'Cross-tenant access denied'], 403);
        // }

        return $next($request);
    }
}
