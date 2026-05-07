<?php
namespace app\service\llm;

class DeepSeekProvider extends GptProvider
{
    public function chat(array $messages, array $config, array $options = [])
    {
        // Set default endpoint for DeepSeek if not provided
        if (empty($config['endpoint'])) {
            $config['endpoint'] = 'https://api.deepseek.com/chat/completions';
        }
        return parent::chat($messages, $config, $options);
    }
}
