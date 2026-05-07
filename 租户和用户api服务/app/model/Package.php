<?php
namespace app\model;

use think\Model;
use think\model\concern\SoftDelete;

class Package extends Model
{
    use SoftDelete;
    
    protected $name = 'packages';
    protected $autoWriteTimestamp = true;
    protected $deleteTime = 'delete_time';
}
