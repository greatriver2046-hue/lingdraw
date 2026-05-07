<?php

use think\migration\Migrator;

class AddProviderCodeToModelConfigs extends Migrator
{
    public function change()
    {
        $table = $this->table('model_configs');
        if (!$table->hasColumn('provider_code')) {
            $table->addColumn('provider_code', 'string', [
                'limit' => 100,
                'default' => '',
                'comment' => 'Provider code for routing'
            ])->update();
        }
    }
}

