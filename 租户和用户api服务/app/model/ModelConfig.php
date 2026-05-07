<?php
namespace app\model;

use think\Model;
use think\model\concern\SoftDelete;

class ModelConfig extends Model
{
    use SoftDelete;
    protected $deleteTime = 'delete_time';
    protected $name = 'model_configs';
    protected $json = ['size_config', 'duration_config', 'quality_config', 'aspect_ratio_config', 'resolution_config'];
    protected $autoWriteTimestamp = true;

    public function incrementCallCount()
    {
        // Use inc() for atomic increment
        return $this->where('id', $this->id)->inc('call_count')->update();
    }
}
