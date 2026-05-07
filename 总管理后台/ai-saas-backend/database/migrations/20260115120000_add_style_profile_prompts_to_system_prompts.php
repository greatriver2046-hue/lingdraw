<?php

use think\migration\Migrator;

class AddStyleProfilePromptsToSystemPrompts extends Migrator
{
    public function change()
    {
        $table = $this->table('system_prompts');

        $columns = [
            'style_profile_system_prompt' => '作者风格分析 System Prompt',
            'style_profile_user_prompt' => '作者风格分析 User Prompt 模板',
        ];

        foreach ($columns as $name => $comment) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, 'text', ['null' => true, 'comment' => $comment]);
            }
        }

        $table->save();
    }
}

