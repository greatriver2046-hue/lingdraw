<?php
namespace app\model;

use think\Model;

class InspirationCategory extends Model
{
    // Explicitly set table name
    protected $name = 'inspiration_categories';

    // Auto timestamp
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
