<?php
namespace app\admin\model;

use think\Model;
 

class SystemErrorLog extends Model
{
    protected $name = 'system_error_logs';
    protected $autoWriteTimestamp = false;
}
