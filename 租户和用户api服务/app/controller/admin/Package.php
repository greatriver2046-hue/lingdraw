<?php
namespace app\controller\admin;

use app\BaseController;
use app\model\Package as PackageModel;
use think\Request;
use think\exception\ValidateException;

class Package extends BaseController
{
    /**
     * List packages
     */
    public function index(Request $request)
    {
        $tenantId = $request->tenantId;
        $page = $request->param('page', 1);
        $pageSize = $request->param('page_size', 10);
        
        $list = PackageModel::where('tenant_id', $tenantId)
            ->order('create_time', 'desc')
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
     * Create package
     */
    public function save(Request $request)
    {
        $tenantId = $request->tenantId;
        $data = $request->only(['name', 'price', 'original_price', 'duration_days', 'points', 'description', 'status', 'reset_cycle_days', 'theme_color']);
        
        try {
            $this->validate($data, [
                'name' => 'require|max:100',
                'price' => 'require|float|>=:0',
                'original_price' => 'float|>=:0',
                'duration_days' => 'require|integer|>=:1',
                'points' => 'require|integer|>=:0',
                'reset_cycle_days' => 'integer|>=:0',
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getError()]);
        }
        
        $data['tenant_id'] = $tenantId;
        
        try {
            $package = PackageModel::create($data);
            return json(['code' => 200, 'msg' => 'Created successfully', 'data' => $package]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Failed to create: ' . $e->getMessage()]);
        }
    }

    /**
     * Update package
     */
    public function update(Request $request, $id)
    {
        $tenantId = $request->tenantId;
        $data = $request->only(['name', 'price', 'original_price', 'duration_days', 'points', 'description', 'status', 'reset_cycle_days', 'theme_color']);
        
        $package = PackageModel::where('tenant_id', $tenantId)->find($id);
        if (!$package) {
            return json(['code' => 404, 'msg' => 'Package not found']);
        }
        
        try {
            $this->validate($data, [
                'name' => 'max:100',
                'price' => 'float|>=:0',
                'original_price' => 'float|>=:0',
                'duration_days' => 'integer|>=:1',
                'points' => 'integer|>=:0',
                'reset_cycle_days' => 'integer|>=:0',
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getError()]);
        }
        
        try {
            $package->save($data);
            return json(['code' => 200, 'msg' => 'Updated successfully', 'data' => $package]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Failed to update: ' . $e->getMessage()]);
        }
    }

    /**
     * Delete package
     */
    public function delete(Request $request, $id)
    {
        $tenantId = $request->tenantId;
        
        $package = PackageModel::where('tenant_id', $tenantId)->find($id);
        if (!$package) {
            return json(['code' => 404, 'msg' => 'Package not found']);
        }
        
        try {
            $package->delete();
            return json(['code' => 200, 'msg' => 'Deleted successfully']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Failed to delete: ' . $e->getMessage()]);
        }
    }

    /**
     * Update status
     */
    public function status(Request $request, $id)
    {
        $tenantId = $request->tenantId;
        $status = $request->param('status');
        
        $package = PackageModel::where('tenant_id', $tenantId)->find($id);
        if (!$package) {
            return json(['code' => 404, 'msg' => 'Package not found']);
        }
        
        try {
            $package->status = $status;
            $package->save();
            return json(['code' => 200, 'msg' => 'Status updated successfully']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Failed to update status: ' . $e->getMessage()]);
        }
    }
}
