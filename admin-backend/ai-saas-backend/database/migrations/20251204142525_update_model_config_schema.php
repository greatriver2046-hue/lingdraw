<?php

use think\migration\Migrator;
use think\migration\db\Column;

class UpdateModelConfigSchema extends Migrator
{
    public function up()
    {
        // Drop model_providers table
        if ($this->hasTable('model_providers')) {
            $this->table('model_providers')->drop()->save();
        }

        // Modify model_configs table
        $table = $this->table('model_configs');
        
        // Rename provider to model_identity
        if ($table->hasColumn('provider')) {
            $table->renameColumn('provider', 'model_identity')
                  ->save();
        }

        // Update column definition to change comment
        $table->changeColumn('model_identity', 'string', ['limit' => 100, 'default' => '', 'comment' => '模型标识'])
              ->save();
    }

    public function down()
    {
        // Note: Down is not strictly required for this task but good practice.
        // For speed/task focus, I'll implement basic revert.
        
        // Recreate model_providers
        if (!$this->hasTable('model_providers')) {
            $this->table('model_providers', ['engine' => 'InnoDB', 'comment' => 'Model Providers'])
                 ->addColumn('name', 'string', ['limit' => 50])
                 ->addColumn('code', 'string', ['limit' => 50])
                 ->addColumn('status', 'string', ['limit' => 20, 'default' => 'active'])
                 ->addColumn('create_time', 'integer', ['default' => 0])
                 ->addColumn('update_time', 'integer', ['default' => 0])
                 ->addColumn('delete_time', 'integer', ['null' => true])
                 ->create();
        }

        // Revert model_configs
        $table = $this->table('model_configs');
        if ($table->hasColumn('model_identity')) {
            $table->renameColumn('model_identity', 'provider')
                  ->save();
            $table->changeColumn('provider', 'string', ['limit' => 50, 'default' => '', 'comment' => 'Provider Name'])
                  ->save();
        }
    }
}
