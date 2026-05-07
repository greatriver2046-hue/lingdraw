<?php
namespace app\controller\api;

use app\BaseController;
use app\model\ModelConfig;
use think\Request;

class Model extends BaseController
{
    public function list(Request $request)
    {
        $type = $request->get('type');
        if (!$type) {
            $type = 'image';
        }

        $query = ModelConfig::where('status', 'active')
            ->whereNull('delete_time')
            ->field(['id','name','model_identity','model_id','model_type','cost_per_request',
                'aspect_ratio_config', 'duration_config', 'quality_config', 'size_config', 'resolution_config',
                'enable_first_frame', 'enable_first_last_frame', 'enable_multi_image_ref', 'enable_video_ref']);

        if (strpos($type, ',') !== false) {
            $types = explode(',', $type);
            $query->whereIn('model_type', $types);
        } else {
            $query->where('model_type', $type);
        }

        $models = $query->select();
        
        $models->each(function($item) {
            $item->config = [
                'aspect_ratios' => $item->aspect_ratio_config ? array_values((array)$item->aspect_ratio_config) : [],
                'durations' => $item->duration_config ? array_values((array)$item->duration_config) : [],
                'resolutions' => !empty($item->quality_config) ? array_values((array)$item->quality_config) : (!empty($item->resolution_config) ? array_values((array)$item->resolution_config) : []),
                'sizes' => $item->size_config ? array_values((array)$item->size_config) : []
            ];
        });

        return json(['code' => 200, 'msg' => 'Success', 'data' => $models]);
    }
}

