<?php
namespace app\controller\api;

use app\BaseController;
use app\model\User as UserModel;
use app\model\UserPointLog;
use think\facade\Cache;
use think\facade\Request;

class User extends BaseController
{
    /**
     * Get current user info
     */
    public function info()
    {
        $userId = request()->userId;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized']);
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return json(['code' => 404, 'msg' => 'User not found']);
        }

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => $user
        ]);
    }

    /**
     * Get user points log
     */
    public function pointsLog()
    {
        $userId = request()->userId;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized']);
        }

        $limit = Request::param('limit', 20);
        $page = Request::param('page', 1);

        $list = UserPointLog::where('user_id', $userId)
            ->order('create_time', 'desc')
            ->paginate(['list_rows' => $limit, 'page' => $page]);

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

    public function changePassword()
    {
        $userId = request()->userId;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized']);
        }

        $tenantId = (int)(request()->tenantId ?? 0);
        $data = Request::post();
        $oldPassword = (string)($data['old_password'] ?? '');
        $newPassword = (string)($data['new_password'] ?? '');

        if ($oldPassword === '') {
            return json(['code' => 400, 'msg' => '请输入原密码']);
        }
        if ($newPassword === '' || strlen($newPassword) < 6) {
            return json(['code' => 400, 'msg' => '新密码长度不能少于6位']);
        }

        $query = UserModel::where('id', $userId);
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        $user = $query->find();
        if (!$user) {
            return json(['code' => 404, 'msg' => 'User not found or access denied']);
        }

        $salt = (string)($user->salt ?? '');
        $hashedOld = hash('sha256', $oldPassword . $salt);
        if ($hashedOld !== (string)$user->password) {
            return json(['code' => 400, 'msg' => '原密码错误']);
        }

        $newSalt = uniqid();
        $user->salt = $newSalt;
        $user->password = hash('sha256', $newPassword . $newSalt);
        $user->save();

        return json(['code' => 200, 'msg' => 'success']);
    }

    public function bindPhone()
    {
        $userId = request()->userId;
        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized']);
        }

        $tenantId = (int)(request()->tenantId ?? 0);
        $data = Request::post();
        $phone = trim((string)($data['phone'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));

        if ($phone === '' || !preg_match('/^1\\d{10}$/', $phone)) {
            return json(['code' => 400, 'msg' => '请输入正确的手机号']);
        }
        if ($code === '' || !preg_match('/^\\d{4,8}$/', $code)) {
            return json(['code' => 400, 'msg' => '请输入正确的验证码']);
        }

        $query = UserModel::where('id', $userId);
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        $user = $query->find();
        if (!$user) {
            return json(['code' => 404, 'msg' => 'User not found or access denied']);
        }

        if ($tenantId) {
            $exists = UserModel::where('tenant_id', $tenantId)->where('phone', $phone)->where('id', '<>', $userId)->find();
        } else {
            $exists = UserModel::where('phone', $phone)->where('id', '<>', $userId)->find();
        }
        if ($exists) {
            return json(['code' => 400, 'msg' => '手机号已被占用']);
        }

        $codeKey = "sms:code:{$tenantId}:{$phone}";
        $cached = (string)Cache::get($codeKey, '');
        if ($cached === '' || $cached !== $code) {
            return json(['code' => 400, 'msg' => '验证码错误或已过期']);
        }

        $user->phone = $phone;
        $user->save();

        Cache::delete($codeKey);

        return json(['code' => 200, 'msg' => 'success']);
    }

    public function index()
    {
        $limit = Request::param('limit', 10);
        $page = Request::param('page', 1);
        $username = Request::param('username', '');

        $query = UserModel::order('id', 'desc');

        if (!empty($username)) {
            $query->whereLike('username', "%{$username}%");
        }
        
        // Tenant isolation is handled by middleware but we can double check or rely on it
        if (request()->tenantId) {
             $query->where('tenant_id', request()->tenantId);
        }

        $list = $query->paginate(['list_rows' => $limit, 'page' => $page]);
        $items = $list->items();
        $tenantId = (int)(request()->tenantId ?? 0);
        $now = time();

        if ($tenantId > 0 && is_array($items)) {
            foreach ($items as $k => $item) {
                $userId = null;
                if (is_array($item)) {
                    $userId = (int)($item['id'] ?? 0);
                } elseif (is_object($item)) {
                    $userId = (int)($item->id ?? 0);
                }

                $locked = 0;
                $lockUntil = 0;
                if ($userId > 0) {
                    $lockKey = "login_lock:user:{$tenantId}:{$userId}";
                    $lockUntil = (int)Cache::get($lockKey, 0);
                    $locked = $lockUntil > $now ? 1 : 0;
                    if (!$locked) $lockUntil = 0;
                }

                if (is_array($item)) {
                    $item['login_locked'] = $locked;
                    $item['login_lock_until'] = $lockUntil;
                    $items[$k] = $item;
                } elseif (is_object($item)) {
                    $item->login_locked = $locked;
                    $item->login_lock_until = $lockUntil;
                }
            }
        }

        // Return raw data (password and salt are hidden by model)
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'total' => $list->total(),
                'per_page' => $list->listRows(),
                'current_page' => $list->currentPage(),
                'data' => $items
            ]
        ]);
    }

    public function save()
    {
        $id = Request::param('id');
        $data = Request::only(['username', 'password', 'status', 'period_points', 'extra_points', 'email', 'phone']);
        
        $saveData = [];
        if (isset($data['period_points'])) $saveData['period_points'] = $data['period_points'];
        if (isset($data['extra_points'])) $saveData['extra_points'] = $data['extra_points'];
        
        // Ensure remaining_points is synced (though deprecated, keep consistent until column drop)
        // Actually, we should probably stop syncing here if model doesn't do it, but User model still does it in deduct/change?
        // Wait, I removed the sync in User model methods, but direct save() bypasses model methods if I just set attributes.
        // But User model save() triggers model events? No, standard save.
        // Let's manually set remaining_points = period + extra to be safe if I don't remove the column yet.
        if (isset($data['period_points']) || isset($data['extra_points'])) {
             // We need current values if only one is updated?
             // But usually frontend sends both.
             // If we are updating existing user, let's just save what we got.
             // Ideally we calculate total.
             // Let's not worry about remaining_points anymore as I removed it from logic.
        }
        
        if (isset($data['email'])) $saveData['email'] = $data['email'];
        if (isset($data['phone'])) $saveData['phone'] = $data['phone'];

        if (isset($data['status'])) {
             // Frontend sends boolean true/false, DB expects 1/0
             $saveData['status'] = $data['status'] ? 1 : 0;
        }

        if ($id) {
            $query = UserModel::where('id', $id);
            if (request()->tenantId) {
                $query->where('tenant_id', request()->tenantId);
            }
            $user = $query->find();
            
            if (!$user) {
                return json(['code' => 404, 'msg' => 'User not found or access denied']);
            }
            
            // If we are updating points, let's sync remaining_points for legacy support just in case
            if (isset($data['period_points']) || isset($data['extra_points'])) {
                 $p = isset($data['period_points']) ? $data['period_points'] : $user->period_points;
                 $e = isset($data['extra_points']) ? $data['extra_points'] : $user->extra_points;
                 // $saveData['remaining_points'] = $p + $e; // Column deleted
            }
            
            // Update: prevent modifying username and password (simply don't add them to saveData)
            $user->save($saveData);
        } else {
            // Create
            if (empty($data['username'])) {
                 return json(['code' => 400, 'msg' => 'Username required']);
            }
            
            // Check if username already exists
            $exists = UserModel::where('username', $data['username'])->find();
            
            if ($exists) {
                return json(['code' => 400, 'msg' => '用户名已存在，请使用其他用户名']);
            }

            if (empty($data['password'])) {
                 return json(['code' => 400, 'msg' => 'Password required for new user']);
            }

            $saveData['username'] = $data['username'];

            $salt = uniqid();
            $saveData['salt'] = $salt;
            $saveData['password'] = hash('sha256', $data['password'] . $salt);

            $saveData['tenant_id'] = request()->tenantId ?? 1; 
            $saveData['register_time'] = date('Y-m-d H:i:s');
            $saveData['status'] = 1; // Default active
            
            // For new user
            if (!isset($saveData['period_points'])) $saveData['period_points'] = 0;
            if (!isset($saveData['extra_points'])) $saveData['extra_points'] = 0;
            // $saveData['remaining_points'] = $saveData['period_points'] + $saveData['extra_points']; // Column deleted
            
            UserModel::create($saveData);
        }

        return json(['code' => 200, 'msg' => 'Saved successfully']);
    }

    public function delete()
    {
        $id = Request::param('id');
        $query = UserModel::where('id', $id);
        if (request()->tenantId) {
            $query->where('tenant_id', request()->tenantId);
        }
        $user = $query->find();

        if (!$user) {
            return json(['code' => 404, 'msg' => 'User not found or access denied']);
        }

        $user->delete();
        return json(['code' => 200, 'msg' => 'Deleted successfully']);
    }

    public function unlockLogin()
    {
        $id = (int)Request::param('id', 0);
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => 'id不能为空']);
        }

        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) {
            return json(['code' => 403, 'msg' => 'Tenant ID missing'], 403);
        }

        $user = UserModel::where('id', $id)->where('tenant_id', $tenantId)->find();
        if (!$user) {
            return json(['code' => 404, 'msg' => 'User not found or access denied']);
        }

        Cache::delete("login_fail:user:{$tenantId}:{$id}");
        Cache::delete("login_lock:user:{$tenantId}:{$id}");

        return json(['code' => 200, 'msg' => 'success']);
    }
}
