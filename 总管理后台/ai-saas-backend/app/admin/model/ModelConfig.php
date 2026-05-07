<?php
namespace app\admin\model;

use think\Model;
use think\model\concern\SoftDelete;

class ModelConfig extends Model
{
    use SoftDelete;

    protected $name = 'model_configs';
    protected $autoWriteTimestamp = true;
    protected $deleteTime = 'delete_time';
}
