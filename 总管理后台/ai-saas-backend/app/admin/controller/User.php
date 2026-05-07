<?php
namespace app\admin\controller;

use app\BaseController;
use app\admin\model\User as UserModel;
use think\exception\ValidateException;

class User extends BaseController
{
    public function index()
    {
        $page = $this->request->param('page', 1);
        $limit = $this->request->param('limit', 10);
        $keyword = $this->request->param('keyword', '');

        $query = UserModel::order('id', 'desc');
        if (!empty($keyword)) {
            $query->where('username|email|phone', 'like', "%{$keyword}%");
        }

        $list = $query->field('id,tenant_id,username,register_time,last_login_time,period_points,extra_points,membership_expire,status')
                      ->paginate([
                          'list_rows' => $limit,
                          'page' => $page
                      ]);

        return $this->success($list);
    }

    public function update($id)
    {
        $data = $this->request->put();
        try {
            $user = UserModel::find($id);
            if (!$user) {
                return $this->error('用户不存在', 404);
            }

            $rules = [
                'username' => 'max:50',
                'period_points' => 'integer',
                'extra_points' => 'integer',
                'membership_expire' => 'date|number',
                'status' => 'in:0,1'
            ];

            $this->validate($data, $rules);

            $allowed = [
                'username',
                'period_points',
                'extra_points',
                'membership_expire',
                'status'
            ];

            $payload = [];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $data)) {
                    $payload[$field] = $data[$field];
                }
            }

            if (empty($payload)) {
                return $this->error('无可更新字段', 400);
            }

            if (isset($payload['membership_expire'])) {
                if (!is_numeric($payload['membership_expire'])) {
                    $ts = strtotime($payload['membership_expire']);
                    if ($ts !== false) {
                        $payload['membership_expire'] = $ts;
                    }
                } else {
                    $payload['membership_expire'] = intval($payload['membership_expire']);
                }
            }

            $user->save($payload);
            return $this->success($user, '更新成功');

        } catch (ValidateException $e) {
            return $this->error($e->getError());
        } catch (\Exception $e) {
            return $this->error('更新失败: ' . $e->getMessage());
        }
    }

    public function status($id)
    {
        $status = $this->request->put('status');
        try {
            $user = UserModel::find($id);
            if (!$user) {
                return $this->error('用户不存在', 404);
            }
            $user->status = $status;
            $user->save();
            return $this->success(null, '状态更新成功');
        } catch (\Exception $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }

    public function delete($id)
    {
        try {
            $user = UserModel::find($id);
            if (!$user) {
                return $this->error('用户不存在', 404);
            }
            $user->delete();
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }
}
