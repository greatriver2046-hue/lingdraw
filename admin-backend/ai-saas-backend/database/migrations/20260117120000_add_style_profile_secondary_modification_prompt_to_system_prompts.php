<?php

use think\migration\Migrator;

class AddStyleProfileSecondaryModificationPromptToSystemPrompts extends Migrator
{
    public function change()
    {
        $table = $this->table('system_prompts');
        $name = 'style_profile_secondary_modification_prompt';
        if (!$table->hasColumn($name)) {
            $table->addColumn($name, 'text', ['null' => true, 'comment' => '作者风格分析 Style Profile 二次修改 Prompt']);
        }
        $table->save();
    }
}

