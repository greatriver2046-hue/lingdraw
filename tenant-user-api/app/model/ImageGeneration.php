<?php
namespace app\model;

use think\Model;

class ImageGeneration extends Model
{
    protected $name = 'image_generations';
    protected $autoWriteTimestamp = false;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}

