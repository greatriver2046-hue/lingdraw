<?php
namespace app\controller\admin;

use app\BaseController;
use app\model\InspirationCategory as CategoryModel;
use think\Request;
use think\exception\ValidateException;

class InspirationCategory extends BaseController
{
    /**
     * List categories
     */
    public function index(Request $request)
    {
        $tenantId = $request->tenantId;
        $page = $request->param('page', 1);
        $pageSize = $request->param('page_size', 10);
        $keyword = $request->param('keyword', '');
        
        $query = CategoryModel::where('tenant_id', $tenantId);

        if (!empty($keyword)) {
            $query->where('name', 'like', "%{$keyword}%");
        }
        
        $list = $query->order('sort_order', 'desc')
            ->order('created_at', 'desc')
            ->paginate(['list_rows' => $pageSize, 'page' => $page]);
            
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $list->items(),
                'total' => $list->total()
            ]
        ]);
    }

    /**
     * Get all categories for dropdown
     */
    public function all(Request $request)
    {
        $tenantId = $request->tenantId;
        $list = CategoryModel::where('tenant_id', $tenantId)
            ->order('sort_order', 'desc')
            ->order('created_at', 'desc')
            ->select();
            
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => $list
        ]);
    }

    /**
     * Create category
     */
    public function save(Request $request)
    {
        $tenantId = $request->tenantId;
        $data = $request->only(['name', 'sort_order']);
        
        try {
            $this->validate($data, [
                'name' => 'require|max:100',
                'sort_order' => 'integer',
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getError()]);
        }
        
        $data['tenant_id'] = $tenantId;
        
        try {
            $item = CategoryModel::create($data);
            return json(['code' => 200, 'msg' => 'Created successfully', 'data' => $item]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Failed to create: ' . $e->getMessage()]);
        }
    }

    /**
     * Update category
     */
    public function update(Request $request, $id)
    {
        $tenantId = $request->tenantId;
        $data = $request->only(['name', 'sort_order']);
        
        $item = CategoryModel::where('tenant_id', $tenantId)->find($id);
        if (!$item) {
            return json(['code' => 404, 'msg' => 'Category not found']);
        }
        
        try {
            $this->validate($data, [
                'name' => 'max:100',
                'sort_order' => 'integer',
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getError()]);
        }
        
        try {
            $item->save($data);
            return json(['code' => 200, 'msg' => 'Updated successfully', 'data' => $item]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Failed to update: ' . $e->getMessage()]);
        }
    }

    /**
     * Delete category
     */
    public function delete(Request $request, $id)
    {
        $tenantId = $request->tenantId;
        $item = CategoryModel::where('tenant_id', $tenantId)->find($id);
        
        if (!$item) {
            return json(['code' => 404, 'msg' => 'Category not found']);
        }
        
        try {
            // Check if used? Maybe optional. For now just delete.
            $item->delete();
            return json(['code' => 200, 'msg' => 'Deleted successfully']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Failed to delete: ' . $e->getMessage()]);
        }
    }
}
