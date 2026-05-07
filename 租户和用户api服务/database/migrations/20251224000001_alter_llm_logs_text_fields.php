<?php

use think\migration\Migrator;

class AlterLlmLogsTextFields extends Migrator
{
    public function change()
    {
        // Use raw SQL to ensure LONGTEXT type modification
        $this->execute("ALTER TABLE `llm_logs` MODIFY COLUMN `prompt` LONGTEXT COMMENT '请求内容' NULL;");
        $this->execute("ALTER TABLE `llm_logs` MODIFY COLUMN `response` LONGTEXT COMMENT '响应内容' NULL;");
    }
}
