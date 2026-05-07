<?php

use think\migration\Migrator;

class ConvertSystemPromptsToUtf8mb4 extends Migrator
{
    public function change()
    {
        $this->execute('ALTER TABLE `system_prompts` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
}

