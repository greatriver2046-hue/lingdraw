<?php
namespace app\admin\controller;

use app\BaseController;
use app\admin\model\ImageAsset as ImageAssetModel;

class ImageAsset extends BaseController
{
    public function index()
    {
        $page = $this->request->param('page', 1);
        $limit = $this->request->param('limit', 10);
        $type = $this->request->param('type', '');
        $start = $this->request->param('start_time');
        $end = $this->request->param('end_time');

        $query = ImageAssetModel::order('id', 'desc');

        if (!empty($type)) {
            $query->where('type', $type);
        }
        if (!empty($start)) {
            $query->where('create_time', '>=', strtotime($start));
        }
        if (!empty($end)) {
            $query->where('create_time', '<=', strtotime($end));
        }

        $list = $query->paginate([
            'list_rows' => $limit,
            'page' => $page
        ]);

        return $this->success($list);
    }

    public function delete($id)
    {
        try {
            $asset = ImageAssetModel::find($id);
            if (!$asset) {
                return $this->error('资源不存在', 404);
            }
            $asset->delete();
            return $this->success([], '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }
}

