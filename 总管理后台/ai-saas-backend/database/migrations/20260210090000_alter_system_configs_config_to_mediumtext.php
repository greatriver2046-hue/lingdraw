<?php

use think\migration\Migrator;

class AlterSystemConfigsConfigToMediumtext extends Migrator
{
    public function change()
    {
        $this->execute("ALTER TABLE `system_configs` MODIFY COLUMN `config` MEDIUMTEXT NULL");
    }
}

