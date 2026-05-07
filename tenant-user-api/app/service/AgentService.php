<?php
namespace app\service;

use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Queue;
use app\service\LlmService;
use app\service\ImageService;
use app\service\VideoService;

class AgentService
{
    protected $llmService;
    protected $imageService;
    protected $videoService;

    public function __construct(LlmService $llmService, ImageService $imageService, VideoService $videoService)
    {
        $this->llmService = $llmService;
        $this->imageService = $imageService;
        $this->videoService = $videoService;
    }

    protected function getSystemDefaultModel($key)
    {
        try {
            $configVal = Db::table('system_configs')->where('category', 'default_models')->value('config');
            if ($configVal) {
                $config = json_decode($configVal, true);
                if (!empty($config[$key])) {
                    return $config[$key];
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '';
    }

    protected function getUserFacingErrorMessage(\Throwable $e): string
    {
        $msg = (string)$e->getMessage();
        if ($msg !== '') {
            if (mb_strpos($msg, '点数不足') !== false) return $msg;
        }
        if ($this->isLlmApiException($e)) {
            return '大语言模型访问出错，请联系管理员';
        }
        return $msg !== '' ? $msg : '系统异常';
    }

    protected function isLlmApiException(\Throwable $e): bool
    {
        $cur = $e;
        while ($cur) {
            $file = (string)$cur->getFile();
            $file = str_replace('/', '\\', $file);
            if ($file !== '') {
                if (stripos($file, '\\app\\service\\llm\\') !== false) return true;
                if (stripos($file, '\\app\\service\\LlmService.php') !== false) return true;
            }

            $msg = (string)$cur->getMessage();
            if ($msg !== '') {
                if (stripos($msg, ' API Error') !== false) return true;
                if (stripos($msg, 'No active LLM configuration') !== false) return true;
                if (stripos($msg, 'LLM provider not supported') !== false) return true;
            }

            $cur = $cur->getPrevious();
        }
        return false;
    }

    /**
     * Process Agent Chat
     * 
     * @param int|null $userId
     * @param array $messages
     * @param array $options
     * @param callable $pushCallback function(array $data)
     */
    public function processChat($userId, array $messages, array $options, callable $pushCallback)
    {
        $mode = isset($options['agent_mode']) ? (string)$options['agent_mode'] : '';
        if ($mode === 'legacy') {
            return $this->processChatLegacy($userId, $messages, $options, $pushCallback);
        }
        return $this->processChatStateMachine($userId, $messages, $options, $pushCallback);
    }

    protected function processChatLegacy($userId, array $messages, array $options, callable $pushCallback)
    {
        try {
            if (empty($options['model']) && empty($options['model_identity'])) {
                $def = $this->getSystemDefaultModel('default_general_llm_model');
                if ($def) {
                    // Try to resolve model_id to model_identity
                    $resolvedIdentity = Db::table('model_configs')
                        ->where('model_id', $def)
                        ->value('model_identity');
                    
                    if ($resolvedIdentity) {
                        $options['model_identity'] = $resolvedIdentity;
                    } else {
                        $options['model_identity'] = $def;
                    }
                }
            }

            Log::channel('agent_llm')->write('agent_llm_model_select_legacy: ' . ($options['model_identity'] ?? 'none'), 'info');

            $messages = $this->prepareSystemPrompt($messages, $options);
            $messages = $this->detectHallucination($messages);
            $this->persistUserMessage($messages, $options);

            $tools = $this->getTools();
            $options['tools'] = $tools;
            $options['tool_choice'] = 'auto';
            $options['stream'] = true;

            if (!isset($options['temperature'])) {
                $options['temperature'] = 0.3;
            }

            Log::channel('agent_llm')->write('agent_llm_request:' . $this->trimLogValue([
                'stage' => 'legacy',
                'user_id' => $userId,
                'messages' => $messages,
                'options' => $options,
            ]), 'info');
            $result = $this->llmService->chat($messages, $options, $userId);

            $accumulatedContent = '';
            $accumulatedToolCalls = [];

            if ($result instanceof \Psr\Http\Message\StreamInterface) {
                $buffer = '';

                $processLine = function($line) use (&$accumulatedContent, &$accumulatedToolCalls, $pushCallback) {
                    $line = trim($line);
                    if ($line === '') return;

                    $data = null;
                    if (strpos($line, 'data:') === 0) {
                        $payload = trim(substr($line, 5));
                        if ($payload === '[DONE]') return;
                        $data = json_decode($payload, true);
                    } else {
                        $data = json_decode($line, true);
                    }

                    if (is_array($data)) {
                        if (isset($data['choices'][0]['delta'])) {
                            $delta = $data['choices'][0]['delta'];

                            if (isset($delta['reasoning_content']) && is_string($delta['reasoning_content'])) {
                                $rc = $delta['reasoning_content'];
                                $accumulatedContent .= $rc;
                                $pushCallback(['type' => 'content_delta', 'content' => $rc]);
                            }

                            if (isset($delta['content']) && is_string($delta['content'])) {
                                $c = $delta['content'];
                                $accumulatedContent .= $c;
                                $pushCallback(['type' => 'content_delta', 'content' => $c]);
                            }

                            if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                                foreach ($delta['tool_calls'] as $tc) {
                                    $idx = $tc['index'];
                                    if (!isset($accumulatedToolCalls[$idx])) {
                                        $accumulatedToolCalls[$idx] = [
                                            'id' => $tc['id'] ?? '',
                                            'type' => 'function',
                                            'function' => [
                                                'name' => $tc['function']['name'] ?? '',
                                                'arguments' => $tc['function']['arguments'] ?? ''
                                            ]
                                        ];
                                    } else {
                                        if (isset($tc['id'])) $accumulatedToolCalls[$idx]['id'] = $tc['id'];
                                        if (isset($tc['function']['name'])) $accumulatedToolCalls[$idx]['function']['name'] .= $tc['function']['name'];
                                        if (isset($tc['function']['arguments'])) $accumulatedToolCalls[$idx]['function']['arguments'] .= $tc['function']['arguments'];
                                    }
                                }
                            }
                        } elseif (isset($data['choices'][0]['message']['content'])) {
                            $c = $data['choices'][0]['message']['content'];
                            if (is_string($c)) {
                                $accumulatedContent .= $c;
                                $pushCallback(['type' => 'content_delta', 'content' => $c]);
                            }
                        }
                    }
                };

                while (!$result->eof()) {
                    $chunk = $result->read(1024);
                    if ($chunk === '') { usleep(10000); continue; }
                    $buffer .= $chunk;

                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 1);
                        $processLine($line);
                    }
                }

                if ($buffer !== '') {
                    $processLine($buffer);
                }
            } else {
                // Handle non-stream response (e.g. from DoubaoProvider or other HTTP-based providers)
                if (is_array($result)) {
                    // Check if it's LlmService normalized format
                    if (isset($result['content']) && is_string($result['content'])) {
                        $accumulatedContent = $result['content'];
                        $pushCallback(['type' => 'content_delta', 'content' => $accumulatedContent]);
                        
                        // Check for tool calls in raw response if available
                        if (isset($result['raw']['choices'][0]['message']['tool_calls'])) {
                            $accumulatedToolCalls = $result['raw']['choices'][0]['message']['tool_calls'];
                        }
                    } 
                    // Fallback to OpenAI format
                    elseif (isset($result['choices'][0]['message'])) {
                        $msg = $result['choices'][0]['message'];
                        if (isset($msg['content']) && is_string($msg['content'])) {
                            $accumulatedContent = $msg['content'];
                            $pushCallback(['type' => 'content_delta', 'content' => $accumulatedContent]);
                        }
                        if (isset($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                            $accumulatedToolCalls = $msg['tool_calls'];
                        }
                    }
                }
            }

            Log::channel('agent_llm')->write('agent_llm_response:' . $this->trimLogValue([
                'stage' => 'legacy',
                'user_id' => $userId,
                'content' => $accumulatedContent,
                'tool_calls' => array_values($accumulatedToolCalls),
            ]), 'info');
            if (!empty($accumulatedToolCalls)) {
                foreach ($accumulatedToolCalls as $idx => &$tc) {
                    if (empty($tc['id'])) {
                        $tc['id'] = 'call_' . uniqid() . mt_rand(1000, 9999);
                    }
                }
                unset($tc);

                $this->handleToolCalls($userId, $messages, $options, $accumulatedContent, $accumulatedToolCalls, $pushCallback);
            } else {
                if ($accumulatedContent === '') {
                    $accumulatedContent = '（无回复内容）';
                    $pushCallback(['type' => 'content_delta', 'content' => $accumulatedContent]);
                }
                $pushCallback(['type' => 'done', 'content' => $accumulatedContent]);
                $this->persistAssistantMessage($options, $accumulatedContent);
            }

        } catch (\Throwable $e) {
            Log::error("Agent error: " . $e->getMessage());
            $pushCallback(['type' => 'error', 'msg' => $this->getUserFacingErrorMessage($e)]);
        }
    }

    protected function processChatStateMachine($userId, array $messages, array $options, callable $pushCallback)
    {
        try {
            if (empty($options['model']) && empty($options['model_identity'])) {
                $mode = isset($options['agent_mode']) ? (string)$options['agent_mode'] : '';
                $configKey = ($mode === 'article_sm') ? 'default_article_llm_model' : 'default_general_llm_model';
                $def = $this->getSystemDefaultModel($configKey);
                if ($def) {
                    // Try to resolve model_id to model_identity
                    $resolvedIdentity = Db::table('model_configs')
                        ->where('model_id', $def)
                        ->value('model_identity');
                    
                    if ($resolvedIdentity) {
                        $options['model_identity'] = $resolvedIdentity;
                    } else {
                        $options['model_identity'] = $def;
                    }
                }
            }

            Log::channel('agent_llm')->write('agent_llm_model_select_sm: ' . ($options['model_identity'] ?? 'none'), 'info');

            $this->persistUserMessage($messages, $options);

            $userText = $this->extractLastUserText($messages);
            if ($userText === '') {
                $pushCallback(['type' => 'done', 'content' => '请输入内容后再试。']);
                return;
            }

            $mode = isset($options['agent_mode']) ? (string)$options['agent_mode'] : '';
            $referenceImages = [];
            if (!empty($options['reference_images']) && is_array($options['reference_images'])) {
                $referenceImages = array_values(array_filter($options['reference_images'], function($v) { return is_string($v) && trim($v) !== ''; }));
            }

            $this->pushSmLogStatus($pushCallback, '现在我将开始思考用户需要做什么...');

            $promptRow = null;
            try {
                $promptRow = Db::table('system_prompts')->order('id', 'desc')->find();
            } catch (\Throwable $e) {
                $promptRow = null;
            }

            if ($mode === 'article_sm') {
                $s0Prompt = $this->pickPrompt($promptRow, 'article_sm_s0_intent_system_prompt', $this->defaultS0IntentPrompt());
                $contextText = $this->buildSmContext($messages, $options, $userText);
                $s0Input = $contextText !== ''
                    ? ("对话上下文：\n" . $contextText . "\n\n用户本轮输入：\n" . $userText)
                    : $userText;

                $this->pushSmLogStatus($pushCallback, '正在判断是否需要开始写文章…');
                $s0System = rtrim($s0Prompt);
                $llmOptionsForSm = $this->buildSmLlmOptions($options);
                $parsed = $this->callJsonStage($userId, $s0System, $s0Input, 0.0, $llmOptionsForSm);
                $norm = $this->normalizeArticleS0($parsed, $options);

                if (($norm['intent'] ?? '') !== 'WRITE_ARTICLE') {
                    $msg = (string)($norm['reply'] ?? '');
                    if (trim($msg) === '') $msg = '如果你想开始写文章，请告诉我：主题/要点、体裁（字数可选），以及其他要求。';
                    $pushCallback(['type' => 'content_delta', 'content' => $msg]);
                    $pushCallback(['type' => 'done', 'content' => $msg]);
                    $this->persistAssistantMessage($options, $msg);
                    return;
                }

                $missing = is_array($norm['missing'] ?? null) ? $norm['missing'] : [];
                if (count($missing) > 0) {
                    $ask = (string)($norm['ask'] ?? '');
                    if (trim($ask) === '') {
                        $ask = $this->buildArticleMissingQuestion($missing, $norm);
                    }
                    $this->pushSmLog($pushCallback, '信息不足：' . implode('、', $missing));
                    $pushCallback(['type' => 'done', 'content' => $ask]);
                    $this->persistAssistantMessage($options, $ask);
                    return;
                }

                $this->pushSmLogStatus($pushCallback, '信息已齐全，正在创建写作任务…');
                try {
                    $created = $this->createWritingTaskFromAgent($userId, $norm, $options);
                    $taskId = (string)($created['task_id'] ?? '');
                    $workResourceId = (string)($created['work_resource_id'] ?? '');
                    $pushCallback([
                        'type' => 'article_task',
                        'data' => [
                            'task_id' => $taskId,
                            'work_resource_id' => $workResourceId,
                            'topic' => (string)($norm['topic'] ?? ''),
                            'genre' => (string)($norm['genre'] ?? ''),
                            'word_count' => (int)($norm['word_count'] ?? 0),
                            'style_id' => (string)($norm['style_id'] ?? ''),
                            'style_profile_id' => (string)($norm['style_profile_id'] ?? ''),
                        ],
                    ]);
                    $msg = $taskId !== '' ? ('已开始写作，任务ID：' . $taskId) : '已开始写作。';
                    $pushCallback(['type' => 'content_delta', 'content' => $msg]);
                    $pushCallback(['type' => 'done', 'content' => $msg]);
                    $this->persistAssistantMessage($options, $msg);
                    return;
                } catch (\Throwable $e) {
                    $err = '创建写作任务失败：' . $e->getMessage();
                    $pushCallback(['type' => 'content_delta', 'content' => $err]);
                    $pushCallback(['type' => 'done', 'content' => $err]);
                    $this->persistAssistantMessage($options, $err);
                    return;
                }
            }

            $s1Prompt = $this->pickPrompt($promptRow, 'agent_sm_s1_intent_prompt', $this->defaultS1IntentPrompt());
            $s2Prompt = $this->pickPrompt($promptRow, 'agent_sm_s2_plan_prompt', $this->defaultS2PlanPrompt());
            $s3ImagePrompt = $this->pickPrompt($promptRow, 'agent_sm_s3_image_prompt', $this->defaultS3ImagePrompt());
            $s3VideoPrompt = $this->pickPrompt($promptRow, 'agent_sm_s3_video_prompt', $this->defaultS3VideoPrompt());
            $s3TextPrompt = $this->pickPrompt($promptRow, 'agent_sm_s3_text_prompt', $this->defaultS3TextPrompt());
            $s5Prompt = $this->pickPrompt($promptRow, 'agent_sm_s5_result_prompt', $this->defaultS5ResultPrompt());

            $contextText = $this->buildSmContext($messages, $options, $userText);
            $contextTextForS3 = $this->buildSmContext($messages, $options, $userText, 50, 10);
            $llmOptionsForSm = $this->buildSmLlmOptions($options);

            $this->pushSmStatus($pushCallback, '正在识别用户意图…');
            $s1SystemPrompt = $s1Prompt . "\n\n补充规则：\n- 输入可能包含“对话上下文”和“用户本轮输入”两段。\n- 主要以“用户本轮输入”为准；上下文仅用于消解省略与指代（如“再来一张”“同样风格”“把刚才那张改一下”）。\n- 仍然只输出 JSON。";
            $s1Input = $contextText !== ''
                ? ("对话上下文：\n" . $contextText . "\n\n用户本轮输入：\n" . $userText)
                : $userText;
            $intent = $this->callJsonStage($userId, $s1SystemPrompt, $s1Input, 0.0, $llmOptionsForSm);
            if (!is_array($intent)) {
                $intent = $this->fallbackIntent($userText, $options);
            }
            $this->pushSmLog($pushCallback, '用户需求：' . $this->formatIntentLabel($intent));

            $intentType = strtoupper(trim((string)($intent['intent'] ?? '')));
            if ($intentType === 'TEXT_ONLY') {
                $answer = trim((string)($intent['text_only_content'] ?? ''));
                if ($answer === '') $answer = '（无回复内容）';
                $this->pushSmLogStatus($pushCallback, '已识别为纯文本问题，直接回复…');
                $pushCallback(['type' => 'content_delta', 'content' => $answer]);
                $pushCallback(['type' => 'done', 'content' => $answer]);
                $this->persistAssistantMessage($options, $answer);
                return;
            }

            $this->pushSmLogStatus($pushCallback, '现在我将根据用户需求规划任务...');
            $s2SystemPrompt = $s2Prompt . "\n\n补充规则：\n- 输入可能包含“对话上下文”，用于消解省略与指代。\n- 主要以“用户原始输入”为准；上下文仅用于补全缺失信息。\n- 仍然只输出 JSON。\n- 顶层必须输出 JSON object，并包含 tasks 数组字段。";
            $planInputCore = "用户原始输入：\n" . $userText . "\n\n意图识别输出 JSON：\n" . json_encode($intent, JSON_UNESCAPED_UNICODE);
            if (count($referenceImages) > 0) {
                $planInputCore .= "\n\n参考图 URL 列表：\n" . json_encode($referenceImages, JSON_UNESCAPED_UNICODE);
            }
            $planInput = $contextText !== '' ? ("对话上下文：\n" . $contextText . "\n\n" . $planInputCore) : $planInputCore;
            $plan = $this->callJsonStage($userId, $s2SystemPrompt, $planInput, 0.2, $llmOptionsForSm);
            if (!is_array($plan)) {
                $plan = [];
            }

            $tasks = $this->normalizePlanTasks($plan, $intent, $userText, $options, $referenceImages);
            if (count($tasks) === 0) {
                $tasks = $this->normalizePlanTasks([], $intent, $userText, $options, $referenceImages);
            }

            $this->pushSmLog($pushCallback, '任务规划：');
            $this->pushSmLogPlanTasks($pushCallback, $tasks);

            $needConfirm = false;
            foreach ($tasks as $t) {
                if (is_array($t) && !empty($t['need_confirm'])) { $needConfirm = true; break; }
            }
            if ($needConfirm) {
                $msg = "你的需求有点不够明确。\n请补充：你希望生成几张？每张的画面比例与分辨率分别是什么？";
                $pushCallback(['type' => 'content_delta', 'content' => $msg]);
                $pushCallback(['type' => 'done', 'content' => $msg]);
                $this->persistAssistantMessage($options, $msg);
                return;
            }

            $preparedTasks = $this->preparePlannedTasks($tasks, $intent, $userText, $options, $referenceImages);
            $this->pushSmLogStatus($pushCallback, '任务规划完毕，现在我要开始生成工具参数...');
            $this->pushSmLog($pushCallback, '参数选择：');
            $this->pushSmLogChosenParams($pushCallback, $preparedTasks);

            if (count($preparedTasks) === 1 && (($preparedTasks[0]['task_type'] ?? '') === 'TEXT_ONLY')) {
                $this->pushSmLogStatus($pushCallback, '现在我将开始生成文本回复...');
                $answer = $this->callTextStage($userId, $s3TextPrompt, $this->applyUserInputTemplate($s3TextPrompt, $userText), 0.7, $llmOptionsForSm);
                if ($answer === '') $answer = '（无回复内容）';
                $this->pushSmLog($pushCallback, '回复生成：已生成文本回复');
                $pushCallback(['type' => 'content_delta', 'content' => $answer]);
                $pushCallback(['type' => 'done', 'content' => $answer]);
                $this->persistAssistantMessage($options, $answer);
                return;
            }

            $sanitizedForBuilder = $this->sanitizeForPromptBuild($userText);
            $buildPrompt = function(string $systemPrompt, string $extraHint) use ($userId, $contextTextForS3, $sanitizedForBuilder, $llmOptionsForSm): string {
                $builderInputCore = $this->applyUserInputTemplate($systemPrompt, $sanitizedForBuilder);
                $core = $builderInputCore;
                $hint = trim($extraHint);
                if ($hint !== '') {
                    $core .= "\n\n" . $hint;
                }
                $builderInput = $contextTextForS3 !== ''
                    ? ("对话上下文：\n" . $contextTextForS3 . "\n\n用户本轮输入：\n" . $core)
                    : $core;
                $p = $this->callTextStage($userId, $systemPrompt, $builderInput, 0.7, $llmOptionsForSm);
                if ($p === '') $p = $sanitizedForBuilder;
                return $p;
            };

            $totalTasks = count($preparedTasks);
            $this->pushSmLogStatus($pushCallback, '现在我要开始优化一下用户的提示词...');
            $firstPrompt = '';
            $promptLogged = false;
            $executeLogged = false;
            $seenPrompts = [];
            $submitted = 0;
            $firstTaskId = '';

            for ($i = 1; $i <= $totalTasks; $i++) {
                $task = $preparedTasks[$i - 1];
                $taskType = (string)($task['task_type'] ?? '');
                $imageName = (string)($task['image_name'] ?? '');
                $modelIdentity = (string)($task['model_identity'] ?? '');
                $aspectRatio = (string)($task['aspect_ratio'] ?? '');
                $resolution = (string)($task['resolution'] ?? '');
                $taskReferenceImages = [];
                if (!empty($task['reference_images']) && is_array($task['reference_images'])) {
                    $taskReferenceImages = array_values(array_filter($task['reference_images'], function($v) { return is_string($v) && trim($v) !== ''; }));
                }
                if (count($taskReferenceImages) === 0) $taskReferenceImages = $referenceImages;

                $taskHintParts = [];
                if ($totalTasks > 1) {
                    $taskHintParts[] = "批量信息：第 {$i}/{$totalTasks} 张。请在保持主题一致的前提下，给出与其他张不同的构图/镜头/细节，避免输出完全相同的提示词。";
                }
                if ($imageName !== '') $taskHintParts[] = "作品名：{$imageName}。";
                if ($modelIdentity !== '') $taskHintParts[] = "目标生成模型：{$modelIdentity}。";
                if ($aspectRatio !== '') $taskHintParts[] = "画面比例：{$aspectRatio}。";
                if ($resolution !== '') $taskHintParts[] = "分辨率：{$resolution}。";
                //$taskHintParts[] = "请根据目标生成模型的特性自主判断：如果该模型的API通过独立参数传递比例和分辨率（如size、aspect_ratio字段），则不要在最终prompt中写入比例、分辨率、模型名称；如果该模型需要在prompt文本中内嵌比例或分辨率参数（如Midjourney风格的--ar参数），则按该模型的要求格式保留。无论哪种情况，都不要输出模型名称。";
                $taskHint = implode("\n", $taskHintParts);

                $builderPrompt = $taskType === 'TEXT_TO_VIDEO' ? $s3VideoPrompt : $s3ImagePrompt;
                $taskPrompt = $buildPrompt($builderPrompt, $taskHint);

                $hash = md5($taskPrompt);
                if (isset($seenPrompts[$hash])) {
                    $taskPrompt = rtrim($taskPrompt) . "（变体{$i}/{$totalTasks}）";
                    $hash = md5($taskPrompt);
                }
                $seenPrompts[$hash] = 1;

                if ($firstPrompt === '') $firstPrompt = $taskPrompt;

                if (!$promptLogged && $taskPrompt !== '') {
                    $promptSummary = $totalTasks > 1
                        ? ('批量：' . $totalTasks . '；' . $this->formatPromptSummary($taskPrompt))
                        : $this->formatPromptSummary($taskPrompt);
                    $this->pushSmLog($pushCallback, '提示词构建：' . $promptSummary);
                    $promptLogged = true;
                }

                if (!$executeLogged) {
                    $this->pushSmLogStatus($pushCallback, '我已经做好所有准备工作，现在开始执行任务...');
                    $executeLogged = true;
                }

                $meta = [
                    'task_type' => $taskType !== '' ? $taskType : 'TEXT_TO_IMAGE',
                    'conversation_id' => $options['conversation_id'] ?? null,
                    'image_name' => $imageName !== '' ? $imageName : ($taskType === 'TEXT_TO_VIDEO' ? '生成视频' : '生成图像'),
                    'aspect_ratio' => $aspectRatio,
                    'resolution' => $resolution,
                    'batch_index' => $i,
                    'batch_total' => $totalTasks,
                ];

                try {
                    if ($taskType === 'TEXT_TO_VIDEO') {
                        $meta['duration'] = (int)($options['video_duration'] ?? 10);
                        if ($meta['aspect_ratio'] === '') $meta['aspect_ratio'] = $options['video_aspect_ratio'] ?? '16:9';
                        if ($meta['resolution'] === '') $meta['resolution'] = $options['video_resolution'] ?? '720P';

                        $toolOptions = [
                            'model_identity' => $modelIdentity !== '' ? $modelIdentity : 'sora2',
                            'task_type' => 'TEXT_TO_VIDEO',
                            'aspect_ratio' => $meta['aspect_ratio'],
                            'duration' => $meta['duration'],
                            'resolution' => $meta['resolution'],
                            'tool_meta' => $meta
                        ];

                        $res = $this->videoService->generateAsync($taskPrompt, $toolOptions, $userId);
                    } else {
                        $toolOptions = [
                            'model_identity' => $modelIdentity !== '' ? $modelIdentity : 'seedream4.5',
                            'size' => $resolution !== '' ? $resolution : '1024x1024',
                            'resolution' => $resolution !== '' ? $resolution : '1024x1024',
                            'aspect_ratio' => $aspectRatio !== '' ? $aspectRatio : '1:1',
                            'reference_images' => $taskReferenceImages,
                            'n' => 1,
                            'tool_meta' => $meta,
                        ];

                        $res = $this->imageService->generateAsync($taskPrompt, $toolOptions, $userId);
                    }
                } catch (\Throwable $e) {
                    $this->pushSmLog($pushCallback, '任务执行失败，正在整理结果...');
                    $s5SystemPrompt = $s5Prompt . "\n\n补充规则：\n- 任务执行失败。\n- 输入包含“对话上下文”、“用户本轮输入”和“错误信息”。\n- 请向用户解释错误原因，并给出建议。\n- 不要输出 JSON，直接输出文本回复。";
                    $s5Input = $contextText !== '' 
                        ? ("对话上下文：\n" . $contextText . "\n\n用户本轮输入：\n" . $userText . "\n\n错误信息：\n" . $e->getMessage())
                        : ("用户本轮输入：\n" . $userText . "\n\n错误信息：\n" . $e->getMessage());
                    
                    $explanation = $this->callTextStage($userId, $s5SystemPrompt, $s5Input, 0.7, $llmOptionsForSm);
                    if ($explanation === '') $explanation = '任务执行失败：' . $e->getMessage();
                    
                    $pushCallback(['type' => 'content_delta', 'content' => $explanation]);
                    $pushCallback(['type' => 'done', 'content' => $explanation]);
                    $this->persistAssistantMessage($options, $explanation);
                    return;
                }

                $taskId = $res['task_id'] ?? '';
                if ($taskId !== '') {
                    if ($firstTaskId === '') $firstTaskId = $taskId;
                    $submitted++;
                    $pushCallback([
                        'type' => 'tool_task',
                        'data' => [
                            'task_id' => $taskId,
                            'tool_meta' => $meta,
                            'image_name' => $meta['image_name']
                        ]
                    ]);
                }
            }

            $submitLine = $submitted > 1
                ? ('任务提交：已提交 ' . $submitted . ' 个任务' . ($firstTaskId !== '' ? ('，首个任务ID：' . $firstTaskId) : ''))
                : ('任务提交：' . ($firstTaskId !== '' ? ('已提交，任务ID：' . $firstTaskId) : '已提交'));
            $this->pushSmLog($pushCallback, $submitLine);
            $this->pushSmStatus($pushCallback, '等待生成结果…');
            $pushCallback(['type' => 'done', 'content' => '']);
        } catch (\Throwable $e) {
            Log::error("Agent state machine error: " . $e->getMessage());
            $this->pushSmStatus($pushCallback, '生成失败');
            $pushCallback(['type' => 'error', 'msg' => $this->getUserFacingErrorMessage($e)]);
        }
    }

    protected function pushSmStatus(callable $pushCallback, string $text): void
    {
        $t = trim($text);
        if ($t === '') return;
        $pushCallback(['type' => 'sm_status', 'content' => $t]);
    }

    protected function pushSmLog(callable $pushCallback, string $line): void
    {
        $t = trim($line);
        if ($t === '') return;
        $this->pushSmStep($pushCallback, '', $t);
    }

    protected function pushSmLogStatus(callable $pushCallback, string $line): void
    {
        $this->pushSmStatus($pushCallback, $line);
        $this->pushSmLog($pushCallback, $line);
    }

    protected function pushSmStep(callable $pushCallback, string $title, string $summary): void
    {
        $ttl = trim($title);
        $sum = trim($summary);
        if ($ttl === '' && $sum === '') return;
        $pushCallback(['type' => 'sm_step', 'title' => $ttl, 'summary' => $sum]);
    }

    protected function formatIntentLabel(array $intent): string
    {
        $i = isset($intent['intent']) ? (string)$intent['intent'] : '';
        $labelMap = [
            'IMAGE_GENERATION' => '文生图',
            'IMAGE_EDIT' => '图片编辑',
            'IMAGE_REFERENCE' => '参考图生成',
            'IMAGE_FUSION' => '多图融合',
            'VIDEO_GENERATION' => '视频生成',
            'USER_INFO' => '用户信息查询',
            'TEXT_ONLY' => '纯文本回答',
        ];
        $label = $labelMap[$i] ?? ($i !== '' ? $i : '未知');
        $hasUrl = isset($intent['has_image_url']) ? (bool)$intent['has_image_url'] : false;
        $isBatch = isset($intent['is_batch_request']) ? (bool)$intent['is_batch_request'] : false;
        $parts = [$label];
        if ($hasUrl) $parts[] = '包含图片/链接';
        if ($isBatch) $parts[] = '批量倾向';
        return implode('；', $parts);
    }

    protected function formatIntentSummary(array $intent): string
    {
        $i = isset($intent['intent']) ? (string)$intent['intent'] : '';
        $labelMap = [
            'IMAGE_GENERATION' => '文生图',
            'IMAGE_EDIT' => '图片编辑',
            'IMAGE_REFERENCE' => '参考图生成',
            'IMAGE_FUSION' => '多图融合',
            'VIDEO_GENERATION' => '视频生成',
            'USER_INFO' => '用户信息查询',
            'TEXT_ONLY' => '纯文本回答',
        ];
        $label = $labelMap[$i] ?? ($i !== '' ? $i : '未知');
        $hasUrl = isset($intent['has_image_url']) ? (bool)$intent['has_image_url'] : false;
        $isBatch = isset($intent['is_batch_request']) ? (bool)$intent['is_batch_request'] : false;
        $parts = ["意图：{$label}"];
        if ($hasUrl) $parts[] = '包含图片/链接';
        if ($isBatch) $parts[] = '批量倾向';
        return implode('；', $parts);
    }

    protected function pushSmLogPlanTasks(callable $pushCallback, array $tasks): void
    {
        $typeLabelMap = [
            'TEXT_TO_IMAGE' => '文生图',
            'EDIT_SINGLE_IMAGE' => '编辑单图',
            'REFERENCE_TO_IMAGE' => '参考图生图',
            'FUSION_MULTI_IMAGES' => '多图融合',
            'TEXT_TO_VIDEO' => '文生视频',
            'TEXT_ONLY' => '纯文本',
        ];
        $i = 1;
        foreach ($tasks as $t) {
            if (!is_array($t)) continue;
            $name = isset($t['image_name']) && (is_string($t['image_name']) || is_scalar($t['image_name'])) ? trim((string)$t['image_name']) : '';
            if ($name === '') $name = '任务' . $i;
            $tt = isset($t['task_type']) ? trim((string)$t['task_type']) : '';
            $typeLabel = $typeLabelMap[$tt] ?? ($tt !== '' ? $tt : '未知');
            $ar = '';
            if (isset($t['aspect_ratios']) && is_array($t['aspect_ratios']) && count($t['aspect_ratios']) > 0) {
                $ar = trim((string)$t['aspect_ratios'][0]);
            }
            $res = '';
            if (isset($t['resolution']) && (is_string($t['resolution']) || is_scalar($t['resolution']))) {
                $res = trim((string)$t['resolution']);
            }
            $parts = [$name, $typeLabel];
            if ($ar !== '') $parts[] = $ar;
            if ($res !== '') $parts[] = $res;
            $this->pushSmLog($pushCallback, implode(' ', $parts));
            $i++;
        }
        if ($i === 1) {
            $this->pushSmLog($pushCallback, '（无任务）');
        }
    }

    protected function pushSmLogChosenParams(callable $pushCallback, array $preparedTasks): void
    {
        $i = 1;
        foreach ($preparedTasks as $t) {
            if (!is_array($t)) continue;
            $name = isset($t['image_name']) ? trim((string)$t['image_name']) : '';
            if ($name === '') $name = '任务' . $i;
            $model = isset($t['model_identity']) ? trim((string)$t['model_identity']) : '';
            $ar = isset($t['aspect_ratio']) ? trim((string)$t['aspect_ratio']) : '';
            $res = isset($t['resolution']) ? trim((string)$t['resolution']) : '';
            $parts = [$name];
            if ($model !== '') $parts[] = $model;
            if ($ar !== '') $parts[] = $ar;
            if ($res !== '') $parts[] = $res;
            $this->pushSmLog($pushCallback, implode(' ', $parts));
            $i++;
        }
        if ($i === 1) {
            $this->pushSmLog($pushCallback, '（无参数）');
        }
    }

    protected function formatPlanSummary(array $plan): string
    {
        $typeLabelMap = [
            'TEXT_TO_IMAGE' => '文生图',
            'EDIT_SINGLE_IMAGE' => '编辑单图',
            'REFERENCE_TO_IMAGE' => '参考图生图',
            'FUSION_MULTI_IMAGES' => '多图融合',
            'TEXT_TO_VIDEO' => '文生视频',
            'TEXT_ONLY' => '纯文本',
        ];

        if ($this->isListArray($plan)) {
            $total = count($plan);
            if ($total === 0) return '任务数：0';
            $needConfirm = false;
            $examples = [];
            for ($i = 0; $i < min(3, $total); $i++) {
                $t = $plan[$i] ?? null;
                if (!is_array($t)) continue;
                $tt = isset($t['task_type']) ? trim((string)$t['task_type']) : '';
                $name = isset($t['image_name']) && (is_string($t['image_name']) || is_scalar($t['image_name'])) ? trim((string)$t['image_name']) : '';
                $ar = '';
                if (isset($t['aspect_ratios'])) {
                    $ars = $t['aspect_ratios'];
                    if (is_array($ars) && count($ars) > 0) $ar = trim((string)$ars[0]);
                    elseif (is_string($ars) || is_scalar($ars)) $ar = trim((string)$ars);
                }
                $res = '';
                if (isset($t['resolution'])) {
                    $res = $this->normalizeResolutionFromAny($t['resolution']);
                }
                $typeLabel = $typeLabelMap[$tt] ?? ($tt !== '' ? $tt : '未知');
                $itemParts = [];
                if ($name !== '') $itemParts[] = $name;
                $itemParts[] = $typeLabel;
                if ($ar !== '') $itemParts[] = $ar;
                if ($res !== '') $itemParts[] = $res;
                $examples[] = implode(' ', $itemParts);
                if (!empty($t['need_confirm'])) $needConfirm = true;
            }
            $parts = ['任务数：' . $total];
            if (!empty($examples)) $parts[] = '示例：' . implode(' / ', $examples) . ($total > 3 ? ' …' : '');
            if ($needConfirm) $parts[] = '需要澄清';
            return implode('；', $parts);
        }

        $taskType = isset($plan['task_type']) ? (string)$plan['task_type'] : '';
        $imgCount = isset($plan['image_count']) ? (int)$plan['image_count'] : 1;
        $ratios = [];
        if (isset($plan['aspect_ratios']) && is_array($plan['aspect_ratios'])) {
            foreach ($plan['aspect_ratios'] as $r) {
                $v = is_string($r) ? trim($r) : (is_scalar($r) ? trim((string)$r) : '');
                if ($v !== '') $ratios[] = $v;
            }
        }
        $modelHint = isset($plan['model_hint']) && is_string($plan['model_hint']) ? trim($plan['model_hint']) : '';
        $needConfirm = !empty($plan['need_confirm']);
        $typeLabel = $typeLabelMap[$taskType] ?? ($taskType !== '' ? $taskType : '未知');

        $parts = ["任务：{$typeLabel}"];
        if ($taskType !== 'TEXT_TO_VIDEO' && $taskType !== 'TEXT_ONLY') $parts[] = "数量：{$imgCount}";
        if (!empty($ratios)) $parts[] = '比例：' . implode(' / ', array_slice($ratios, 0, 3));
        if ($modelHint !== '') $parts[] = "模型提示：{$modelHint}";
        if ($needConfirm) $parts[] = '需要澄清';
        return implode('；', $parts);
    }

    protected function formatChosenParamsSummary(string $taskType, string $modelIdentity, string $aspectRatio, string $resolution, int $imageCount, array $referenceImages): string
    {
        $parts = [];
        if (trim($modelIdentity) !== '') $parts[] = '模型：' . $modelIdentity;
        if (trim($aspectRatio) !== '') $parts[] = '比例：' . $aspectRatio;
        if (trim($resolution) !== '') $parts[] = '分辨率：' . $resolution;
        if ($taskType !== 'TEXT_TO_VIDEO') $parts[] = '数量：' . max(1, $imageCount);
        if (is_array($referenceImages) && count($referenceImages) > 0) $parts[] = '参考图：' . count($referenceImages) . ' 张';
        return implode('；', $parts) ?: '已选择默认参数';
    }

    protected function formatChosenParamsSummaryMulti(array $preparedTasks, array $referenceImages): string
    {
        $total = count($preparedTasks);
        if ($total === 0) return '已选择默认参数';
        $parts = ['任务数：' . $total];
        for ($i = 0; $i < min(3, $total); $i++) {
            $t = $preparedTasks[$i] ?? null;
            if (!is_array($t)) continue;
            $name = isset($t['image_name']) ? trim((string)$t['image_name']) : '';
            $model = isset($t['model_identity']) ? trim((string)$t['model_identity']) : '';
            $ar = isset($t['aspect_ratio']) ? trim((string)$t['aspect_ratio']) : '';
            $res = isset($t['resolution']) ? trim((string)$t['resolution']) : '';
            $itemParts = [($i + 1) . ')'];
            if ($name !== '') $itemParts[] = $name;
            if ($model !== '') $itemParts[] = $model;
            if ($ar !== '') $itemParts[] = $ar;
            if ($res !== '') $itemParts[] = $res;
            $parts[] = implode(' ', $itemParts);
        }
        if (is_array($referenceImages) && count($referenceImages) > 0) $parts[] = '参考图：' . count($referenceImages) . ' 张';
        return implode('；', $parts);
    }

    protected function formatPromptSummary(string $prompt): string
    {
        $p = trim($prompt);
        if ($p === '') return '提示词为空，使用用户原始描述';
        $len = mb_strlen($p, 'UTF-8');
        $preview = mb_substr($p, 0, 60, 'UTF-8');
        $preview = preg_replace('/\s+/u', ' ', (string)$preview);
        if ($len > 60) $preview .= '…';
        return "长度：{$len}；预览：{$preview}";
    }

    protected function extractLastUserText(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i] ?? null;
            if (is_array($m) && ($m['role'] ?? null) === 'user') {
                $c = $m['content'] ?? '';
                if (is_string($c)) return trim($c);
                if (is_scalar($c)) return trim((string)$c);
                if (is_array($c)) return trim(json_encode($c, JSON_UNESCAPED_UNICODE));
                return '';
            }
        }
        return '';
    }

    protected function buildSmContext(array $messages, array $options, string $currentUserText, int $maxItems = 10, int $keepHead = 0): string
    {
        $history = $this->loadConversationHistory($messages, $options);
        if (!is_array($history) || count($history) === 0) return '';

        $items = [];
        foreach ($history as $m) {
            if (!is_array($m)) continue;
            $role = isset($m['role']) && is_string($m['role']) ? $m['role'] : '';
            if ($role !== 'user' && $role !== 'assistant') continue;

            $raw = $m['content'] ?? '';
            $text = $this->normalizeContextText($raw);
            if ($text === '') continue;

            $toolResult = isset($m['toolResult']) && is_array($m['toolResult']) ? $m['toolResult'] : null;
            $items[] = [
                'role' => $role,
                'raw' => is_string($raw) ? $raw : (is_scalar($raw) ? (string)$raw : ''),
                'text' => $text,
                'toolResult' => $toolResult,
            ];
        }

        if (count($items) === 0) return '';

        $cur = trim($currentUserText);
        if ($cur !== '') {
            $last = $items[count($items) - 1];
            if (($last['role'] ?? '') === 'user' && trim((string)($last['raw'] ?? '')) === $cur) {
                array_pop($items);
            }
        }

        if (count($items) === 0) return '';

        $maxItems = max(1, $maxItems);
        $keepHead = max(0, $keepHead);
        if ($keepHead > 0 && count($items) > $maxItems) {
            $keepHead = min($keepHead, count($items));
            $tailStart = max($keepHead, count($items) - $maxItems);
            $slice = array_merge(array_slice($items, 0, $keepHead), array_slice($items, $tailStart));
        } else {
            $slice = array_slice($items, max(0, count($items) - $maxItems));
        }
        $lines = [];
        foreach ($slice as $it) {
            $label = ($it['role'] ?? '') === 'user' ? '用户' : '助手';
            $line = $label . '：' . ($it['text'] ?? '');
            $tr = $this->summarizeToolResult($it['toolResult'] ?? null);
            if ($tr !== '') {
                $line .= '；工具结果：' . $tr;
            }
            $lines[] = $line;
        }

        return trim(implode("\n", $lines));
    }

    protected function loadConversationHistory(array $messages, array $options): array
    {
        $conversationUid = isset($options['conversation_id']) ? (string)$options['conversation_id'] : '';
        if ($conversationUid !== '') {
            try {
                $row = Db::table('conversations')->where('conversation_id', $conversationUid)->find();
                if ($row && isset($row['messages_json']) && is_string($row['messages_json']) && trim($row['messages_json']) !== '') {
                    $decoded = json_decode($row['messages_json'], true);
                    if (is_array($decoded) && count($decoded) > 0) return $decoded;
                }
            } catch (\Throwable $e) {
            }
        }
        return $messages;
    }

    protected function normalizeContextText($content, int $maxLen = 220): string
    {
        if (is_string($content)) {
            $t = $content;
        } elseif (is_scalar($content)) {
            $t = (string)$content;
        } elseif (is_array($content)) {
            $t = json_encode($content, JSON_UNESCAPED_UNICODE);
        } else {
            $t = '';
        }

        $t = trim((string)$t);
        if ($t === '') return '';
        $t = preg_replace('/\s+/u', ' ', $t);
        $t = trim((string)$t);
        if ($t === '') return '';
        if (mb_strlen($t, 'UTF-8') > $maxLen) {
            $t = mb_substr($t, 0, $maxLen, 'UTF-8') . '…';
        }
        return $t;
    }

    protected function summarizeToolResult($toolResult): string
    {
        if (!is_array($toolResult) || count($toolResult) === 0) return '';

        $picked = [];
        foreach (['task_type', 'image_name', 'resolution', 'status', 'image_url', 'video_url'] as $k) {
            if (!isset($toolResult[$k])) continue;
            $v = $toolResult[$k];
            if (is_string($v)) {
                $vv = trim($v);
                if ($vv !== '') $picked[$k] = $vv;
            } elseif (is_scalar($v)) {
                $picked[$k] = $v;
            }
        }

        if (count($picked) === 0) return '';
        $json = json_encode($picked, JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '';
    }

    protected function pickPrompt(?array $row, string $key, string $fallback): string
    {
        if ($row && isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') {
            return (string)$row[$key];
        }
        return $fallback;
    }

    protected function buildSmLlmOptions(array $options): array
    {
        $modelIdentity = '';
        if (isset($options['model_identity'])) $modelIdentity = (string)$options['model_identity'];
        elseif (isset($options['model'])) $modelIdentity = (string)$options['model'];
        $modelIdentity = trim($modelIdentity);
        if ($modelIdentity === '') return [];
        $opt = ['model_identity' => $modelIdentity];
        $idLower = mb_strtolower($modelIdentity, 'UTF-8');
        if (strpos($idLower, 'doubao-seed-1-6') !== false) {
            $opt['thinking'] = ['type' => 'disabled'];
        }
        return $opt;
    }

    protected function callJsonStage($userId, string $systemPrompt, string $userText, float $temperature, array $extraOptions = []): ?array
    {
        $msgs = [
            ['role' => 'user', 'content' => $userText],
        ];
        $opt = array_merge($extraOptions, [
            'stream' => false,
            'temperature' => $temperature,
            'system_prompt' => $systemPrompt,
            'response_format' => ['type' => 'json_object'],
        ]);
        Log::channel('agent_llm')->write('agent_llm_request:' . $this->trimLogValue([
            'stage' => 'json',
            'user_id' => $userId,
            'messages' => $msgs,
            'options' => $opt,
        ]), 'info');
        $res = $this->llmService->chat($msgs, $opt, $userId);
        Log::channel('agent_llm')->write('agent_llm_response:' . $this->trimLogValue([
            'stage' => 'json',
            'user_id' => $userId,
            'response' => $res,
        ]), 'info');
        $content = $this->extractContentFromResponse($res);
        return $this->extractJsonObject($content);
    }

    protected function callTextStage($userId, string $systemPrompt, string $userText, float $temperature, array $extraOptions = []): string
    {
        $msgs = [
            ['role' => 'user', 'content' => $userText],
        ];
        $opt = array_merge($extraOptions, ['stream' => false, 'temperature' => $temperature, 'system_prompt' => $systemPrompt]);
        Log::channel('agent_llm')->write('agent_llm_request:' . $this->trimLogValue([
            'stage' => 'text',
            'user_id' => $userId,
            'messages' => $msgs,
            'options' => $opt,
        ]), 'info');
        $res = $this->llmService->chat($msgs, $opt, $userId);
        Log::channel('agent_llm')->write('agent_llm_response:' . $this->trimLogValue([
            'stage' => 'text',
            'user_id' => $userId,
            'response' => $res,
        ]), 'info');
        $content = $this->extractContentFromResponse($res);
        return trim($content);
    }

    protected function extractContentFromResponse($res): string
    {
        if (is_string($res)) return $res;
        if (!is_array($res)) return '';

        // Handle LlmService::formatResponse output (Unified format)
        if (isset($res['content']) && is_string($res['content'])) {
            return $res['content'];
        }

        // Handle raw OpenAI format (fallback)
        if (isset($res['choices'][0]['message']['content']) && is_string($res['choices'][0]['message']['content'])) {
            return $res['choices'][0]['message']['content'];
        }

        return '';
    }

    protected function trimLogValue($value, int $maxLen = 4000): string
    {
        if (is_string($value)) {
            $text = $value;
        } else {
            $text = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if (!is_string($text)) $text = '';
        if (mb_strlen($text, 'UTF-8') > $maxLen) {
            $text = mb_substr($text, 0, $maxLen, 'UTF-8') . '…';
        }
        return $text;
    }

    protected function extractJsonObject(string $text): ?array
    {
        return $this->extractJsonValue($text);
    }

    protected function extractJsonValue(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') return null;

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/iu', $text, $m)) {
            $text = trim((string)$m[1]);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) return $decoded;

        $startArr = strpos($text, '[');
        $endArr = strrpos($text, ']');
        if ($startArr !== false && $endArr !== false && $endArr > $startArr) {
            $sub = substr($text, $startArr, $endArr - $startArr + 1);
            $decoded2 = json_decode($sub, true);
            if (is_array($decoded2)) return $decoded2;
        }

        $startObj = strpos($text, '{');
        $endObj = strrpos($text, '}');
        if ($startObj !== false && $endObj !== false && $endObj > $startObj) {
            $sub = substr($text, $startObj, $endObj - $startObj + 1);
            $decoded3 = json_decode($sub, true);
            if (is_array($decoded3)) return $decoded3;
        }

        return null;
    }

    protected function fallbackIntent(string $userText, array $options): array
    {
        $hasUrl = preg_match('/https?:\/\/\S+/i', $userText) ? true : false;
        $isBatch = preg_match('/(\d+\s*张|\d+\s*个|batch|多张)/iu', $userText) ? true : false;
        $intent = 'TEXT_ONLY';
        if (preg_match('/视频|video|动图|sora|runway/iu', $userText)) {
            $intent = 'VIDEO_GENERATION';
        } elseif ($hasUrl || (!empty($options['reference_images']) && is_array($options['reference_images']) && count($options['reference_images']) > 0)) {
            if (preg_match('/融合|合成|拼在一起|mix|fusion/iu', $userText)) $intent = 'IMAGE_FUSION';
            elseif (preg_match('/编辑|修改|去掉|加上|替换|抠图|背景|擦除|修复/iu', $userText)) $intent = 'IMAGE_EDIT';
            else $intent = 'IMAGE_REFERENCE';
        } elseif (preg_match('/图|图片|海报|封面|logo|插画|摄影|照片|生图/iu', $userText)) {
            $intent = 'IMAGE_GENERATION';
        }
        return ['intent' => $intent, 'has_image_url' => $hasUrl, 'is_batch_request' => $isBatch];
    }

    protected function selectModelIdentity(string $taskType, $modelHint, $selectedModel): string
    {
        $hint = is_string($modelHint) ? $modelHint : '';
        $sel = is_string($selectedModel) ? $selectedModel : '';
        $pick = $sel !== '' ? $sel : $hint;
        $p = mb_strtolower($pick, 'UTF-8');

        if ($taskType === 'TEXT_TO_VIDEO') {
            if (strpos($p, 'test') !== false) return 'test-video';
            return 'sora2';
        }

        if (strpos($p, 'seedream4.0') !== false || (strpos($p, 'seedream4') !== false && strpos($p, '4.5') === false)) return 'seedream4.0';
        if (strpos($p, 'seedream3.0') !== false || strpos($p, 'seedream3') !== false) return 'seedream3.0';
        if (strpos($p, '4.5') !== false || strpos($p, 'seedream4.5') !== false) return 'seedream4.5';
        if (strpos($p, 'nano') !== false && strpos($p, 'banana') !== false) return 'duomi-nano-banana-pro';
        if (strpos($p, 'antigravity') !== false || strpos($p, 'gemini') !== false) return 'gemini-3-pro-image-antigravity';
        if (strpos($p, 'gpt-image-2-all') !== false) return 'gpt-image-2-all-apiyi';
        if (strpos($p, 'gpt-image-2') !== false) return 'gpt-image-2-apiyi';

        return 'seedream4.5';
    }

    protected function pickAspectRatio($aspectRatios, string $userText): string
    {
        if (is_array($aspectRatios) && count($aspectRatios) > 0) {
            $v = (string)$aspectRatios[0];
            if ($v !== '') return $v;
        }
        if (preg_match('/(16\s*:\s*9|9\s*:\s*16|1\s*:\s*1|4\s*:\s*3|3\s*:\s*4|3\s*:\s*2|2\s*:\s*3|21\s*:\s*9|9\s*:\s*21)/', $userText, $m)) {
            return str_replace(' ', '', $m[1]);
        }
        return '';
    }

    protected function pickResolution(string $userText, string $aspectRatio): string
    {
        $minPixels = $this->requestedMinPixelsFromText($userText);

        if (preg_match('/(\d{3,5})\s*[x×]\s*(\d{3,5})/u', $userText, $m)) {
            $r = $this->normalizeResolution($m[1] . 'x' . $m[2]);
            if ($minPixels > 0) $r = $this->ensureMinPixelsAlign64($r, $minPixels);
            return $r;
        }
        $map = [
            '16:9' => '1280x720',
            '9:16' => '720x1280',
            '16:5' => '1280x400',
            '5:16' => '400x1280',
            '4:3' => '1024x768',
            '3:4' => '768x1024',
            '3:2' => '1024x683',
            '2:3' => '683x1024',
            '21:9' => '1280x548',
            '9:21' => '548x1280',
            '1:1' => '1024x1024',
        ];
        if ($aspectRatio !== '' && isset($map[$aspectRatio])) {
            $r = $map[$aspectRatio];
            if ($minPixels > 0) $r = $this->ensureMinPixelsAlign64($r, $minPixels);
            return $r;
        }
        if (preg_match('/^\s*(\d+)\s*:\s*(\d+)\s*$/u', $aspectRatio, $m)) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            if ($a > 0 && $b > 0) {
                $ratio = $a / $b;
                $longSide = abs($a - $b) <= 1 ? 1024 : 1280;
                $minSide = 256;
                if ($ratio >= 1) {
                    $w = $longSide;
                    $h = (int)max($minSide, round($w / $ratio));
                } else {
                    $h = $longSide;
                    $w = (int)max($minSide, round($h * $ratio));
                }
                $r = $w . 'x' . $h;
                if ($minPixels > 0) $r = $this->ensureMinPixelsAlign64($r, $minPixels);
                return $r;
            }
        }
        $r = '1024x1024';
        if ($minPixels > 0) $r = $this->ensureMinPixelsAlign64($r, $minPixels);
        return $r;
    }

    protected function normalizeResolution($value): string
    {
        if (!is_string($value) && !is_scalar($value)) return '';
        $s = trim((string)$value);
        if ($s === '') return '';

        // Allow 4k/2k/1k (case-insensitive)
        if (preg_match('/^(4k|2k|1k)$/i', $s)) {
            return strtolower($s);
        }

        if (!preg_match('/^(\d{2,5})\s*[x×]\s*(\d{2,5})$/u', $s, $m)) return '';
        $w = (int)$m[1];
        $h = (int)$m[2];
        if ($w <= 0 || $h <= 0) return '';
        return $w . 'x' . $h;
    }

    protected function pickImageCount($imageCount, string $userText): int
    {
        $c = 1;
        if (is_numeric($imageCount)) $c = (int)$imageCount;
        if ($c <= 0) $c = 1;
        if (preg_match('/(\d+)\s*张/u', $userText, $m)) $c = (int)$m[1];
        if ($c < 1) $c = 1;
        if ($c > 8) $c = 8;
        return $c;
    }

    protected function isListArray(array $arr): bool
    {
        $i = 0;
        foreach ($arr as $k => $v) {
            if ($k !== $i) return false;
            $i++;
        }
        return true;
    }

    protected function normalizeResolutionFromAny($value): string
    {
        if (is_array($value) && count($value) > 0) {
            $first = $value[0];
            return $this->normalizeResolution($first);
        }
        return $this->normalizeResolution($value);
    }

    protected function normalizeImageListFromAny($value): array
    {
        $vals = [];
        if (is_array($value)) {
            $vals = $value;
        } elseif (is_string($value) || is_scalar($value)) {
            $vals = [(string)$value];
        }

        $list = [];
        foreach ($vals as $v) {
            if (!is_string($v) && !is_scalar($v)) continue;
            $s = trim((string)$v);
            if ($s === '') continue;
            $list[] = $s;
            if (count($list) >= 14) break;
        }
        return array_values($list);
    }

    protected function defaultTaskTypeFromIntent(array $intent, array $referenceImages): string
    {
        $i = isset($intent['intent']) ? (string)$intent['intent'] : '';
        if ($i === 'VIDEO_GENERATION') return 'TEXT_TO_VIDEO';
        if ($i === 'TEXT_ONLY') return 'TEXT_ONLY';
        return $this->mapImageTaskType($intent, $referenceImages);
    }

    protected function normalizePlanTasks(array $plan, array $intent, string $userText, array $options, array $referenceImages): array
    {
        $defaultType = $this->defaultTaskTypeFromIntent($intent, $referenceImages);
        $items = [];
        if (isset($plan['tasks']) && is_array($plan['tasks'])) {
            $items = $plan['tasks'];
        } elseif ($this->isListArray($plan)) {
            $items = $plan;
        } else {
            $items = [$plan];
        }
        if (count($items) === 0) {
            $items = [[
                'task_type' => $defaultType,
                'image_name' => null,
                'model_hint' => null,
                'image_list' => [],
                'aspect_ratios' => [],
                'resolution' => null,
                'need_confirm' => false,
            ]];
        }
        $tasks = [];
        foreach ($items as $it) {
            $t = $this->normalizePlanTaskItem($it, $defaultType);
            if (!empty($t)) $tasks[] = $t;
            if (count($tasks) >= 8) break;
        }
        if (count($tasks) === 0) {
            $tasks[] = $this->normalizePlanTaskItem([], $defaultType);
        }
        return $tasks;
    }

    protected function normalizePlanTaskItem($item, string $defaultType): array
    {
        $it = is_array($item) ? $item : [];
        $allowed = [
            'TEXT_TO_IMAGE' => 1,
            'EDIT_SINGLE_IMAGE' => 1,
            'REFERENCE_TO_IMAGE' => 1,
            'FUSION_MULTI_IMAGES' => 1,
            'TEXT_TO_VIDEO' => 1,
            'TEXT_ONLY' => 1,
        ];

        $taskType = isset($it['task_type']) && (is_string($it['task_type']) || is_scalar($it['task_type'])) ? trim((string)$it['task_type']) : '';
        if ($taskType === '' || !isset($allowed[$taskType])) $taskType = $defaultType;

        $imageName = '';
        if (isset($it['image_name']) && (is_string($it['image_name']) || is_scalar($it['image_name']))) {
            $imageName = trim((string)$it['image_name']);
        }

        $modelHint = null;
        if (isset($it['model_hint']) && (is_string($it['model_hint']) || is_scalar($it['model_hint']))) {
            $mh = trim((string)$it['model_hint']);
            if ($mh !== '') $modelHint = $mh;
        }

        $imageList = [];
        if (isset($it['image_list'])) {
            $imageList = $this->normalizeImageListFromAny($it['image_list']);
        }

        $aspectRatios = [];
        if (isset($it['aspect_ratios'])) {
            $ars = $it['aspect_ratios'];
            if (is_array($ars)) {
                foreach ($ars as $r) {
                    $v = is_string($r) ? trim($r) : (is_scalar($r) ? trim((string)$r) : '');
                    if ($v !== '') $aspectRatios[] = $v;
                }
            } elseif (is_string($ars) || is_scalar($ars)) {
                $v = trim((string)$ars);
                if ($v !== '') $aspectRatios[] = $v;
            }
        }

        $resolution = '';
        if (isset($it['image_size'])) {
            $resolution = $this->normalizeResolutionFromAny($it['image_size']);
        }
        if ($resolution === '' && isset($it['size'])) {
            $resolution = $this->normalizeResolutionFromAny($it['size']);
        }
        if ($resolution === '' && isset($it['resolution'])) {
            $resolution = $this->normalizeResolutionFromAny($it['resolution']);
        }

        $needConfirm = !empty($it['need_confirm']);

        return [
            'task_type' => $taskType,
            'image_name' => $imageName !== '' ? $imageName : null,
            'model_hint' => $modelHint,
            'image_list' => $imageList,
            'aspect_ratios' => $aspectRatios,
            'resolution' => $resolution !== '' ? $resolution : null,
            'need_confirm' => $needConfirm,
        ];
    }

    protected function requestedMinPixelsFromText(string $userText): int
    {
        $t = mb_strtolower($userText, 'UTF-8');
        if (preg_match('/4\s*k/u', $t)) return 8294400;
        if (preg_match('/2\s*k/u', $t)) return 3686400;
        if (preg_match('/1\s*k/u', $t)) return 2073600;
        return 0;
    }

    protected function ensureMinPixelsAlign64(string $resolution, int $minPixels): string
    {
        $r = $this->normalizeResolution($resolution);
        if ($r === '' || $minPixels <= 0) return $r;
        $parts = explode('x', $r);
        if (count($parts) !== 2) return $r;
        $w = (int)$parts[0];
        $h = (int)$parts[1];
        if ($w <= 0 || $h <= 0) return $r;

        $pixels = $w * $h;
        if ($pixels >= $minPixels) return $r;

        $scale = sqrt($minPixels / max(1, $pixels));
        $nw = (int)ceil(($w * $scale) / 64) * 64;
        $nh = (int)ceil(($h * $scale) / 64) * 64;
        if ($nw <= 0 || $nh <= 0) return $r;
        return $nw . 'x' . $nh;
    }

    protected function enforceSeedream45MinPixels(string $resolution): string
    {
        $minPixels = 3686400;
        $w = 0; $h = 0;
        if ($resolution !== '' && strpos($resolution, 'x') !== false) {
            $parts = explode('x', $resolution);
            if (count($parts) === 2) {
                $w = (int)$parts[0];
                $h = (int)$parts[1];
            }
        }
        if ($w > 0 && $h > 0) {
            $pixels = $w * $h;
            if ($pixels < $minPixels) {
                $scale = sqrt($minPixels / max(1, $pixels));
                $nw = (int)ceil(($w * $scale) / 64) * 64;
                $nh = (int)ceil(($h * $scale) / 64) * 64;
                if ($nw > 0 && $nh > 0) {
                    return $nw . 'x' . $nh;
                }
            }
        }
        return $resolution;
    }

    protected function preparePlannedTasks(array $tasks, array $intent, string $userText, array $options, array $referenceImages): array
    {
        $requestedMinPixels = $this->requestedMinPixelsFromText($userText);
        $prepared = [];
        foreach ($tasks as $t) {
            if (!is_array($t)) continue;
            $taskType = isset($t['task_type']) ? trim((string)$t['task_type']) : '';
            if ($taskType === '') $taskType = $this->defaultTaskTypeFromIntent($intent, $referenceImages);

            $taskReferenceImages = [];
            if (isset($t['image_list'])) {
                $taskReferenceImages = $this->normalizeImageListFromAny($t['image_list']);
            }
            if (count($taskReferenceImages) === 0) {
                $taskReferenceImages = $referenceImages;
            }

            $modelHint = $t['model_hint'] ?? null;
            $modelIdentity = $this->selectModelIdentity($taskType, $modelHint, $options['image_model'] ?? null);

            $aspectRatio = $this->pickAspectRatio($t['aspect_ratios'] ?? [], $userText);

            $plannedResolution = isset($t['resolution']) && (is_string($t['resolution']) || is_scalar($t['resolution'])) ? trim((string)$t['resolution']) : '';
            $resolution = $plannedResolution !== '' ? $this->normalizeResolution($plannedResolution) : '';
            if ($resolution === '') $resolution = $this->pickResolution($userText, $aspectRatio);

            if ($taskType !== 'TEXT_TO_VIDEO' && $requestedMinPixels > 0) {
                $resolution = $this->ensureMinPixelsAlign64($resolution, $requestedMinPixels);
            }

            if ($taskType !== 'TEXT_TO_VIDEO') {
                $idLower = mb_strtolower(trim($modelIdentity), 'UTF-8');
                if ($idLower === 'seedream4.5') {
                    $resolution = $this->enforceSeedream45MinPixels($resolution);
                }
            }

            $imageName = '';
            if (isset($t['image_name']) && (is_string($t['image_name']) || is_scalar($t['image_name']))) {
                $imageName = trim((string)$t['image_name']);
            }
            if ($imageName === '') $imageName = $taskType === 'TEXT_TO_VIDEO' ? '生成视频' : '生成图像';

            $prepared[] = [
                'task_type' => $taskType,
                'image_name' => $imageName,
                'model_hint' => $modelHint,
                'model_identity' => $modelIdentity,
                'aspect_ratio' => $aspectRatio,
                'resolution' => $resolution,
                'reference_images' => $taskReferenceImages,
            ];
            if (count($prepared) >= 8) break;
        }
        if (count($prepared) === 0) {
            $defaultType = $this->defaultTaskTypeFromIntent($intent, $referenceImages);
            $prepared[] = [
                'task_type' => $defaultType,
                'image_name' => $defaultType === 'TEXT_TO_VIDEO' ? '生成视频' : '生成图像',
                'model_hint' => null,
                'model_identity' => $this->selectModelIdentity($defaultType, null, $options['image_model'] ?? null),
                'aspect_ratio' => '',
                'resolution' => $defaultType === 'TEXT_TO_VIDEO' ? '' : '1024x1024',
                'reference_images' => $referenceImages,
            ];
        }
        return $prepared;
    }

    protected function sanitizeForPromptBuild(string $userText): string
    {
        $t = preg_replace('/https?:\/\/\S+/i', '', $userText);
        $t = preg_replace('/（[^）]*参考图[^\)]*）/u', '', (string)$t);
        $t = preg_replace('/\s+/u', ' ', (string)$t);
        return trim((string)$t);
    }

    protected function applyUserInputTemplate(string $prompt, string $userText): string
    {
        if (strpos($prompt, '{{USER_INPUT}}') !== false) return str_replace('{{USER_INPUT}}', $userText, $prompt);
        return $userText;
    }

    protected function mapImageTaskType(array $intent, array $referenceImages): string
    {
        $i = isset($intent['intent']) ? (string)$intent['intent'] : '';
        $n = count($referenceImages);
        if ($i === 'IMAGE_EDIT') return 'EDIT_SINGLE_IMAGE';
        if ($i === 'IMAGE_FUSION' || $n > 1) return 'FUSION_MULTI_IMAGES';
        if ($i === 'IMAGE_REFERENCE' || $n > 0) return 'REFERENCE_TO_IMAGE';
        return 'TEXT_TO_IMAGE';
    }

    protected function defaultS0IntentPrompt(): string
    {
        return "你是文章创作对话的意图与参数解析器。\n你必须只输出严格 JSON（顶层为 object），不得输出任何额外文本。\n\n目标：根据用户输入判断是否要开始写文章；如果要写，则抽取并校验参数。\n\n输出 JSON Schema：\n{\n  \"intent\": \"WRITE_ARTICLE\"|\"OTHER\",\n  \"topic\": \"\",\n  \"genre\": \"\",\n  \"word_count\": 0,\n  \"requirements\": \"\",\n  \"style_id\": \"\",\n  \"style_profile_id\": 0,\n  \"missing\": [\"topic\"|\"genre\"],\n  \"ask\": \"\",\n  \"reply\": \"\"\n}\n\n规则：\n- topic：主题/要点，必须能直接用于写作。\n- genre：体裁/文章类型，如“科普/观点/测评/教程/新闻稿/小红书笔记/公众号推文/知乎回答/演讲稿/短视频口播稿”。\n- word_count：字数目标（整数，可选）。用户未明确要求字数时必须填 0，且不要把 word_count 放入 missing。\n- requirements：除上述字段外的其他要求（受众、语气、结构、要点、禁用项、是否需要标题/小节/案例/数据等）。\n- style_id/style_profile_id：如用户明确指定作者风格才填；不确定则留空/0。\n- 若 intent=OTHER：missing 为空；reply 给出简短引导。\n- 若 intent=WRITE_ARTICLE：仅把缺失的 topic/genre 写入 missing，并在 ask 中用一句话询问缺失信息；reply 可为空。\n- 如果用户输入包含明确的 topic/genre/word_count，则相应字段应被抽取；但 word_count 不应出现在 missing。\n- 始终输出 JSON。";
    }

    protected function normalizeArticleS0(?array $parsed, array $options): array
    {
        $out = is_array($parsed) ? $parsed : [];
        $intent = strtoupper(trim((string)($out['intent'] ?? '')));
        if ($intent !== 'WRITE_ARTICLE') $intent = 'OTHER';

        $topic = trim((string)($out['topic'] ?? ''));
        $genre = trim((string)($out['genre'] ?? ''));
        $requirements = trim((string)($out['requirements'] ?? ''));
        $wordCountRaw = $out['word_count'] ?? 0;
        $wordCount = is_numeric($wordCountRaw) ? (int)$wordCountRaw : 0;
        if ($wordCount <= 0) $wordCount = 0;

        $styleId = trim((string)($out['style_id'] ?? ($options['style_id'] ?? '')));
        $styleProfileIdRaw = $out['style_profile_id'] ?? ($options['style_profile_id'] ?? 0);
        $styleProfileId = is_numeric($styleProfileIdRaw) ? (int)$styleProfileIdRaw : 0;
        if ($styleProfileId < 0) $styleProfileId = 0;

        $missing = [];
        $missingIn = $out['missing'] ?? [];
        if (is_array($missingIn)) {
            foreach ($missingIn as $m) {
                $k = trim((string)$m);
                if ($k !== '') $missing[] = $k;
            }
        }
        $missing = array_values(array_unique($missing));

        if ($intent === 'WRITE_ARTICLE') {
            if ($topic === '' && !in_array('topic', $missing, true)) $missing[] = 'topic';
            if ($genre === '' && !in_array('genre', $missing, true)) $missing[] = 'genre';
        } else {
            $missing = [];
        }

        if (in_array('word_count', $missing, true)) {
            $missing = array_values(array_filter($missing, function($v) { return $v !== 'word_count'; }));
        }

        $ask = (string)($out['ask'] ?? '');
        $reply = (string)($out['reply'] ?? '');

        if ($styleId !== '' && $styleProfileId <= 0) {
            $styleId = '';
        }

        return [
            'intent' => $intent,
            'topic' => $topic,
            'genre' => $genre,
            'word_count' => $wordCount > 0 ? $wordCount : 0,
            'requirements' => $requirements,
            'style_id' => $styleId,
            'style_profile_id' => $styleProfileId > 0 ? $styleProfileId : 0,
            'missing' => $missing,
            'ask' => $ask,
            'reply' => $reply,
        ];
    }

    protected function buildArticleMissingQuestion(array $missing, array $norm): string
    {
        $need = array_values(array_unique(array_filter(array_map(function($v) { return trim((string)$v); }, $missing))));
        $parts = [];
        if (in_array('topic', $need, true)) $parts[] = '主题/要点';
        if (in_array('genre', $need, true)) $parts[] = '体裁';
        if (in_array('word_count', $need, true)) $parts[] = '字数';
        $tail = $parts ? ('请补充' . implode('、', $parts) . '。') : '请补充写作信息。';
        $hint = '例如：主题=…；体裁=科普/教程/公众号推文…；字数=1200（可选）。';
        $known = [];
        if (!empty($norm['topic'])) $known[] = '已识别主题：' . (string)$norm['topic'];
        if (!empty($norm['genre'])) $known[] = '已识别体裁：' . (string)$norm['genre'];
        if (!empty($norm['word_count'])) $known[] = '已识别字数：' . (string)$norm['word_count'];
        $knownText = $known ? ("\n" . implode("\n", $known)) : '';
        return $tail . "\n" . $hint . $knownText;
    }

    protected function ensureWritingTables(): void
    {
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `writing_tasks` (
                `task_id` VARCHAR(32) NOT NULL,
                `tenant_id` INT NULL,
                `user_id` INT NULL,
                `style_id` VARCHAR(50) NULL,
                `style_profile_id` INT NULL,
                `status` VARCHAR(40) NULL,
                `stage` VARCHAR(60) NULL,
                `model` VARCHAR(40) NULL,
                `prompt_json` LONGTEXT NULL,
                `error_message` VARCHAR(1024) NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                `started_at` DATETIME NULL,
                `finished_at` DATETIME NULL,
                PRIMARY KEY (`task_id`),
                INDEX `idx_user` (`user_id`),
                INDEX `idx_tenant` (`tenant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (\Throwable $e) {}

        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `writing_artifacts` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `task_id` VARCHAR(32) NOT NULL,
                `type` VARCHAR(50) NOT NULL,
                `version` INT NOT NULL DEFAULT 1,
                `payload_json` LONGTEXT NULL,
                `text` LONGTEXT NULL,
                `created_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_task_type` (`task_id`, `type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (\Throwable $e) {}
    }

    protected function ensureResourcesTableForWriting(): void
    {
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `resources` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `resource_id` CHAR(32) NULL,
                `tenant_id` INT NULL,
                `user_id` INT NULL,
                `style_id` VARCHAR(50) NULL,
                `type` VARCHAR(20) NULL COMMENT 'note, link, file, group',
                `title` VARCHAR(255) NULL,
                `url` VARCHAR(1024) NULL,
                `content` LONGTEXT NULL,
                `task_id` VARCHAR(32) NULL,
                `task_status_json` LONGTEXT NULL,
                `style_profile_id` INT NULL,
                `topic` VARCHAR(255) NULL,
                `genre` VARCHAR(255) NULL,
                `word_count` INT NULL,
                `status` VARCHAR(20) DEFAULT 'normal' COMMENT 'normal, hidden, deleted',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_user_style` (`user_id`, `style_id`),
                UNIQUE KEY `uniq_resource_id` (`resource_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (\Throwable $e) {}
    }

    protected function mapWritingModelIdentity(?string $model): ?string
    {
        $m = trim((string)$model);
        if ($m === '') return null;
        if ($m === 'qwen3max') return 'qwen';
        if ($m === 'glm4.7') return 'glm';
        return $m;
    }

    protected function createWritingTaskFromAgent(int $userId, array $norm, array $options): array
    {
        $this->ensureWritingTables();
        $this->ensureResourcesTableForWriting();

        $tenantId = null;
        if (isset($options['tenant_id'])) $tenantId = $options['tenant_id'];
        if (isset($options['tenantId'])) $tenantId = $options['tenantId'];
        if ($tenantId !== null && $tenantId !== '') {
            $tenantId = is_numeric($tenantId) ? (int)$tenantId : null;
        } else {
            $tenantId = null;
        }

        $providedWorkResourceId = null;
        if (isset($options['work_resource_id'])) $providedWorkResourceId = (string)$options['work_resource_id'];
        if (isset($options['workResourceId'])) $providedWorkResourceId = (string)$options['workResourceId'];
        $providedWorkResourceId = trim((string)$providedWorkResourceId);
        if ($providedWorkResourceId === '' || !preg_match('/^[a-f0-9]{32}$/i', $providedWorkResourceId)) {
            $providedWorkResourceId = null;
        }

        $editorIsEmpty = null;
        if (array_key_exists('editor_is_empty', $options)) $editorIsEmpty = $options['editor_is_empty'];
        if (array_key_exists('editorIsEmpty', $options)) $editorIsEmpty = $options['editorIsEmpty'];
        if ($editorIsEmpty === null && array_key_exists('editor_has_content', $options)) $editorIsEmpty = !(bool)$options['editor_has_content'];
        if ($editorIsEmpty === null && array_key_exists('editorHasContent', $options)) $editorIsEmpty = !(bool)$options['editorHasContent'];
        if (is_string($editorIsEmpty)) {
            $s = strtolower(trim($editorIsEmpty));
            if ($s === 'true' || $s === '1' || $s === 'yes') $editorIsEmpty = true;
            elseif ($s === 'false' || $s === '0' || $s === 'no') $editorIsEmpty = false;
        }
        if (!is_bool($editorIsEmpty)) $editorIsEmpty = null;

        $topic = trim((string)($norm['topic'] ?? ''));
        $genre = trim((string)($norm['genre'] ?? ''));
        $wordCountRaw = $norm['word_count'] ?? 0;
        $wordCount = is_numeric($wordCountRaw) ? (int)$wordCountRaw : 0;
        if ($wordCount <= 0) $wordCount = 0;
        $requirements = trim((string)($norm['requirements'] ?? ''));

        $styleId = trim((string)($norm['style_id'] ?? ''));
        $styleProfileId = (int)($norm['style_profile_id'] ?? 0);
        if ($styleId !== '' && $styleProfileId <= 0) {
            $styleId = '';
            $styleProfileId = 0;
        }

        $model = $this->mapWritingModelIdentity(isset($options['model_identity']) ? (string)$options['model_identity'] : (isset($options['model']) ? (string)$options['model'] : ''));

        if ($topic === '' || $genre === '') {
            throw new \Exception('Missing required params');
        }

        $taskId = bin2hex(random_bytes(16));
        $taskKey = 'writing_task:' . $taskId;
        $reuseWorkResource = $providedWorkResourceId !== null && $editorIsEmpty === true;
        $workResourceId = $reuseWorkResource ? $providedWorkResourceId : bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');

        $workTitle = mb_substr($topic, 0, 30, 'UTF-8');
        if ($reuseWorkResource) {
            $existing = null;
            try {
                $existing = Db::table('resources')
                    ->where('resource_id', $workResourceId)
                    ->where('user_id', $userId)
                    ->order('id', 'desc')
                    ->find();
            } catch (\Throwable $e) {
                $existing = null;
            }

            $existingTenantId = $existing ? ($existing['tenant_id'] ?? null) : null;
            if ($existing && $existingTenantId !== null && $tenantId !== null && (int)$existingTenantId !== (int)$tenantId) {
                $reuseWorkResource = false;
                $workResourceId = bin2hex(random_bytes(16));
                $existing = null;
            }
            if ($existing && isset($existing['type']) && (string)$existing['type'] !== 'work') {
                $reuseWorkResource = false;
                $workResourceId = bin2hex(random_bytes(16));
                $existing = null;
            }

            if ($reuseWorkResource && $existing) {
                $existingTitle = trim((string)($existing['title'] ?? ''));
                $updates = [
                    'updated_at' => $now,
                    'style_id' => $styleId !== '' ? $styleId : null,
                    'type' => 'work',
                    'url' => null,
                    'content' => $requirements,
                    'task_id' => $taskId,
                    'task_status_json' => json_encode(['status' => 'QUEUED', 'stage' => 'QUEUED', 'progress' => '0'], JSON_UNESCAPED_UNICODE),
                    'style_profile_id' => $styleProfileId > 0 ? $styleProfileId : null,
                    'topic' => $topic,
                    'genre' => $genre,
                        'word_count' => $wordCount > 0 ? $wordCount : null,
                    'status' => 'normal',
                ];
                if ($tenantId !== null) $updates['tenant_id'] = $tenantId;
                if ($existingTitle === '' || $existingTitle === '未命名作品') {
                    $updates['title'] = $workTitle !== '' ? $workTitle : '未命名作品';
                }
                Db::table('resources')->where('resource_id', $workResourceId)->update($updates);
            } else {
                Db::table('resources')->insert([
                    'resource_id' => $workResourceId,
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'style_id' => $styleId !== '' ? $styleId : null,
                    'type' => 'work',
                    'title' => $workTitle !== '' ? $workTitle : '未命名作品',
                    'url' => null,
                    'content' => $requirements,
                    'task_id' => $taskId,
                    'task_status_json' => json_encode(['status' => 'QUEUED', 'stage' => 'QUEUED', 'progress' => '0'], JSON_UNESCAPED_UNICODE),
                    'style_profile_id' => $styleProfileId > 0 ? $styleProfileId : null,
                    'topic' => $topic,
                    'genre' => $genre,
                    'word_count' => $wordCount > 0 ? $wordCount : null,
                    'status' => 'normal',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } else {
            Db::table('resources')->insert([
                'resource_id' => $workResourceId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'style_id' => $styleId !== '' ? $styleId : null,
                'type' => 'work',
                'title' => $workTitle !== '' ? $workTitle : '未命名作品',
                'url' => null,
                'content' => $requirements,
                'task_id' => $taskId,
                'task_status_json' => json_encode(['status' => 'QUEUED', 'stage' => 'QUEUED', 'progress' => '0'], JSON_UNESCAPED_UNICODE),
                'style_profile_id' => $styleProfileId > 0 ? $styleProfileId : null,
                'topic' => $topic,
                'genre' => $genre,
                'word_count' => $wordCount > 0 ? $wordCount : null,
                'status' => 'normal',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $statusArr = [
            'status' => 'QUEUED',
            'stage' => 'QUEUED',
            'progress' => '0',
            'tenant_id' => $tenantId !== null ? (string)$tenantId : '',
            'user_id' => (string)$userId,
            'style_id' => $styleId,
            'style_profile_id' => (string)$styleProfileId,
            'work_resource_id' => $workResourceId,
            'updated_at' => (string)time(),
        ];
        Cache::set($taskKey, $statusArr, 3600);

        Db::table('writing_tasks')->insert([
            'task_id' => $taskId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'style_id' => $styleId,
            'style_profile_id' => $styleProfileId,
            'status' => 'QUEUED',
            'stage' => 'QUEUED',
            'model' => $model ?: '',
            'prompt_json' => json_encode([
                'topic' => $topic,
                'genre' => $genre,
                'word_count' => $wordCount,
                'requirements' => $requirements,
                'style_id' => $styleId,
                'style_profile_id' => $styleProfileId,
                'model' => $model,
                'work_resource_id' => $workResourceId,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
            'started_at' => null,
            'finished_at' => null,
        ]);

        $payload = [
            'task_id' => $taskId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'title' => '',
            'topic' => $topic,
            'genre' => $genre,
            'word_count' => $wordCount,
            'requirements' => $requirements,
            'style_id' => $styleId,
            'style_profile_id' => $styleProfileId,
            'model_identity' => $model,
            'work_resource_id' => $workResourceId,
        ];
        Queue::push('app\\job\\WritingTaskJob', $payload, 'default');

        return ['task_id' => $taskId, 'work_resource_id' => $workResourceId];
    }

    protected function defaultS1IntentPrompt(): string
    {
        return "你是一个意图分类器。\n只输出 JSON，不要解释、不加多余字段。\n\n根据用户输入，判断其主要意图类型：\n\n可选 intent：\n- IMAGE_GENERATION        （文生图）\n- IMAGE_EDIT              （编辑已有图片）\n- IMAGE_REFERENCE         （参考图生成）\n- IMAGE_FUSION            （多图融合）\n- VIDEO_GENERATION        （生成视频）\n- USER_INFO               （查询用户信息）\n- TEXT_ONLY               （仅文本回答）\n\n额外判断字段：\n- has_image_url：true / false\n- is_batch_request：true / false\n\n输出格式示例：\n{\n  \"intent\": \"IMAGE_GENERATION\",\n  \"has_image_url\": false,\n  \"is_batch_request\": false\n}";
    }

    protected function defaultS2PlanPrompt(): string
    {
        return "你是一个任务规划器。\n只输出 JSON，不要解释。\n\n输入包括：\n- 用户原始输入\n- 已识别的 intent\n\n请输出 JSON object，顶层必须包含 tasks 数组字段，tasks 里每个元素是一条任务。\n\n字段说明：\n- task_type：\n  TEXT_TO_IMAGE | EDIT_SINGLE_IMAGE | REFERENCE_TO_IMAGE |\n  FUSION_MULTI_IMAGES | TEXT_TO_VIDEO | TEXT_ONLY\n\n- image_name：作品名称（用简短中文概括，不要太长；不明确则为 null）\n- model_hint：用户是否明确提到模型（如 seedream4.5、nano banana pro），没有则为 null\n- image_list：参考图 URL 数组（<= 14），没有则为空数组\n- aspect_ratios：如提到 16:9、9:16、1:1，数组形式\n- resolution：如提到 1280x720，数组形式（例如 [\"1280x720\"]），不明确则为空数组\n- need_confirm：是否属于批量或模糊需求，需要用户确认\n\n输出示例：\n{\n  \"tasks\": [\n    {\n      \"image_name\": \"新年海报\",\n      \"task_type\": \"TEXT_TO_IMAGE\",\n      \"model_hint\": \"seedream4.5\",\n      \"image_list\": [],\n      \"aspect_ratios\": [\"16:9\"],\n      \"resolution\": [\"1280x800\"],\n      \"need_confirm\": false\n    },\n    {\n      \"image_name\": \"新年海报-竖版\",\n      \"task_type\": \"TEXT_TO_IMAGE\",\n      \"model_hint\": \"seedream4.5\",\n      \"image_list\": [],\n      \"aspect_ratios\": [\"9:16\"],\n      \"resolution\": [\"800x1280\"],\n      \"need_confirm\": false\n    }\n  ]\n}";
    }

    protected function defaultS3ImagePrompt(): string
    {
        return "你是一个专业的图像生成提示词工程师。\n\n你的任务是：\n- 如果输入是中文，先翻译成英文\n- 在不改变原意的前提下，补充必要细节\n- 输出适合图像生成模型使用的英文 prompt\n\n优化维度（按需使用）：\n- Subject：外观、颜色、材质、表情、动作\n- Environment：场景、背景、时间、天气\n- Lighting：光线类型、方向、氛围\n- Style：艺术风格、绘画技法、渲染方式\n- Quality：构图、清晰度、细节等级\n\n规则：\n- 根据目标生成模型的特性决定是否在prompt中包含比例和分辨率：如果模型API通过独立参数（如size、aspect_ratio字段）传递这些信息，则不要在prompt中写入；如果模型需要在prompt文本中内嵌这些参数（如--ar格式），则按要求格式保留\n- 不要输出模型名称\n- 不要输出 JSON\n- 只输出最终英文 prompt，一整段文本\n\n用户输入：\n{{USER_INPUT}}";
    }

    protected function defaultS3VideoPrompt(): string
    {
        return "你是一个专业的视频生成提示词工程师。\n\n你的任务是：\n- 如果输入是中文，先翻译成英文\n- 在不改变原意的前提下，补充必要细节（动作、镜头、节奏、光线、风格）\n- 输出适合视频生成模型使用的英文 prompt\n\n规则：\n- 根据目标生成模型的特性决定是否在prompt中包含比例和分辨率：如果模型API通过独立参数（如size、aspect_ratio、resolution字段）传递这些信息，则不要在prompt中写入；如果模型需要在prompt文本中内嵌这些参数，则按要求格式保留\n- 不要输出模型名称、平台名称\n- 不要输出 JSON\n- 只输出最终英文 prompt，一整段文本\n\n用户输入：\n{{USER_INPUT}}";
    }

    protected function defaultS3TextPrompt(): string
    {
        return "你是一个中文写作与问答助手。\n\n要求：\n- 直接回答用户问题\n- 不调用工具，不提及工具\n- 语言简洁清晰\n\n用户输入：\n{{USER_INPUT}}";
    }

    protected function defaultS5ResultPrompt(): string
    {
        return "你是结果展示助手。\n\n根据工具返回结果，用简洁中文总结生成内容。\n\n要求：\n- 列出名称、类型、分辨率\n- 不输出链接\n- 不解释技术细节\n- 语言简洁、易读\n\n输入：\n{{USER_INPUT}}";
    }

    protected function prepareSystemPrompt(array $messages, array $options)
    {
        $systemPrompt = null;
        
        // 1. Check if explicit system prompt is provided
        if (!empty($options['system_prompt']) && is_string($options['system_prompt'])) {
            $systemPrompt = $options['system_prompt'];
        } 
        // 2. Check for specific system key (from Llm controller logic)
        elseif (!empty($options['use_system_key'])) {
            try {
                $key = $options['use_system_key'];
                $row = Db::table('system_prompts')->order('id', 'desc')->find();
                if ($row && isset($row[$key]) && is_string($row[$key]) && $row[$key] !== '') {
                    $systemPrompt = $row[$key];
                } else {
                    $row = Db::table('system_prompts')->where('key', $key)->find();
                    if (!$row) {
                        $row = Db::table('system_prompts')->where('name', $key)->find();
                    }
                    if ($row) {
                        $candidates = ['content', 'prompt', 'text', 'value', 'message', 'system_prompt', 'prompt_text'];
                        foreach ($candidates as $k) {
                            if (isset($row[$k]) && is_string($row[$k]) && $row[$k] !== '') {
                                $systemPrompt = $row[$k];
                                break;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error("Failed to load system prompt for key {$options['use_system_key']}: " . $e->getMessage());
            }
        }
        
        // 3. Fallback to default assistant system prompt if still null
        if (!$systemPrompt) {
            try {
                $row = Db::table('system_prompts')
                    ->whereNotNull('llm_assistant_system_prompt')
                    ->where('llm_assistant_system_prompt', '<>', '')
                    ->order('id','desc')
                    ->find();
                if ($row) {
                    $systemPrompt = $row['llm_assistant_system_prompt'];
                }
            } catch (\Throwable $e) {}
        }

        if ($systemPrompt) {
            $isPolishEnabled = $options['is_polish_enabled'] ?? false;
            $placeholder = '[zhanwei]润色prompt占位[/zhanwei]';
            // Cancel original logic for raw mode (false) as we now handle it via prompt prefix in frontend
            $replacement = $isPolishEnabled ? '- 将用户的提示词润色为更适合图片生成、视频生成模型的自然语言提示词，如精准描述场景、风格、元素、光效、构图、清晰度等，提高图片的质量。' : '';
            $systemPrompt = str_replace($placeholder, $replacement, $systemPrompt);
            
            // Append mandatory tool instruction to ANY system prompt to ensure tools are used
            $systemPrompt .= "\n\nIMPORTANT: If the user asks to generate or edit an image/video, you MUST use the provided tools. Do NOT just describe the action without calling the tool.";
            $systemPrompt .= "\n\nIMPORTANT: If the user asks to search the web, get up-to-date info, or requests sources, you MUST use the web_search tool when available.";
            $systemPrompt .= "\n\nIMPORTANT: Do NOT output your internal thinking process, reasoning steps, or analysis in the response. Only provide the final answer to the user. If you must output reasoning, enclose it strictly within <think>...</think> tags.";
        } else {
            $systemPrompt = "You are a helpful assistant. IMPORTANT: If the user asks to generate or edit an image/video, you MUST use the provided tools. Do NOT just describe the action without calling the tool.";
            $systemPrompt .= "\n\nIMPORTANT: If the user asks to search the web, get up-to-date info, or requests sources, you MUST use the web_search tool when available.";
            $systemPrompt .= "\n\nIMPORTANT: Do NOT output your internal thinking process, reasoning steps, or analysis in the response. Only provide the final answer to the user. If you must output reasoning, enclose it strictly within <think>...</think> tags.";
        }

        // 4. Combine all system messages into one and place it at the start
        $combinedSystemContent = $systemPrompt;
        $newMessages = [];
        
        foreach ($messages as $msg) {
            if (isset($msg['role']) && $msg['role'] === 'system') {
                if (isset($msg['content']) && is_string($msg['content']) && $msg['content'] !== '') {
                    if (strpos($combinedSystemContent, $msg['content']) === false) {
                        $combinedSystemContent .= "\n\n" . $msg['content'];
                    }
                }
            } else {
                $newMessages[] = $msg;
            }
        }
        
        array_unshift($newMessages, ['role' => 'system', 'content' => $combinedSystemContent]);
        
        return $newMessages;
    }

    protected function detectHallucination(array $messages)
    {
        $lastUserContent = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (isset($messages[$i]['role']) && $messages[$i]['role'] === 'user') {
                $lastUserContent = isset($messages[$i]['content']) ? $messages[$i]['content'] : '';
                break;
            }
        }
        if (preg_match('/(没有|失败|fail|didn\'t|not|retry|again|看不到|where|骗人|没做|重新|没调用)/iu', $lastUserContent)) {
             $hint = "SYSTEM ALERT: The user indicates the previous generation/edit failed or was not visible. This usually means you described the action but DID NOT call the tool function. You MUST call the tool function now to perform the requested action. Do not just apologize, CALL THE TOOL.";
             array_push($messages, [ 'role' => 'system', 'content' => $hint ]);
        }
        return $messages;
    }

    protected function persistUserMessage(array $messages, array $options)
    {
        $conversationUid = isset($options['conversation_id']) ? (string)$options['conversation_id'] : '';
        if ($conversationUid !== '') {
            try {
                $lastUser = null;
                for ($i = count($messages) - 1; $i >= 0; $i--) {
                    if (isset($messages[$i]['role']) && $messages[$i]['role'] === 'user') { $lastUser = $messages[$i]; break; }
                }
                if ($lastUser && isset($lastUser['content']) && is_string($lastUser['content'])) {
                    $row = Db::table('conversations')->where('conversation_id', $conversationUid)->find();
                    if ($row) {
                        $msgs = [];
                        try { $msgs = isset($row['messages_json']) && $row['messages_json'] ? json_decode($row['messages_json'], true) ?: [] : []; } catch (\Throwable $e) { $msgs = []; }
                        $timestamp = time();
                        $msgId = isset($lastUser['id']) ? $lastUser['id'] : (int)(microtime(true) * 1000);
                        $msgs[] = [ 'id' => $msgId, 'role' => 'user', 'content' => $lastUser['content'], 'timestamp' => $timestamp ];
                        
                        $updateData = [
                            'messages_json' => json_encode($msgs, JSON_UNESCAPED_UNICODE),
                            'updated_at' => date('Y-m-d H:i:s')
                        ];
                        
                        $currentTitle = isset($row['title']) ? $row['title'] : '';
                        if ($currentTitle === '新的对话' || $currentTitle === 'New Conversation') {
                            $rawPrefix = '直接将以下内容作为prompt传入工具不要做任何解析修改：';
                            $titleContent = $lastUser['content'];
                            if (strpos($titleContent, $rawPrefix) === 0) {
                                $titleContent = substr($titleContent, strlen($rawPrefix));
                            }
                            $newTitle = mb_substr($titleContent, 0, 10, 'UTF-8');
                            if ($newTitle) $updateData['title'] = $newTitle;
                        }
                        Db::table('conversations')->where('conversation_id', $conversationUid)->update($updateData);
                    }
                }
            } catch (\Throwable $e) {}
        }
    }

    protected function persistAssistantMessage(array $options, $content)
    {
        $conversationUid = isset($options['conversation_id']) ? (string)$options['conversation_id'] : '';
        if ($conversationUid !== '') {
            try {
                $row = Db::table('conversations')->where('conversation_id', $conversationUid)->find();
                if ($row) {
                    $msgs = [];
                    try { $msgs = isset($row['messages_json']) && $row['messages_json'] ? json_decode($row['messages_json'], true) ?: [] : []; } catch (\Throwable $e) { $msgs = []; }
                    $msgs[] = [
                        'id' => (int)(microtime(true) * 1000),
                        'role' => 'assistant',
                        'content' => $content,
                        'timestamp' => time()
                    ];
                    Db::table('conversations')->where('conversation_id', $conversationUid)->update([
                        'messages_json' => json_encode($msgs, JSON_UNESCAPED_UNICODE),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
            } catch (\Throwable $e) {}
        }
    }

    protected function getTools()
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_user_info',
                    'description' => 'Get current user information including profile and points',
                    'parameters' => [ 'type' => 'object', 'properties' => [], 'required' => [] ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'web_search',
                    'description' => 'Search the web for up-to-date information and return sources.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [ 'type' => 'string', 'description' => 'Search query (<= 70 chars recommended)' ],
                            'search_engine' => [ 'type' => 'string', 'enum' => ['search_std','search_pro','search_pro_sogou','search_pro_quark'] ],
                            'count' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ],
                            'search_domain_filter' => [ 'type' => 'string', 'description' => 'Optional domain whitelist like www.example.com' ],
                            'search_recency_filter' => [ 'type' => 'string', 'enum' => ['oneDay','oneWeek','oneMonth','oneYear','noLimit'] ],
                            'content_size' => [ 'type' => 'string', 'enum' => ['medium','high'] ],
                            'search_intent' => [ 'type' => 'boolean' ]
                        ],
                        'required' => ['query']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_video_sora_duomi',
                    'description' => 'Generate video via Sora (Duomi)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'prompt' => [ 'type' => 'string', 'description' => 'English detailed description' ],
                            'image_name' => [ 'type' => 'string', 'description' => 'Video title' ],
                            'aspect_ratio' => [ 'type' => 'string', 'enum' => ['16:9', '9:16'] ],
                            'resolution' => [ 'type' => 'string', 'description' => 'Video resolution (e.g. 720P, 1080P)' ],
                            'quality' => [ 'type' => 'string', 'description' => 'Video quality' ],
                            'duration' => [ 'type' => 'integer', 'enum' => [10, 15, 25] ],
                            'image_urls' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'maxItems' => 1, 'description' => 'Reference image URL (max 1)' ],
                            'task_type' => [ 'type' => 'string', 'enum' => ['TEXT_TO_VIDEO'] ],
                        ],
                        'required' => ['prompt','task_type','aspect_ratio','duration']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_image_seedream_v4_5',
                    'description' => 'Generate image via Seedream v4_5. Use this tool for generating NEW images OR EDITING existing images.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'prompt' => [ 'type' => 'string', 'description' => '中文详细描述' ],
                            'image_name' => [ 'type' => 'string', 'description' => '中文或英文名称皆可' ],
                            'task_type' => [ 'type' => 'string', 'enum' => ['TEXT_TO_IMAGE','REFERENCE_TO_IMAGE','EDIT_SINGLE_IMAGE','FUSION_MULTI_IMAGES','OTHER'] ],
                            'image_list' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => '参考图 URL 数组（<= 14）' ],
                            'size' => [ 'type' => 'string', 'description' => '自定义分辨率字符串；支持“widthxheight”（如“1024x1536”）或“widthheight”（如“10241536”）' ],
                            'upscale_factor' => [ 'type' => 'integer', 'enum' => [1, 2, 4], 'description' => '1|2|4；缺省为 1；用于提高清晰度' ],
                            'optimize_prompt_mode' => [ 'type' => 'string', 'enum' => ['standard'], 'description' => '仅支持“standard”，可省略' ],
                        ],
                        'required' => ['prompt','image_name','task_type','size']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_image_nanobananapro_duomi',
                    'description' => 'Generate image via Nano Banana Pro (Duomi). Use this tool for generating NEW images OR EDITING existing images.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'prompt' => [ 'type' => 'string' ],
                            'image_list' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'List of image URLs to use as reference or to be edited.' ],
                            'image_name' => [ 'type' => 'string' ],
                            'aspect_ratio' => [ 'type' => 'string', 'enum' => ['auto','1:1','2:3','3:2','3:4','4:3','4:5','5:4','9:16','16:9','21:9','9:21'] ],
                            'size' => [ 'type' => 'string' ],
                            'task_type' => [ 'type' => 'string', 'enum' => ['TEXT_TO_IMAGE','IMAGE_TO_IMAGE','IMAGE_VARIATION','IMAGE_EXTEND','IMAGE_EDIT','IMAGE_MATTING'] ],
                        ],
                        'required' => ['prompt','task_type','aspect_ratio']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_image_nanobananapro_antigravity',
                    'description' => 'Generate image via Nano Banana Pro (Antigravity local).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'prompt' => [ 'type' => 'string' ],
                            'image_name' => [ 'type' => 'string' ],
                            'task_type' => [ 'type' => 'string', 'enum' => ['TEXT_TO_IMAGE','EDIT_SINGLE_IMAGE','REFERENCE_TO_IMAGE','FUSION_MULTI_IMAGES','OTHER'] ],
                            'image_list' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Reference image URLs (<=14). Required when EDIT_SINGLE_IMAGE.' ],
                            'aspect_ratio' => [ 'type' => 'string', 'enum' => ['auto','1:1','2:3','3:2','3:4','4:3','4:5','5:4','9:16','16:9','21:9'] ],
                            'size' => [ 'type' => 'string', 'description' => 'Image size like 1024x1024. Optional.' ],
                        ],
                        'required' => ['prompt','image_name','task_type']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_image_seedream_v4_0',
                    'description' => 'Generate image via Seedream v4_0. Use this tool for generating NEW images OR EDITING existing images.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'prompt' => [ 'type' => 'string' ],
                            'image_list' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'List of image URLs to use as reference or to be edited.' ],
                            'image_name' => [ 'type' => 'string' ],
                            'aspect_ratio' => [ 'type' => 'string', 'enum' => ['auto','1:1','2:3','3:2','3:4','4:3','4:5','5:4','9:16','16:9','21:9','9:21'] ],
                            'size' => [ 'type' => 'string' ],
                            'task_type' => [ 'type' => 'string', 'enum' => ['TEXT_TO_IMAGE','IMAGE_TO_IMAGE','IMAGE_VARIATION','IMAGE_EXTEND','IMAGE_EDIT','IMAGE_MATTING'] ],
                        ],
                        'required' => ['prompt','task_type','aspect_ratio']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_image_seedream_v3_0',
                    'description' => 'Generate image via Seedream v3_0. Use this tool for generating NEW images OR EDITING existing images.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'prompt' => [ 'type' => 'string' ],
                            'image_list' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'List of image URLs to use as reference or to be edited.' ],
                            'image_name' => [ 'type' => 'string' ],
                            'aspect_ratio' => [ 'type' => 'string', 'enum' => ['auto','1:1','2:3','3:2','3:4','4:3','4:5','5:4','9:16','16:9','21:9','9:21'] ],
                            'size' => [ 'type' => 'string' ],
                            'task_type' => [ 'type' => 'string', 'enum' => ['TEXT_TO_IMAGE','IMAGE_TO_IMAGE','IMAGE_VARIATION','IMAGE_EXTEND','IMAGE_EDIT','IMAGE_MATTING'] ],
                        ],
                        'required' => ['prompt','task_type','aspect_ratio']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_video_test',
                    'description' => 'Generate video (Test Tool).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'prompt' => [ 'type' => 'string', 'description' => 'English detailed description' ],
                            'image_name' => [ 'type' => 'string', 'description' => 'Video title' ],
                            'aspect_ratio' => [ 'type' => 'string', 'enum' => ['16:9', '9:16'] ],
                            'resolution' => [ 'type' => 'string', 'description' => 'Video resolution (e.g. 720P, 1080P)' ],
                            'quality' => [ 'type' => 'string', 'description' => 'Video quality' ],
                            'duration' => [ 'type' => 'integer', 'enum' => [10, 15, 25] ],
                            'image_urls' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'maxItems' => 1, 'description' => 'Reference image URL (max 1)' ],
                            'task_type' => [ 'type' => 'string', 'enum' => ['TEXT_TO_VIDEO'] ],
                        ],
                        'required' => ['prompt','image_name','task_type','aspect_ratio','duration','resolution']
                    ]
                ]
            ]
        ];
    }

    // Helper to get Redis connection
    protected function getRedisConnection()
    {
        $cfg = config('queue.connections.redis');
        $redis = class_exists('Redis') ? new \Redis() : null;
        if ($redis) {
            try {
                $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
                if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
                if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
                return $redis;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    protected function normalizeZhipuApiKey(string $apiKey, int $expireSeconds = 300): string
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') return '';
        if (strpos($apiKey, '.') === false) return $apiKey;
        if (strpos($apiKey, 'sk-') === 0) return $apiKey;

        $parts = explode('.', $apiKey);
        if (count($parts) !== 2) return $apiKey;
        $id = $parts[0];
        $secret = $parts[1];
        if ($id === '' || $secret === '') return $apiKey;

        $now = time() * 1000;
        $payload = [
            'api_key' => $id,
            'exp' => $now + ($expireSeconds * 1000),
            'timestamp' => $now,
        ];
        $header = [
            'alg' => 'HS256',
            'sign_type' => 'SIGN',
        ];

        $base64UrlEncode = function ($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        $base64UrlHeader = $base64UrlEncode(json_encode($header));
        $base64UrlPayload = $base64UrlEncode(json_encode($payload));
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = $base64UrlEncode($signature);
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    protected function getWebSearchConfig(): array
    {
        $cfg = [];
        try {
            $raw = Db::table('system_configs')->where('category', 'web_search')->value('config');
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $cfg = $decoded;
            }
        } catch (\Throwable $e) {}

        if (!$cfg) {
            try {
                $raw2 = Db::table('system_configs')->where('category', 'default_models')->value('config');
                if ($raw2) {
                    $decoded2 = json_decode($raw2, true);
                    if (is_array($decoded2) && isset($decoded2['web_search']) && is_array($decoded2['web_search'])) {
                        $cfg = $decoded2['web_search'];
                    }
                }
            } catch (\Throwable $e) {}
        }

        return $cfg ?: [];
    }

    protected function webSearchSearxng(string $query, int $count): array
    {
        $q = trim($query);
        if ($q === '') return [];
        $count = max(1, min(50, (int)$count));

        $cfg = $this->getWebSearchConfig();
        $endpoint = isset($cfg['searxng_endpoint']) ? trim((string)$cfg['searxng_endpoint']) : '';
        if ($endpoint === '') return [];

        $apiKey = isset($cfg['searxng_api_key']) ? trim((string)$cfg['searxng_api_key']) : '';
        $engines = isset($cfg['searxng_engines']) ? trim((string)$cfg['searxng_engines']) : '';

        $client = new \GuzzleHttp\Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify' => false,
        ]);

        $headers = [
            'Accept' => 'application/json',
        ];
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
            $headers['X-API-Key'] = $apiKey;
        }

        try {
            $queryParams = [
                'q' => $q,
                'format' => 'json',
                'pageno' => 1,
                'safesearch' => 0,
            ];
            if ($engines !== '') $queryParams['engines'] = $engines;

            $resp = $client->get($endpoint, [
                'headers' => $headers,
                'query' => $queryParams,
            ]);
            $json = json_decode($resp->getBody()->getContents(), true);
            if (!is_array($json)) return [];

            $results = $json['results'] ?? null;
            if (!is_array($results) || !$results) return [];

            $items = [];
            foreach ($results as $r) {
                if (!is_array($r)) continue;
                $url = trim((string)($r['url'] ?? $r['link'] ?? ''));
                $title = trim((string)($r['title'] ?? ''));
                if ($url === '' || !preg_match('/^https?:\/\//i', $url)) continue;
                $items[] = ['title' => $title, 'link' => $url];
                if (count($items) >= $count) break;
            }
            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function extractVolcWebSearchResults($json): array
    {
        if (!is_array($json)) return [];

        $items = [];
        $push = function ($url, $title = '') use (&$items) {
            $u = trim((string)$url);
            if ($u === '') return;
            if (!preg_match('/^https?:\/\//i', $u)) return;
            $items[] = ['title' => trim((string)$title), 'link' => $u];
        };

        $walk = function ($node) use (&$walk, $push) {
            if (!is_array($node)) return;
            if (isset($node['results']) && is_array($node['results'])) {
                foreach ($node['results'] as $r) {
                    if (!is_array($r)) continue;
                    $url = $r['url'] ?? $r['link'] ?? $r['source_url'] ?? $r['sourceUrl'] ?? '';
                    $title = $r['title'] ?? $r['name'] ?? $r['source_title'] ?? $r['sourceTitle'] ?? '';
                    if ($url) $push($url, $title);
                }
            }
            foreach ($node as $v) {
                if (is_array($v)) $walk($v);
            }
        };

        $walk($json);

        if (!$items) {
            $texts = [];
            $collectText = function ($node) use (&$collectText, &$texts) {
                if (count($texts) >= 10) return;
                if (is_array($node)) {
                    if (isset($node['text']) && is_string($node['text'])) {
                        $t = trim($node['text']);
                        if ($t !== '') $texts[] = $t;
                    }
                    foreach ($node as $v) $collectText($v);
                }
            };
            $collectText($json);
            $raw = implode("\n", $texts);
            if ($raw !== '') {
                if (preg_match_all('/https?:\/\/[^\s\)\]\}<>"]+/i', $raw, $m)) {
                    foreach ($m[0] as $u) $push($u, '');
                }
            }
        }

        $dedup = [];
        $out = [];
        foreach ($items as $it) {
            $u = (string)($it['link'] ?? '');
            if ($u === '') continue;
            $k = strtolower($u);
            if (isset($dedup[$k])) continue;
            $dedup[$k] = 1;
            $out[] = $it;
            if (count($out) >= 50) break;
        }
        return $out;
    }

    protected function webSearchVolcengine(string $query, int $count): array
    {
        $q = trim($query);
        if ($q === '') return [];
        $count = max(1, min(50, (int)$count));

        $cfg = $this->getWebSearchConfig();
        $apiKey = isset($cfg['volc_api_key']) ? trim((string)$cfg['volc_api_key']) : '';
        if ($apiKey === '') return [];

        $endpoint = isset($cfg['volc_endpoint']) ? trim((string)$cfg['volc_endpoint']) : '';
        if ($endpoint === '') $endpoint = 'https://ark.cn-beijing.volces.com/api/v3/responses';

        $model = isset($cfg['volc_model']) ? trim((string)$cfg['volc_model']) : '';
        if ($model === '') return [];

        $client = new \GuzzleHttp\Client([
            'timeout' => 45,
            'connect_timeout' => 10,
            'verify' => false,
        ]);

        $body = [
            'model' => $model,
            'stream' => false,
            'tools' => [
                ['type' => 'web_search']
            ],
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $q]
                    ]
                ]
            ]
        ];

        try {
            $resp = $client->post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ],
                'json' => $body,
            ]);
            $json = json_decode($resp->getBody()->getContents(), true);
            if (!is_array($json)) return [];

            $items = $this->extractVolcWebSearchResults($json);
            if (!$items) return [];
            return array_slice($items, 0, $count);
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function runWebSearch(int $userId, array $args): array
    {
        $cfg = $this->getWebSearchConfig();
        $provider = trim((string)($cfg['search_provider_toggle'] ?? 'zhipu'));
        if ($provider === '' || $provider === 'current') $provider = 'zhipu';

        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'Missing query'];
        }

        $count = (int)($args['count'] ?? ($cfg['count'] ?? 10));
        $count = max(1, min(50, $count));

        if ($provider === 'searxng') {
            $items = $this->webSearchSearxng($query, (int)($args['count'] ?? ($cfg['searxng_result_count'] ?? $count)));
            return ['provider' => 'searxng', 'search_result' => $items];
        }
        if ($provider === 'volcengine') {
            $items = $this->webSearchVolcengine($query, $count);
            return ['provider' => 'volcengine', 'search_result' => $items];
        }

        $apiKey = isset($cfg['api_key']) ? trim((string)$cfg['api_key']) : '';
        $endpoint = isset($cfg['endpoint']) ? trim((string)$cfg['endpoint']) : '';
        if ($endpoint === '') $endpoint = 'https://open.bigmodel.cn/api/paas/v4/web_search';
        if ($apiKey === '') {
            return ['error' => 'Web search is not configured (missing api_key).'];
        }
        $apiKey = $this->normalizeZhipuApiKey($apiKey);

        $body = [
            'search_query' => $query,
            'search_engine' => (string)($args['search_engine'] ?? ($cfg['search_engine'] ?? 'search_std')),
            'search_intent' => (bool)($args['search_intent'] ?? ($cfg['search_intent'] ?? false)),
            'count' => $count,
            'search_recency_filter' => (string)($args['search_recency_filter'] ?? ($cfg['search_recency_filter'] ?? 'noLimit')),
            'content_size' => (string)($args['content_size'] ?? ($cfg['content_size'] ?? 'medium')),
            'user_id' => 'user_' . $userId,
        ];

        $domain = trim((string)($args['search_domain_filter'] ?? ($cfg['search_domain_filter'] ?? '')));
        if ($domain !== '') {
            $body['search_domain_filter'] = $domain;
        }

        $client = new \GuzzleHttp\Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify' => false,
        ]);

        try {
            $resp = $client->post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ],
                'json' => $body,
            ]);
            $json = json_decode($resp->getBody()->getContents(), true);
            if (!is_array($json)) return ['error' => 'Invalid response'];
            $json['provider'] = 'zhipu';
            return $json;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $msg = $e->getMessage();
            if ($e->hasResponse()) {
                $rawErr = (string)$e->getResponse()->getBody()->getContents();
                $parsed = json_decode($rawErr, true);
                if (is_array($parsed)) {
                    $msg = $parsed['msg'] ?? $parsed['message'] ?? $msg;
                } elseif ($rawErr !== '') {
                    $msg = $rawErr;
                }
            }
            return ['error' => 'Web search failed: ' . $msg];
        } catch (\Throwable $e) {
            return ['error' => 'Web search failed: ' . $e->getMessage()];
        }
    }

    protected function handleToolCalls($userId, array $messages, array $options, $accumulatedContent, array $accumulatedToolCalls, callable $pushCallback)
    {
        $toolResults = [];
        $pendingTasks = [];

        foreach ($accumulatedToolCalls as $idx => $tc) {
            $name = $tc['function']['name'];
            $args = json_decode($tc['function']['arguments'], true) ?: [];
            
            $pushCallback(['type' => 'tool_call', 'name' => $name, 'args' => $args]);

            try {
                if ($name === 'get_user_info') {
                    $user = \app\model\User::find($userId);
                    $toolResults[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tc['id'],
                        'content' => json_encode($user ? $user->toArray() : ['error' => 'User not found'], JSON_UNESCAPED_UNICODE)
                    ];
                } elseif ($name === 'web_search') {
                    $res = $this->runWebSearch((int)$userId, is_array($args) ? $args : []);
                    $toolResults[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tc['id'],
                        'content' => json_encode($res, JSON_UNESCAPED_UNICODE)
                    ];
                } elseif (strpos($name, 'generate_video') === 0) {
                    Log::info("AgentService: Processing video tool call. Name: {$name}");
                    $modelIdentity = '';
                    if ($name === 'generate_video_test') $modelIdentity = 'test-video';
                    elseif ($name === 'generate_video_sora_duomi') $modelIdentity = 'sora2';
                    elseif ($name === 'generate_video_runway') $modelIdentity = 'runway-gen3';
                    
                    Log::info("AgentService: Mapped {$name} to identity: {$modelIdentity}");

                    if (empty($modelIdentity)) {
                         // No matching tool found, return error
                         $toolResults[] = [
                             'role' => 'tool',
                             'tool_call_id' => $tc['id'],
                             'content' => json_encode(['error' => "Unknown video tool: $name"], JSON_UNESCAPED_UNICODE)
                         ];
                         continue;
                    }
                    
                    $prompt = $args['prompt'] ?? '';
                    $videoService = $this->videoService;
                    // Ensure conversation_id is passed in tool_meta for job to update history
                    $meta = [
                        'task_type' => $args['task_type'] ?? 'TEXT_TO_VIDEO',
                        'conversation_id' => $options['conversation_id'] ?? null
                    ];
                    $toolOptions = array_merge($args, ['model_identity' => $modelIdentity, 'tool_meta' => $meta]);
                    
                    $res = $videoService->generateAsync($prompt, $toolOptions, $userId);
                    if (isset($res['task_id'])) {
                        $pendingTasks[$res['task_id']] = ['tool_call_id' => $tc['id']];
                        $pushCallback([
                            'type' => 'tool_task',
                            'data' => [
                                'task_id' => $res['task_id'],
                                'tool_meta' => $meta,
                                'image_name' => $args['image_name'] ?? '生成视频'
                            ]
                        ]);
                    } else {
                        $toolResults[] = [
                            'role' => 'tool',
                            'tool_call_id' => $tc['id'],
                            'content' => json_encode($res, JSON_UNESCAPED_UNICODE)
                        ];
                    }
                } elseif (strpos($name, 'generate_image') === 0) {
                    // Log the tool arguments for debugging
                    Log::info("Tool Call: {$name}", ['args' => json_encode($args, JSON_UNESCAPED_UNICODE)]);

                    $modelIdentity = 'seedream4.5';
                    if ($name === 'generate_image_nanobananapro_duomi') $modelIdentity = 'duomi-nano-banana-pro';
                    elseif ($name === 'generate_image_nanobananapro_antigravity') $modelIdentity = 'gemini-3-pro-image-antigravity';
                    elseif ($name === 'generate_image_seedream_v4_0') $modelIdentity = 'seedream4.0';
                    elseif ($name === 'generate_image_seedream_v3_0') $modelIdentity = 'seedream3.0';
                    
                    $prompt = $args['prompt'] ?? '';
                    if (is_array($prompt)) {
                        $flat = [];
                        try {
                            array_walk_recursive($prompt, function($a) use (&$flat) {
                                if (is_scalar($a)) $flat[] = (string)$a;
                            });
                            $prompt = implode(' ', $flat);
                        } catch (\Throwable $e) {
                            $prompt = json_encode($prompt, JSON_UNESCAPED_UNICODE);
                        }
                    }
                    $prompt = (string)$prompt;

                    // Helper to safely get string from args
                    $safeStr = function($key, $default = '') use ($args) {
                        $val = $args[$key] ?? $default;
                        if (is_array($val)) return json_encode($val, JSON_UNESCAPED_UNICODE);
                        return (string)$val;
                    };

                    $meta = [
                        'task_type' => $safeStr('task_type', 'TEXT_TO_IMAGE'),
                        'conversation_id' => is_array($options['conversation_id'] ?? null) ? json_encode($options['conversation_id']) : ($options['conversation_id'] ?? null),
                        'image_name' => $safeStr('image_name', '生成图像')
                    ];
                    
                    // Sanitize args for toolOptions to prevent Array to string conversion in downstream services
                    $sanitizedArgs = $args;
                    foreach (['image_name', 'task_type', 'aspect_ratio', 'size', 'resolution', 'quality', 'prompt'] as $k) {
                        if (isset($sanitizedArgs[$k]) && is_array($sanitizedArgs[$k])) {
                            $sanitizedArgs[$k] = json_encode($sanitizedArgs[$k], JSON_UNESCAPED_UNICODE);
                        }
                    }
                    
                    $toolOptions = array_merge($sanitizedArgs, ['model_identity' => $modelIdentity, 'tool_meta' => $meta]);
                    
                    // Map image_list to reference_images for providers
                    if (isset($toolOptions['image_list']) && !isset($toolOptions['reference_images'])) {
                        $toolOptions['reference_images'] = $toolOptions['image_list'];
                    }

                    // Map image_size to size if size is missing
                    if (isset($toolOptions['image_size']) && !isset($toolOptions['size'])) {
                        $toolOptions['size'] = $toolOptions['image_size'];
                    }

                    // Map aspect_ratio to size ONLY for legacy seedream versions (v4.0, v3.0)
                    // v4.5 uses 'size' directly.
                    if (in_array($modelIdentity, ['seedream4.0', 'seedream3.0']) && isset($toolOptions['aspect_ratio']) && !isset($toolOptions['size'])) {
                        $ratio = $toolOptions['aspect_ratio'];
                        $sizeMap = [
                            '16:9' => '1280x720',
                            '9:16' => '720x1280',
                            '4:3'  => '1024x768',
                            '3:4'  => '768x1024',
                            '3:2'  => '1024x683',
                            '2:3'  => '683x1024',
                            '21:9' => '1280x548',
                            '9:21' => '548x1280',
                            '1:1'  => '1024x1024',
                            'auto' => '1024x1024'
                        ];
                        if (isset($sizeMap[$ratio])) {
                            $toolOptions['size'] = $sizeMap[$ratio];
                        }
                    }

                    if ($modelIdentity === 'gemini-3-pro-image-antigravity') {
                        if (!isset($toolOptions['size']) || !is_string($toolOptions['size']) || trim($toolOptions['size']) === '') {
                            $ratio = isset($toolOptions['aspect_ratio']) && is_string($toolOptions['aspect_ratio']) ? $toolOptions['aspect_ratio'] : 'auto';
                            $sizeMap = [
                                '16:9' => '1280x720',
                                '9:16' => '720x1280',
                                '4:3'  => '1216x896',
                                '3:4'  => '896x1216',
                                '1:1'  => '1024x1024',
                                'auto' => '1024x1024'
                            ];
                            if (isset($sizeMap[$ratio])) {
                                $toolOptions['size'] = $sizeMap[$ratio];
                            } else {
                                $toolOptions['size'] = '1024x1024';
                            }
                        }
                    }

                    $imageService = $this->imageService;
                    try {
                        $res = $imageService->generateAsync($prompt, $toolOptions, $userId);
                    } catch (\Throwable $e) {
                        Log::error("ImageService::generateAsync failed: " . $e->getMessage());
                        throw $e;
                    }
                    if (isset($res['task_id'])) {
                        $pendingTasks[$res['task_id']] = ['tool_call_id' => $tc['id']];
                        $pushCallback([
                            'type' => 'tool_task',
                            'data' => [
                                'task_id' => $res['task_id'],
                                'tool_meta' => $meta,
                                'image_name' => $args['image_name'] ?? '生成图像'
                            ]
                        ]);
                    } else {
                        $toolResults[] = [
                            'role' => 'tool',
                            'tool_call_id' => $tc['id'],
                            'content' => json_encode($res, JSON_UNESCAPED_UNICODE)
                        ];
                    }
                } else {
                    $toolResults[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tc['id'],
                        'content' => json_encode(['error' => "Unknown tool: {$name}"], JSON_UNESCAPED_UNICODE)
                    ];
                }
            } catch (\Throwable $e) {
                $toolResults[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tc['id'],
                    'content' => json_encode([
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString() // Optional, maybe too long
                    ], JSON_UNESCAPED_UNICODE)
                ];
            }
        }

        // Wait for pending tasks (async)
        // Modified: Do NOT wait for long-running tasks. Return 'queued' status to LLM immediately.
        // This prevents the LLM from outputting the image URL (avoiding duplicate images in frontend)
        // and ensures the response is fast, relying on WebSocket to update the task status.
        if (!empty($pendingTasks)) {
            $maxWait = 0; // Set to 0 to disable waiting
            $startWait = time();
            $redis = $this->getRedisConnection();
            
            // Only check once briefly
            while (!empty($pendingTasks) && (time() - $startWait) <= $maxWait) {
                foreach ($pendingTasks as $taskId => $taskMeta) {
                    $st = null;
                    // Check Redis/Cache instead of DB, matching Job logic
                    $keys = ['image_task:' . $taskId, 'video_task:' . $taskId];
                    
                    foreach ($keys as $key) {
                        if ($redis) {
                            try {
                                $raw = $redis->hGetAll($key);
                                if ($raw && isset($raw['status'])) $st = $raw;
                            } catch (\Throwable $e) {}
                        }
                        if (!$st) {
                            $st = Cache::get($key);
                        }
                        if ($st) break;
                    }
                    
                    if ($st && isset($st['status']) && in_array($st['status'], ['success', 'failed'])) {
                        $toolResults[] = [
                            'role' => 'tool',
                            'tool_call_id' => $taskMeta['tool_call_id'],
                            'content' => json_encode($st, JSON_UNESCAPED_UNICODE)
                        ];
                        unset($pendingTasks[$taskId]);
                    }
                }
                // If maxWait is 0, break immediately after one check
                if ($maxWait <= 0) break;
                
                if (!empty($pendingTasks)) usleep(1000000);
            }
            
            // For remaining pending tasks, return 'queued' status
            foreach ($pendingTasks as $taskId => $taskMeta) {
                $toolResults[] = [
                    'role' => 'tool',
                    'tool_call_id' => $taskMeta['tool_call_id'],
                    'content' => json_encode(['status' => 'queued', 'task_id' => $taskId, 'info' => '任务正在后台处理中，请稍侯。'], JSON_UNESCAPED_UNICODE)
                ];
            }
        }

        // Follow-up Chat
        $assistantMsgFC = [
            'role' => 'assistant',
            'content' => $accumulatedContent ?: null,
            'tool_calls' => array_values($accumulatedToolCalls)
        ];
        
        $followMessages = array_merge($messages, [$assistantMsgFC], $toolResults);
        
        // Prepare callback prompt (Append instead of Replace)
        try {
            $row = Db::table('system_prompts')->whereNotNull('tool_call_callback_prompt')->where('tool_call_callback_prompt', '<>', '')->order('id', 'desc')->find();
            if ($row) {
                $callbackPrompt = trim($row['tool_call_callback_prompt']);
                foreach ($followMessages as &$msg) {
                    if ($msg['role'] === 'system') { 
                        $msg['content'] .= "\n\n" . $callbackPrompt; 
                        break; 
                    }
                }
                unset($msg);
            }
        } catch (\Throwable $e) {}

        $followOptions = $options;
        $followOptions['stream'] = true;
        $followOptions['tool_choice'] = 'none';
        
        Log::channel('agent_llm')->write('agent_llm_request:' . $this->trimLogValue([
            'stage' => 'legacy_follow',
            'user_id' => $userId,
            'messages' => $followMessages,
            'options' => $followOptions,
        ]), 'info');
        $result2 = $this->llmService->chat($followMessages, $followOptions, $userId);
        $finalContent = '';
        if ($result2 instanceof \Psr\Http\Message\StreamInterface) {
            $buf2 = '';
            while (!$result2->eof()) {
                $chunk2 = $result2->read(1024);
                if ($chunk2 === '') { usleep(10000); continue; }
                $buf2 .= $chunk2;
                while (($p2 = strpos($buf2, "\n")) !== false) {
                    $line2 = substr($buf2, 0, $p2);
                    $buf2 = substr($buf2, $p2 + 1);
                    $line2 = trim($line2);
                    if (strpos($line2, 'data: ') === 0) {
                        $payload2 = substr($line2, 6);
                        if ($payload2 === '[DONE]') continue;
                        $data2 = json_decode($payload2, true);
                        if (isset($data2['choices'][0]['delta']['content'])) {
                            $c2 = $data2['choices'][0]['delta']['content'];
                            $finalContent .= $c2;
                            $pushCallback(['type' => 'content_delta', 'content' => $c2]);
                        }
                    }
                }
            }
        }
        Log::channel('agent_llm')->write('agent_llm_response:' . $this->trimLogValue([
            'stage' => 'legacy_follow',
            'user_id' => $userId,
            'content' => $finalContent,
        ]), 'info');
        
        $pushCallback(['type' => 'done', 'content' => $finalContent]);
        
        // Persist the entire chain: 
        // 1. Assistant Request (with tool_calls)
        // 2. Tool Results (queued or immediate)
        // 3. Assistant Final Response
        
        $chain = [];
        // 1. Assistant Request
        $chain[] = [
            'role' => 'assistant',
            'content' => $accumulatedContent ?: null,
            'tool_calls' => array_values($accumulatedToolCalls)
        ];
        
        // 2. Tool Results
        foreach ($toolResults as $tr) {
            $chain[] = $tr;
        }
        
        // 3. Assistant Final Response
        $chain[] = [
            'role' => 'assistant',
            'content' => $finalContent
        ];
        
        $this->persistMessageChain($options, $chain);
    }

    protected function persistMessageChain(array $options, array $messagesToPersist)
    {
        $conversationUid = isset($options['conversation_id']) ? (string)$options['conversation_id'] : '';
        if ($conversationUid !== '') {
            try {
                $row = Db::table('conversations')->where('conversation_id', $conversationUid)->find();
                if ($row) {
                    $msgs = [];
                    try { $msgs = isset($row['messages_json']) && $row['messages_json'] ? json_decode($row['messages_json'], true) ?: [] : []; } catch (\Throwable $e) { $msgs = []; }
                    
                    foreach ($messagesToPersist as $msg) {
                        // Ensure timestamp exists
                        if (!isset($msg['timestamp'])) {
                            $msg['timestamp'] = time();
                        }
                        // Ensure id exists
                        if (!isset($msg['id'])) {
                            $msg['id'] = (int)(microtime(true) * 1000) . mt_rand(100, 999);
                        }
                        $msgs[] = $msg;
                    }
                    
                    Db::table('conversations')->where('conversation_id', $conversationUid)->update([
                        'messages_json' => json_encode($msgs, JSON_UNESCAPED_UNICODE),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error("Failed to persist message chain: " . $e->getMessage());
            }
        }
    }
}
