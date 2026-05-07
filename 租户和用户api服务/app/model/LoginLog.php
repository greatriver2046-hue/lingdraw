<?php

namespace app\model;

use think\Model;

class LoginLog extends Model
{
    protected $name = 'login_logs';
    protected $autoWriteTimestamp = 'datetime';
}
