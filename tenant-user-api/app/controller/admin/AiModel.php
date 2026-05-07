<?php
namespace app\controller\admin;

use app\BaseController;
use app\model\ModelConfig;
use app\model\TenantModelCallStat;
use think\facade\Db;

class AiModel extends BaseController
{
    public function listAll()
    {
        $list = ModelConfig::where('status', 'active')
            ->whereIn('model_type', ['image', 'video'])
            ->field('id, name, model_identity, model_type')
            ->select();
            
        $data = $list->map(function($item) {
            return [
                'label' => $item->name,
                'value' => $item->name,
                'model_name' => $item->name,
                'model_identity' => $item->model_identity,
                'type' => $item->model_type
            ];
        });

        return json(['code' => 200, 'msg' => 'success', 'data' => $data]);
    }

    public function listAllModels()
    {
        $tenantId = request()->tenantId;

        $list = ModelConfig::where('status', 'active')
            ->field('id, name, model_type, model_id, cost_per_request, status')
            ->order('model_type', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $stats = Db::name('tenant_model_call_stats')
            ->where('tenant_id', $tenantId)
            ->column('total_points', 'model_id');

        foreach ($list as &$item) {
            $item['total_points'] = $stats[$item['id']] ?? 0;
        }
        unset($item);

        return json(['code' => 200, 'msg' => 'success', 'data' => $list]);
    }
}
