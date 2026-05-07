<?php
namespace app\admin\controller;

use app\BaseController;
use app\admin\model\AdminUser as AdminUserModel;
use think\exception\ValidateException;

class AdminUser extends BaseController
{
    /**
     * 获取管理员列表
     */
    public function index()
    {
        $limit = $this->request->param('limit', 10, 'intval');
        $username = $this->request->param('username', '', 'trim');

        $where = [];
        if (!empty($username)) {
            $where[] = ['username', 'like', '%' . $username . '%'];
        }

        try {
            $list = AdminUserModel::where($where)
                ->field('id,username,status,create_time,last_login_time,last_login_ip')
                ->order('id', 'desc')
                ->paginate($limit);

            return $this->success($list);
        } catch (\Exception $e) {
            return $this->error('获取列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 创建管理员
     */
    public function save()
    {
        $data = $this->request->post();
        
        try {
            $this->validate($data, [
                'username' => 'require|max:50|unique:admin_users',
                'password' => 'require|min:6'
            ], [
                'username.require' => '用户名不能为空',
                'username.unique' => '用户名已存在',
                'password.require' => '密码不能为空',
                'password.min' => '密码长度至少6位'
            ]);

            $user = AdminUserModel::create($data);
            return $this->success($user, '创建成功');

        } catch (ValidateException $e) {
            return $this->error($e->getError());
        } catch (\Exception $e) {
            return $this->error('创建失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新管理员
     */
    public function update($id)
    {
        $data = $this->request->put();
        
        try {
            $user = AdminUserModel::find($id);
            if (!$user) {
                return $this->error('用户不存在', 404);
            }

            // 验证规则
            $rules = [
                'username' => 'max:50|unique:admin_users,username,' . $id,
            ];
            
            // 只有在提供密码时才验证密码长度
            if (!empty($data['password'])) {
                $rules['password'] = 'min:6';
            } else {
                unset($data['password']); // 不修改密码
            }

            $this->validate($data, $rules, [
                'username.unique' => '用户名已存在',
                'password.min' => '密码长度至少6位'
            ]);

            $user->save($data);
            return $this->success($user, '更新成功');

        } catch (ValidateException $e) {
            return $this->error($e->getError());
        } catch (\Exception $e) {
            return $this->error('更新失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除管理员
     */
    public function delete($id)
    {
        try {
            $user = AdminUserModel::find($id);
            if (!$user) {
                return $this->error('用户不存在', 404);
            }
            
            if ($id == 1) {
                 return $this->error('超级管理员不能删除', 403);
            }

            $user->delete();
            return $this->success(null, '删除成功');

        } catch (\Exception $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新状态
     */
    public function status($id)
    {
        $status = $this->request->put('status');
        
        try {
            $user = AdminUserModel::find($id);
            if (!$user) {
                return $this->error('用户不存在', 404);
            }
            
            if ($id == 1) {
                return $this->error('超级管理员不能禁用', 403);
            }

            $user->status = $status;
            $user->save();
            return $this->success(null, '状态更新成功');

        } catch (\Exception $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }
}
