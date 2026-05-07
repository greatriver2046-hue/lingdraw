<?php
namespace app\service\video;

interface VideoProviderInterface
{
    public function generate(string $prompt, array $config, array $options = []): array;
}
