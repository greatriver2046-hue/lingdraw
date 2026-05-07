<?php
namespace app\model;

use think\Model;

class Conversation extends Model
{
    protected $name = 'conversations';
    protected $autoWriteTimestamp = false;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}

