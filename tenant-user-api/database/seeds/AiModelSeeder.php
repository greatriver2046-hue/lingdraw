<?php

use think\migration\Seeder;

class AiModelSeeder extends Seeder
{
    /**
     * Run Method.
     *
     * Write your database seeder using this method.
     *
     * More information on writing seeders is available here:
     * http://docs.phinx.org/en/latest/seeding.html
     */
    public function run(): void
    {
        $data = [
            [
                'name' => 'Doubao Pro',
                'model_type' => 'llm',
                'model_identity' => 'doubao',
                'model_id' => 'doubao-seed-1-6-251015', // Example from docs
                'api_key' => 'YOUR_VOLCENGINE_API_KEY',
                'endpoint' => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions',
                'is_default' => 1,
                'config' => json_encode(['temperature' => 0.7]),
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'GPT-4o',
                'model_type' => 'llm',
                'model_identity' => 'gpt5', // Using 'gpt5' as identity as requested, mapped to gpt-4o for now
                'model_id' => 'gpt-4o',
                'api_key' => 'YOUR_OPENAI_API_KEY',
                'endpoint' => 'https://api.openai.com/v1/chat/completions',
                'is_default' => 0,
                'config' => json_encode(['temperature' => 1.0]),
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Claude 3.5 Sonnet',
                'model_type' => 'llm',
                'model_identity' => 'claude',
                'model_id' => 'claude-3-5-sonnet-20240620',
                'api_key' => 'YOUR_ANTHROPIC_API_KEY',
                'endpoint' => 'https://api.anthropic.com/v1/messages',
                'is_default' => 0,
                'config' => json_encode(['max_tokens' => 4096]),
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]
        ];

        $this->table('ai_models')->insert($data)->save();
    }
}
