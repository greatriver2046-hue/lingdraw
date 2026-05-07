<?php
namespace app\service\image;

interface ImageProviderInterface
{
    public function generate(string $prompt, array $config, array $options = []): array;
}
