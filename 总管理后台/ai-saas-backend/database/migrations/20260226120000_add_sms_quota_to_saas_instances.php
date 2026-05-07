<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddSmsQuotaToSaasInstances extends Migrator
{
    public function change()
    {
        $table = $this->table('saas_instances');
        if (!$table->hasColumn('sms_quota')) {
            $table->addColumn('sms_quota', 'integer', ['default' => 0, 'comment' => 'SMS quota'])
                ->update();
        }
    }
}

