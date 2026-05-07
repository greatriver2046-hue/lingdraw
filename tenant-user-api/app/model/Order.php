<?php
namespace app\model;

use think\Model;

class Order extends Model
{
    protected $name = 'orders';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
