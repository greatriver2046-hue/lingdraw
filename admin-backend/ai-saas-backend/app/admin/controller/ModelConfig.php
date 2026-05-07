<?php
namespace app\admin\controller;

use app\BaseController;
use app\admin\model\ModelConfig as ModelConfigModel;
use think\facade\Db;
use think\exception\ValidateException;

class ModelConfig extends BaseController
{
    /**
     * Get Model Config List
     */
    public function index()
    {
        $page = $this->request->param('page', 1);
        $limit = $this->request->param('limit', 10);
        $keyword = $this->request->param('keyword', '');

        $query = ModelConfigModel::order('id', 'desc');

        if (!empty($keyword)) {
            $query->where('name|model_id|model_identity|provider_code', 'like', "%{$keyword}%");
        }

        $list = $query->paginate([
            'list_rows' => $limit,
            'page' => $page
        ]);

        return $this->success($list);
    }

    /**
     * Get All Active Models
     */
    public function getAll()
    {
        $list = ModelConfigModel::where('status', 'active')
            ->field('id, name, model_type, model_id, model_identity, provider_code, cost_per_request, call_count, remark, status')
            ->order('model_type', 'asc')
            ->order('id', 'asc')
            ->select();
        return $this->success($list);
    }

    /**
     * Create Model Config
     */
    public function save()
    {
        $data = $this->request->post();
        
        try {
            $this->validate($data, [
                'name' => 'require|max:100',
                'model_id' => 'require|max:100',
                'model_identity' => 'require|max:100',
                'provider_code' => 'max:100',
                'model_type' => 'require|in:llm,image,video,audio,imageseg,imageseg_hd,v2v,vision',
                'cost_per_request' => 'integer|min:0',
                'call_count' => 'integer|min:0',
                'api_key' => 'max:255',
                'endpoint' => 'max:255',
                'enable_first_frame' => 'in:0,1',
                'enable_first_last_frame' => 'in:0,1',
                'enable_multi_image_ref' => 'in:0,1',
                'enable_video_ref' => 'in:0,1',
                'remark' => 'max:255'
            ], [
                'name.require' => '显示名称不能为空',
                'model_id.require' => '模型ID不能为空',
                'model_identity.require' => '模型标识不能为空',
                'model_type.require' => '模型类型不能为空',
                'model_type.in' => '模型类型无效',
                'cost_per_request.integer' => '单次消耗点数必须为整数',
                'cost_per_request.min' => '单次消耗点数不能小于0',
                'call_count.integer' => '调用次数必须为整数',
                'call_count.min' => '调用次数不能小于0'
            ]);
            if (!empty($data['is_default']) && intval($data['is_default']) === 1) {
                Db::name('model_configs')->where('model_type', $data['model_type'])->update(['is_default' => 0]);
            }

            foreach (['size_config', 'duration_config', 'aspect_ratio_config', 'quality_config', 'resolution_config'] as $field) {
                if (isset($data[$field]) && is_array($data[$field])) {
                    $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
                }
            }

            $model = ModelConfigModel::create($data);
            return $this->success($model, '创建成功');

        } catch (ValidateException $e) {
            return $this->error($e->getError());
        } catch (\Exception $e) {
            return $this->error('创建失败: ' . $e->getMessage());
        }
    }

    /**
     * Update Model Config
     */
    public function update($id)
    {
        $data = $this->request->put();
        
        try {
            $model = ModelConfigModel::find($id);
            if (!$model) {
                return $this->error('模型配置不存在', 404);
            }

            $this->validate($data, [
                'name' => 'max:100',
                'model_id' => 'max:100',
                'model_identity' => 'max:100',
                'provider_code' => 'max:100',
                'model_type' => 'in:llm,image,video,audio,imageseg,imageseg_hd,v2v,vision',
                'cost_per_request' => 'integer|min:0',
                'call_count' => 'integer|min:0',
                'api_key' => 'max:255',
                'endpoint' => 'max:255',
                'enable_first_frame' => 'in:0,1',
                'enable_first_last_frame' => 'in:0,1',
                'enable_multi_image_ref' => 'in:0,1',
                'enable_video_ref' => 'in:0,1',
                'remark' => 'max:255'
            ]);

            if (!empty($data['is_default']) && intval($data['is_default']) === 1) {
                $targetType = isset($data['model_type']) ? $data['model_type'] : $model->model_type;
                Db::name('model_configs')->where('model_type', $targetType)->update(['is_default' => 0]);
            }
            
            foreach (['size_config', 'duration_config', 'aspect_ratio_config', 'quality_config', 'resolution_config'] as $field) {
                if (isset($data[$field]) && is_array($data[$field])) {
                    $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
                }
            }

            $model->save($data);
            return $this->success($model, '更新成功');

        } catch (ValidateException $e) {
            return $this->error($e->getError());
        } catch (\Exception $e) {
            return $this->error('更新失败: ' . $e->getMessage());
        }
    }

    /**
     * Delete Model Config
     */
    public function delete($id)
    {
        try {
            $model = ModelConfigModel::find($id);
            if (!$model) {
                return $this->error('模型配置不存在', 404);
            }

            $model->delete();
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * Update Status
     */
    public function status($id)
    {
        $status = $this->request->param('status');
        
        try {
            $model = ModelConfigModel::find($id);
            if (!$model) {
                return $this->error('模型配置不存在', 404);
            }

            $model->status = $status;
            $model->save();
            
            return $this->success($model, '状态更新成功');
        } catch (\Exception $e) {
            return $this->error('更新失败: ' . $e->getMessage());
        }
    }

    public function setDefault($id)
    {
        try {
            $model = ModelConfigModel::find($id);
            if (!$model) {
                return $this->error('模型配置不存在', 404);
            }

            Db::startTrans();
            Db::name('model_configs')->where('model_type', $model->model_type)->update(['is_default' => 0]);
            $model->is_default = 1;
            $model->save();
            Db::commit();

            return $this->success($model, '已设为默认');
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }
}
