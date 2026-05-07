<?php

use think\migration\Migrator;

class RenameCallCountToTotalPoints extends Migrator
{
    public function change()
    {
        $table = $this->table('tenant_model_call_stats');
        if ($table->hasColumn('call_count')) {
            $table->renameColumn('call_count', 'total_points')
                  ->save();
        }
    }
}
