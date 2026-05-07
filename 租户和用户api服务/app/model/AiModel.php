<?php
namespace app\model;

use think\Model;

class AiModel extends Model
{
    protected $name = 'ai_models';
    protected $json = ['config'];
    protected $autoWriteTimestamp = true;
}
