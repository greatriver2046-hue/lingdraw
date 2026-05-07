<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddImageReversePromptToSystemPrompts extends Migrator
{
    public function change()
    {
        $table = $this->table('system_prompts');
        if (!$table->hasColumn('image_reverse_prompt')) {
            $table->addColumn('image_reverse_prompt', 'text', ['null' => true, 'comment' => 'Image Reverse Prompt System Prompt'])
                  ->save();
        }
    }
}
