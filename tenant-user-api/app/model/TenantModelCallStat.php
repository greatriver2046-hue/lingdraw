<?php
namespace app\model;

use think\Model;
use think\facade\Db;

class TenantModelCallStat extends Model
{
    protected $name = 'tenant_model_call_stats';
    protected $autoWriteTimestamp = true;

    public static function addPointsForTenant($tenantId, $modelId, $points)
    {
        if ($points <= 0) {
            return;
        }

        $exists = Db::name('tenant_model_call_stats')
            ->where('tenant_id', $tenantId)
            ->where('model_id', $modelId)
            ->find();

        if ($exists) {
            Db::name('tenant_model_call_stats')
                ->where('tenant_id', $tenantId)
                ->where('model_id', $modelId)
                ->inc('total_points', $points)
                ->update();
        } else {
            Db::name('tenant_model_call_stats')->insert([
                'tenant_id'   => $tenantId,
                'model_id'    => $modelId,
                'total_points' => $points,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
