<?php
namespace app\admin\model;

use think\Model;

class SaasInstance extends Model
{
    protected $name = 'saas_instances';
    protected $autoWriteTimestamp = true; // Use int timestamp by default as per migration
    protected $readonly = ['create_time'];

    // 自动加密密码
    public function setPasswordAttr($value)
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }
}
