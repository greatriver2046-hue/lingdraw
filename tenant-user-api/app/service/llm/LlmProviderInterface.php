<?php
namespace app\service\llm;

interface LlmProviderInterface
{
    /**
     * Send a chat completion request to the LLM provider.
     *
     * @param array $messages The chat history (role, content).
     * @param array $config Provider specific configuration.
     * @param array $options Additional options (stream, temperature, etc.).
     * @return mixed The response (stream or full text).
     */
    public function chat(array $messages, array $config, array $options = []);
}
