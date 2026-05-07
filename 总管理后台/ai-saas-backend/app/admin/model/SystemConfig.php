<?php
namespace app\admin\model;

use think\Model;
use think\model\concern\SoftDelete;

class SystemConfig extends Model
{
    use SoftDelete;

    protected $name = 'system_configs';
    protected $autoWriteTimestamp = true;
    protected $deleteTime = 'delete_time';
}

