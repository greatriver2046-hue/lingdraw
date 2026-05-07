<?php
namespace app\admin\model;

use think\Model;

class AdminUser extends Model
{
    protected $name = 'admin_users';
    protected $autoWriteTimestamp = 'datetime';

    public function setPasswordAttr($value)
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }
}
