<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddImageOcrPromptToSystemPrompts extends Migrator
{
    public function change()
    {
        $table = $this->table('system_prompts');
        $table->addColumn('image_ocr_prompt', 'text', ['null' => true, 'comment' => '图片文字提取提示词'])
              ->update();
    }
}
