<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddHomeConfigToSaasInstances extends Migrator
{
    public function change()
    {
        $table = $this->table('saas_instances');
        
        if (!$table->hasColumn('home_config')) {
            $table->addColumn('home_config', 'text', ['null' => true, 'comment' => '主页配置JSON'])
                  ->save();
        }
    }
}
