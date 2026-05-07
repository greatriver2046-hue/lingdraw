<?php

use think\migration\Migrator;

class AddEraseToolPromptToSystemPrompts extends Migrator
{
    public function change()
    {
        $table = $this->table('system_prompts');
        if (!$table->hasColumn('erase_tool_prompt')) {
            $table->addColumn('erase_tool_prompt', 'text', ['null' => true, 'comment' => '擦除工具默认提示词'])
                  ->update();
        }
    }
}
