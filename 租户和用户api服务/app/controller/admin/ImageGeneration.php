<?php
namespace app\controller\admin;

use app\BaseController;
use app\model\ImageGeneration as ImageGenerationModel;
use think\Request;

class ImageGeneration extends BaseController
{
    public function index(Request $request)
    {
        $tenantId = $request->tenantId;
        $limit = (int)$request->param('limit', 10);
        $page = (int)$request->param('page', 1);

        $query = ImageGenerationModel::alias('ig')
            ->leftJoin('users u', 'u.id = ig.user_id')
            ->where('ig.tenant_id', $tenantId)
            ->field([
                'ig.id',
                'ig.tenant_id',
                'ig.user_id',
                'ig.prompt',
                'ig.model_identity',
                'ig.model_id',
                'ig.width',
                'ig.height',
                'ig.size',
                'ig.image_url',
                'ig.status',
                'ig.error_msg',
                'ig.created_at',
                'u.username' => 'user_username',
                'u.email' => 'user_email',
                'u.phone' => 'user_phone',
            ]);

        $list = $query->order('ig.id', 'desc')->paginate(['list_rows' => $limit, 'page' => $page]);

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'total' => $list->total(),
                'per_page' => $list->listRows(),
                'current_page' => $list->currentPage(),
                'data' => $list->items()
            ]
        ]);
    }
}

