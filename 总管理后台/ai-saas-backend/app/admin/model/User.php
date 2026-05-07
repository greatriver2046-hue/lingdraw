<?php
namespace app\admin\model;

use think\Model;
use think\model\concern\SoftDelete;

class User extends Model
{
    use SoftDelete;

    protected $name = 'users';
    protected $autoWriteTimestamp = true;
    protected $deleteTime = 'delete_time';
}

