<?php

use think\migration\Migrator;

class AddLlmAssistantPromptToSystemPrompts extends Migrator
{
    public function change()
    {
        $table = $this->table('system_prompts');
        if (!$table->hasColumn('llm_assistant_system_prompt')) {
            $table->addColumn('llm_assistant_system_prompt', 'text', ['null' => true, 'comment' => 'LLM助手系统提示词'])
                  ->save();
        }
    }
}

