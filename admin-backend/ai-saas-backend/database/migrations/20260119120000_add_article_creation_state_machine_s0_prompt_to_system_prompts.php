<?php

use think\migration\Migrator;

class AddArticleCreationStateMachineS0PromptToSystemPrompts extends Migrator
{
    public function change()
    {
        $table = $this->table('system_prompts');
        $name = 'article_sm_s0_intent_system_prompt';

        if (!$table->hasColumn($name)) {
            $table->addColumn($name, 'text', ['null' => true, 'comment' => '文章创作状态机 S0 对话意图解析 System Prompt']);
            $table->save();
        }

        $value = "你是对话意图解析器。你必须只输出严格 JSON，不得输出任何其他文本。";
        $escaped = str_replace("\\", "\\\\", $value);
        $escaped = str_replace("'", "\\'", $escaped);
        $sql = "UPDATE `system_prompts` SET `{$name}` = '" . $escaped . "' WHERE `{$name}` IS NULL OR `{$name}` = ''";
        $this->execute($sql);
    }
}
