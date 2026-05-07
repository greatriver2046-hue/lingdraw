<?php
namespace app\service\llm;

class MoonshotProvider extends GptProvider
{
    public function chat(array $messages, array $config, array $options = [])
    {
        // Set default endpoint for Moonshot (Kimi) if not provided
        if (empty($config['endpoint'])) {
            $config['endpoint'] = 'https://api.moonshot.cn/v1/chat/completions';
        }
        return parent::chat($messages, $config, $options);
    }
}
