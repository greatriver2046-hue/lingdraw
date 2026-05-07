<?php

use think\migration\Migrator;

class AddAgentStateMachinePromptsToSystemPrompts extends Migrator
{
    public function change()
    {
        $table = $this->table('system_prompts');

        $columns = [
            'agent_sm_s1_intent_prompt' => '状态机 S1 意图解析 Prompt',
            'agent_sm_s2_plan_prompt' => '状态机 S2 任务规划 Prompt',
            'agent_sm_s3_image_prompt' => '状态机 S3 图片 Prompt 构建 Prompt',
            'agent_sm_s3_video_prompt' => '状态机 S3 视频 Prompt 构建 Prompt',
            'agent_sm_s3_text_prompt' => '状态机 S3 文本回答 Prompt',
            'agent_sm_s5_result_prompt' => '状态机 S5 结果整理 Prompt',
        ];

        foreach ($columns as $name => $comment) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, 'text', ['null' => true, 'comment' => $comment]);
            }
        }

        $table->save();
    }
}

