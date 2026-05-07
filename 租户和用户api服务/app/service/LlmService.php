<?php
namespace app\service;

use app\model\ModelConfig;
use app\model\LlmLog;
use app\model\User;
use app\model\SaasInstance;
use app\model\TenantModelCallStat;
use app\service\llm\DoubaoProvider;
use app\service\llm\GptProvider;
use app\service\llm\ClaudeProvider;
use app\service\llm\QwenProvider;
use app\service\llm\DeepSeekProvider;
use app\service\llm\MoonshotProvider;
use app\service\llm\ZhipuProvider;
use think\facade\Log;
use think\facade\Db;

class LlmService
{
    protected $providers = [
        'doubao' => DoubaoProvider::class,
        'doubaoseed' => DoubaoProvider::class, // Map doubaoseed to DoubaoProvider
        'gpt5'   => GptProvider::class,
        'claude' => ClaudeProvider::class,
        'qwen'   => QwenProvider::class,
        'deepseek' => DeepSeekProvider::class,
        'moonshot' => MoonshotProvider::class,
        'kimi'     => MoonshotProvider::class,
        'glm'      => ZhipuProvider::class,
    ];

    /**
     * Unified Chat Interface
     *
     * @param array $messages List of messages [['role' => 'user', 'content' => '...']]
     * @param array $options Options like temperature, stream, model_id (override)
     * @param int|null $userId User ID for point deduction
     * @return mixed Response data or Stream
     */
    public function chat(array $messages, array $options = [], $userId = null)
    {
        $startTime = microtime(true);

        $systemPrompt = isset($options['system_prompt']) && is_string($options['system_prompt']) ? trim($options['system_prompt']) : '';
        if ($systemPrompt !== '') {
            $foundSystem = false;
            foreach ($messages as $idx => $m) {
                if (!is_array($m)) continue;
                if (($m['role'] ?? null) !== 'system') continue;
                $foundSystem = true;
                $existing = isset($m['content']) && is_string($m['content']) ? $m['content'] : '';
                if ($existing === '') {
                    $messages[$idx]['content'] = $systemPrompt;
                } elseif (strpos($existing, $systemPrompt) === false) {
                    $messages[$idx]['content'] = $existing . "\n\n" . $systemPrompt;
                }
                break;
            }
            if (!$foundSystem) {
                array_unshift($messages, ['role' => 'system', 'content' => $systemPrompt]);
            }
        }
        
        // 1. Retrieve Model Config
        $modelConfig = $this->getModelConfig($options['model_identity'] ?? null);
        if (!$modelConfig) {
            throw new \Exception("No active LLM configuration found in model_configs.");
        }

        // 2. Deduct Points & Increment Call Count
        $deducted = false;
        $breakdown = [];
        $cost = 0;
        $tenant = null;

        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $cost = (int)ceil((float)($modelConfig['cost_per_request'] ?? 0));
                Log::info("Deducting points for user {$userId}, cost: {$cost}");
                if ($cost > 0) {
                    $usageType = $options['usage_type'] ?? 'llm_chat';
                    $desc = "Chat with " . ($modelConfig['model_identity'] ?? 'unknown');
                    $tenant = SaasInstance::find($user->tenant_id);
                    if ($tenant) {
                        if (!$tenant->updateQuota($cost)) {
                            throw new \Exception("租户剩余额度不足，无法进行对话。每次调用需消耗 {$cost} 点。");
                        }
                    }

                    if (!$user->deductPoints($cost, $usageType, $desc, null, $breakdown)) {
                        if ($tenant) {
                            $tenant->updateQuota(-$cost);
                        }
                        $avail = ($user->period_points ?? 0) + ($user->extra_points ?? 0);
                        throw new \Exception("您的点数不足，无法进行对话。每次调用需消耗 {$cost} 点。当前可用: {$avail} (套餐: {$user->period_points}, 额外: {$user->extra_points})");
                    }
                    $deducted = true;
                }
            }
        }
        
        if ($modelConfig instanceof ModelConfig) {
             Log::info("Incrementing call count for model {$modelConfig['model_identity']}");
             $modelConfig->incrementCallCount();
        }

        if ($deducted && $user && $user->tenant_id) {
            TenantModelCallStat::addPointsForTenant($user->tenant_id, $modelConfig->id, $cost);
        }

        // 3. Instantiate Provider
        $providerClass = $this->providers[$modelConfig['model_identity']] ?? null;
        
        // Fallback: Try to match provider by partial string if exact match fails
        if (!$providerClass) {
            foreach ($this->providers as $key => $class) {
                if (stripos($modelConfig['model_identity'], $key) !== false) {
                    $providerClass = $class;
                    break;
                }
            }
        }
        
        if (!$providerClass) {
            // Refund if provider not found but points deducted
            if ($deducted && isset($user)) {
                 $user->refundPoints($breakdown['period'] ?? 0, $breakdown['extra'] ?? 0, 'llm_chat_failed', "Provider not supported");
                 if ($tenant) $tenant->updateQuota(-$cost);
            }
            throw new \Exception('LLM provider not supported: ' . $modelConfig['model_identity']);
        }
        $provider = new $providerClass();

        // 3. Prepare Config & Options
        // Merge DB config with runtime options if needed
        // Runtime options take precedence for things like temperature
        
        // 4. Call Provider
        try {
            $response = $provider->chat($messages, $modelConfig->toArray(), $options);
            
            // 5. Log (Non-streaming only for full response capture, or log stream start)
            if (empty($options['skip_log'])) {
                if (empty($options['stream'])) {
                    $this->logRequest($modelConfig, $messages, $response, $startTime);
                } else {
                    // Log stream initiation
                    $this->logRequest($modelConfig, $messages, "STREAM_STARTED", $startTime);
                }
            }

            return $this->formatResponse($response, $modelConfig['model_identity'], $options['stream'] ?? false);

        } catch (\Exception $e) {
            try {
                $tenantId = null;
                if (isset($user) && $user) {
                    $tenantId = $user->tenant_id ?? null;
                }
                $this->writeSystemErrorLog('llm', $e->getMessage(), [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'model_identity' => $modelConfig['model_identity'] ?? null,
                    'usage_type' => $options['usage_type'] ?? null,
                    'conversation_id' => $options['conversation_id'] ?? null,
                    'endpoint' => $options['endpoint'] ?? null,
                    'code' => $options['code'] ?? null,
                    'source' => '租户和用户api服务',
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'error_trace' => mb_substr($e->getTraceAsString(), 0, 2000),
                ]);
            } catch (\Throwable $logE) {
            }
            if ($deducted && isset($user)) {
                 $user->refundPoints($breakdown['period'] ?? 0, $breakdown['extra'] ?? 0, 'llm_chat_failed', "Chat failed: " . substr($e->getMessage(), 0, 50));
                 if ($tenant) $tenant->updateQuota(-$cost);
            }
            $this->logRequest($modelConfig, $messages, null, $startTime, $e->getMessage());
            throw $e;
        }
    }

    protected function getSystemErrorLogColumns(): array
    {
        static $cols = null;
        if (is_array($cols)) return $cols;

        $map = [];
        try {
            $rows = Db::query("SHOW COLUMNS FROM `system_error_logs`");
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (!is_array($r)) continue;
                    $field = $r['Field'] ?? $r['field'] ?? null;
                    $field = is_string($field) ? trim($field) : '';
                    if ($field === '') continue;
                    $map[$field] = 1;
                }
            }
        } catch (\Throwable $e) {
        }

        $cols = $map;
        return $cols;
    }

    protected function writeSystemErrorLog(string $category, string $message, array $context = []): void
    {
        $cat = trim($category) !== '' ? trim($category) : 'general';
        $msg = trim($message);
        if ($msg === '') return;

        $cols = $this->getSystemErrorLogColumns();
        if (!$cols) return;

        $tenantId = $context['tenant_id'] ?? null;
        $userId = $context['user_id'] ?? null;
        $endpoint = isset($context['endpoint']) ? (string)$context['endpoint'] : '';
        $code = isset($context['code']) ? (string)$context['code'] : '';
        $source = isset($context['source']) ? (string)$context['source'] : '租户和用户api服务';

        $row = [];
        if (isset($cols['tenant_id']) && $tenantId !== null) $row['tenant_id'] = (int)$tenantId;
        if (isset($cols['user_id']) && $userId) $row['user_id'] = (int)$userId;

        if (isset($cols['category'])) $row['category'] = $cat;
        if (isset($cols['message'])) $row['message'] = mb_substr($msg, 0, 500);
        if (isset($cols['endpoint'])) $row['endpoint'] = $endpoint;
        if (isset($cols['code'])) $row['code'] = $code;
        if (isset($cols['source'])) $row['source'] = $source;
        if (isset($cols['context'])) $row['context'] = $code !== '' ? $code : $endpoint;
        if (isset($cols['payload'])) $row['payload'] = json_encode($context, JSON_UNESCAPED_UNICODE);
        if (isset($cols['create_time'])) $row['create_time'] = time();
        if (isset($cols['created_at'])) $row['created_at'] = date('Y-m-d H:i:s');

        try {
            Db::table('system_error_logs')->insert($row);
        } catch (\Throwable $e) {
            try {
                Log::error('system_error_logs insert failed: ' . $e->getMessage());
            } catch (\Throwable $e2) {
            }
        }
    }

    /**
     * Recognize Markers Method
     *
     * @param string $imageUrl (URL or Base64 Data URI)
     * @param int|null $userId
     * @return string
     */
    public function recognizeMarkers($imageUrl, $userId = null)
    {
        // 1. Find a vision model
        $model = $this->getFunctionModelConfig('object_detection_model');

        if (!$model) {
            throw new \Exception('未找到可用的视觉理解模型，请联系管理员配置');
        }

        // 2. Get Marker Recognition system prompt
        $systemPrompt = '请识别图中的标记点，并详细描述每个标记点的内容。请严格按照格式“[标记点X]: 描述内容”输出，每个标记点占一行。';
        try {
            $row = Db::table('system_prompts')->order('id', 'desc')->find();
            if ($row && !empty($row['image_marker_prompt'])) {
                $systemPrompt = $row['image_marker_prompt'];
            }
        } catch (\Throwable $e) {
            // Ignore and use default
        }

        // 3. Prepare messages for vision model
        $messages = [
            // User requested to remove system prompt and put it into user text
            // ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $systemPrompt],
                    [
                        'type' => 'image_url',
                        'image_url' => ['url' => $imageUrl]
                    ]
                ]
            ]
        ];

        // 4. Call LLM Service chat
        $options = [
            'model_identity' => $model->model_identity,
            'stream' => false,
            'temperature' => 0.5,
            'usage_type' => 'image_recognition',
        ];

        $result = $this->chat($messages, $options, $userId);

        // 5. Return content directly
        return $result['content'] ?? '';
    }

    /**
     * Reverse Prompt Method (Image Understanding)
     *
     * @param string $imageUrl
     * @param int|null $userId
     * @return string
     */
    public function reversePrompt($imageUrl, $userId = null)
    {
        // 1. Find a vision model
        $model = $this->getFunctionModelConfig('reverse_prompt_model');

        if (!$model) {
            throw new \Exception('未找到可用的视觉理解模型，请联系管理员配置');
        }

        // 2. Get Reverse Prompt system prompt
        $systemPrompt = '请描述这张图片的内容，用于生成类似的图片。';
        try {
            $row = Db::table('system_prompts')->order('id', 'desc')->find();
            if ($row && !empty($row['image_reverse_prompt'])) {
                $systemPrompt = $row['image_reverse_prompt'];
            }
        } catch (\Throwable $e) {
            // Ignore and use default
        }

        // 3. Prepare messages for vision model
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => '请描述这张图片'],
                    [
                        'type' => 'image_url',
                        'image_url' => ['url' => $imageUrl]
                    ]
                ]
            ]
        ];

        // 4. Call LLM Service chat
        $options = [
            'model_identity' => $model->model_identity,
            'stream' => false,
            'temperature' => 0.7, // Higher temperature for creativity
            'usage_type' => 'image_recognition',
        ];

        $result = $this->chat($messages, $options, $userId);

        // 5. Return content directly
        return $result['content'] ?? '';
    }

    /**
     * OCR Method
     *
     * @param string $imageUrl
     * @param int|null $userId
     * @return array
     */
    public function ocr($imageUrl, $userId = null)
    {
        // 1. Find a vision model
        $model = $this->getFunctionModelConfig('ocr_model');

        if (!$model) {
            throw new \Exception('未找到可用的视觉理解模型，请联系管理员配置');
        }

        // 2. Get OCR system prompt
        $systemPrompt = '提取出图中的所有文字，用json数组返回，如果没有文字则返回json空数组。';
        try {
            $row = Db::table('system_prompts')->order('id', 'desc')->find();
            if ($row && !empty($row['image_ocr_prompt'])) {
                $systemPrompt = $row['image_ocr_prompt'];
            }
        } catch (\Throwable $e) {
            // Ignore and use default
        }

        // 3. Prepare messages for vision model
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => '请提取图中的文字'],
                    [
                        'type' => 'image_url',
                        'image_url' => ['url' => $imageUrl]
                    ]
                ]
            ]
        ];

        // 4. Call LLM Service chat
        $options = [
            'model_identity' => $model->model_identity,
            'stream' => false,
            'temperature' => 0.1, // Lower temperature for more accurate OCR
            'usage_type' => 'image_recognition',
        ];

        $result = $this->chat($messages, $options, $userId);

        // 5. Extract JSON array from response
        $content = $result['content'] ?? '';
        
        // Simple regex to find JSON array in content
        $texts = [];
        if (preg_match('/\[[\s\S]*?\]/', $content, $matches)) {
            $jsonStr = $matches[0];
            $decoded = json_decode($jsonStr, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $textVal = '';
                    if (is_string($item)) {
                        $textVal = $item;
                    } elseif (is_array($item)) {
                        if (isset($item['text'])) {
                            $textVal = (string)$item['text'];
                        } elseif (isset($item['content'])) {
                            $textVal = (string)$item['content'];
                        } else {
                            $val = reset($item);
                            if (is_scalar($val)) {
                                $textVal = (string)$val;
                            }
                        }
                    } elseif (is_scalar($item)) {
                        $textVal = (string)$item;
                    }

                    if ($textVal !== '') {
                        $texts[] = [
                            'old' => $textVal,
                            'new' => $textVal
                        ];
                    }
                }
            }
        }

        return array_values(array_filter($texts));
    }

    protected function getFunctionModelConfig($configKey)
    {
        try {
            $configVal = Db::table('system_configs')->where('category', 'default_models')->value('config');
            if ($configVal) {
                $config = json_decode($configVal, true);
                if (!empty($config[$configKey])) {
                    $model = ModelConfig::where('model_id', $config[$configKey])
                        ->where('status', 'active')
                        ->find();
                    if ($model) {
                        return $model;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error("Failed to get default model for {$configKey}: " . $e->getMessage());
        }

        // Fallback: any active vision model
        return ModelConfig::where('model_type', 'vision')
            ->where('status', 'active')
            ->whereNull('delete_time')
            ->order('id', 'desc')
            ->find();
    }

    protected function getModelConfig($identity = null)
    {
        if ($identity) {
            return ModelConfig::where('model_identity', $identity)
                ->where('status', 'active')
                ->find();
        }

        // Try to get default from system_configs
        try {
            $configVal = Db::table('system_configs')->where('category', 'default_models')->value('config');
            if ($configVal) {
                $config = json_decode($configVal, true);
                if (!empty($config['default_llm_model'])) {
                    $model = ModelConfig::where('model_id', $config['default_llm_model'])
                        ->where('status', 'active')
                        ->find();
                    if ($model) {
                        return $model;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to get default LLM from system config: ' . $e->getMessage());
        }

        // Return null if not found
        return null;
    }

    protected function logRequest($model, $messages, $response, $startTime, $error = null)
    {
        $duration = round((microtime(true) - $startTime) * 1000);
        
        // Simple token estimation (chars / 4)
        $inputTokens = 0;
        foreach ($messages as $msg) {
            $content = $msg['content'] ?? '';
            if (is_array($content)) {
                $contentStr = '';
                foreach ($content as $item) {
                    if (isset($item['text'])) {
                        $contentStr .= $item['text'];
                    } elseif (isset($item['type']) && $item['type'] === 'image_url') {
                        $contentStr .= '[IMAGE]';
                    }
                }
                $inputTokens += mb_strlen($contentStr) / 4;
            } else {
                $inputTokens += mb_strlen((string)$content) / 4; // Approximation
            }
        }

        $outputTokens = 0;
        $responseText = '';
        
        if (is_array($response)) {
            $responseText = json_encode($response, JSON_UNESCAPED_UNICODE);
            // Try to extract text for token calc
            // This depends on provider format, simplified here
            $outputTokens = mb_strlen($responseText) / 4; 
        } elseif (is_string($response)) {
            $responseText = $response;
        }

        LlmLog::create([
            'model_identity' => $model['model_identity'],
            'prompt' => json_encode($messages, JSON_UNESCAPED_UNICODE),
            'response' => $error ? null : $responseText, // No truncation for longtext
            'tokens_input' => (int)$inputTokens,
            'tokens_output' => (int)$outputTokens,
            'duration_ms' => $duration,
            'status_code' => $error ? 500 : 200,
            'error_msg' => $error
        ]);
    }

    protected function formatResponse($response, $identity, $isStream)
    {
        if ($isStream) {
            return $response; // Return stream resource directly
        }

        // Normalize response to unified format
        // { 'content': '...', 'usage': { ... } }
        
        $content = '';
        $usage = [];

        // Use loose matching for provider families
        if (stripos($identity, 'doubao') !== false || stripos($identity, 'gpt') !== false || stripos($identity, 'qwen') !== false || stripos($identity, 'glm') !== false || stripos($identity, 'deepseek') !== false || stripos($identity, 'moonshot') !== false || stripos($identity, 'kimi') !== false) {
            // OpenAI compatible format
            $content = $response['choices'][0]['message']['content'] ?? '';
            $usage = $response['usage'] ?? [];

            // Remove thinking process for reasoning models (e.g. <think>...</think>)
            // This applies to models that embed thought process in content
            if (stripos($identity, 'glm') !== false || stripos($identity, 'deepseek') !== false) {
                 $content = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $content);
                 $content = trim($content);
            }
        } elseif ($identity === 'claude') {
            // Anthropic format
            if (isset($response['content']) && is_array($response['content'])) {
                foreach ($response['content'] as $block) {
                    if ($block['type'] === 'text') {
                        $content .= $block['text'];
                    }
                }
            }
            $usage = [
                'input_tokens' => $response['usage']['input_tokens'] ?? 0,
                'output_tokens' => $response['usage']['output_tokens'] ?? 0,
            ];
        }

        return [
            'content' => $content,
            'usage' => $usage,
            'raw' => $response // Debug info
        ];
    }
}
