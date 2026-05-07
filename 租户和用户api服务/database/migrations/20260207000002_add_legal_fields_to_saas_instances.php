<?php

use think\migration\Migrator;

class AddLegalFieldsToSaasInstances extends Migrator
{
    public function change()
    {
        $this->execute("ALTER TABLE `saas_instances` ADD COLUMN `user_agreement` LONGTEXT NULL COMMENT '用户协议' AFTER `home_config`;");
        $this->execute("ALTER TABLE `saas_instances` ADD COLUMN `privacy_policy` LONGTEXT NULL COMMENT '隐私政策' AFTER `user_agreement`;");
    }
}

