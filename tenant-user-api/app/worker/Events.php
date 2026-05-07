<?php

namespace app\worker;

use GatewayWorker\Lib\Gateway;
use thans\jwt\facade\JWTAuth as JwtFacade;
use thans\jwt\exception\JWTException;
use think\facade\Log;
use app\service\AgentService;
use think\Container;

class Events
{
    /**
     * onConnect 事件
     * @param string $client_id
     */
    public static function onConnect($client_id)
    {
        // 向当前 client_id 发送初始化信息
        Gateway::sendToClient($client_id, json_encode([
            'type'      => 'init',
            'client_id' => $client_id,
        ]));
    }

    /**
     * onMessage 事件
     * @param string $client_id
     * @param mixed $message
     */
    public static function onMessage($client_id, $message)
    {
        $data = json_decode($message, true);
        if (!$data) {
            return;
        }

        switch ($data['type'] ?? '') {
            case 'ping':
                Gateway::sendToClient($client_id, json_encode(['type' => 'pong']));
                break;
            case 'bind':
                if (!empty($data['token'])) {
                    try {
                        $token = trim($data['token']);
                        // Strip 'Bearer ' prefix if present
                        if (stripos($token, 'Bearer ') === 0) {
                            $token = trim(substr($token, 7));
                        }

                        $userId = null;
                        try {
                            $payload = JwtFacade::setToken($token)->getPayload();
                            
                            if (is_array($payload)) {
                                $idClaim = $payload['id'] ?? null;
                                if ($idClaim) {
                                    if (is_object($idClaim) && method_exists($idClaim, 'getValue')) {
                                        $userId = $idClaim->getValue();
                                    } elseif (is_array($idClaim) && isset($idClaim['value'])) {
                                        $userId = $idClaim['value'];
                                    } else {
                                        $userId = $idClaim;
                                    }
                                }
                            } elseif (is_object($payload)) {
                                if (isset($payload->id)) {
                                    $userId = $payload->id;
                                    if (is_object($userId) && method_exists($userId, 'getValue')) {
                                        $userId = $userId->getValue();
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // Fallback: manual decode
                            $parts = explode('.', $token);
                            if (count($parts) === 3) {
                                $payloadJson = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
                                $decoded = json_decode($payloadJson, true);
                                $userId = $decoded['id'] ?? null;
                            }
                            if (!$userId) throw $e;
                        }

                        if ($userId) {
                            Gateway::bindUid($client_id, $userId);
                            Gateway::sendToClient($client_id, json_encode([
                                'type' => 'bind_success',
                                'uid'  => $userId
                            ]));
                        } else {
                            throw new \Exception("User ID not found in token");
                        }
                    } catch (\Exception $e) {
                        Gateway::sendToClient($client_id, json_encode([
                            'type' => 'bind_failed',
                            'msg'  => $e->getMessage()
                        ]));
                    }
                }
                break;
            case 'chat':
                $userId = Gateway::getUidByClientId($client_id);
                if (!$userId) {
                    Gateway::sendToClient($client_id, json_encode(['type' => 'error', 'msg' => 'Unauthenticated']));
                    break;
                }
                $messages = $data['messages'] ?? [];
                $options = $data['options'] ?? [];
                $chatId = $data['chat_id'] ?? uniqid('chat_');
                try {
                    $agentService = Container::getInstance()->make(AgentService::class);
                    $agentService->processChat($userId, $messages, $options, function($payload) use ($client_id, $chatId) {
                        $payload['chat_id'] = $chatId;
                        Gateway::sendToClient($client_id, json_encode([
                            'type' => 'chat_update',
                            'data' => $payload
                        ]));
                    });
                } catch (\Exception $e) {
                    Log::error("WS Chat Error: " . $e->getMessage());
                    Gateway::sendToClient($client_id, json_encode([
                        'type' => 'chat_update',
                        'chat_id' => $chatId,
                        'data' => ['type' => 'error', 'msg' => $e->getMessage()]
                    ]));
                }
                break;

            case 'image_generate':
                $userId = Gateway::getUidByClientId($client_id);
                if (!$userId) {
                    Gateway::sendToClient($client_id, json_encode(['type' => 'error', 'msg' => 'Unauthenticated']));
                    break;
                }
                try {
                    $imageService = Container::getInstance()->make(\app\service\ImageService::class);
                    $prompt = $data['prompt'] ?? '';
                    $options = $data['options'] ?? [];
                    
                    // Handle OCR text modification prompt splicing
                    if (isset($data['ocr_modifications']) && is_array($data['ocr_modifications'])) {
                        $parts = [];
                        foreach ($data['ocr_modifications'] as $mod) {
                            if (isset($mod['old']) && isset($mod['new']) && trim($mod['new']) !== '' && $mod['old'] !== $mod['new']) {
                                $parts[] = "将图中的文字“" . $mod['old'] . "”改为“" . $mod['new'] . "”";
                            }
                        }
                        if (!empty($parts)) {
                            $prompt = implode('，', $parts) . "，除了画质优化之外不修改其他内容。";
                        }
                    }

                    $model = $data['model_identity'] ?? '';
                    $res = $imageService->generateAsync($prompt, array_merge($options, ['model_identity' => $model]), $userId);
                    Gateway::sendToClient($client_id, json_encode([
                        'type' => 'image_generate_res',
                        'data' => $res
                    ]));
                } catch (\Exception $e) {
                    Gateway::sendToClient($client_id, json_encode(['type' => 'error', 'msg' => $e->getMessage()]));
                }
                break;

            case 'video_generate':
                $userId = Gateway::getUidByClientId($client_id);
                if (!$userId) {
                    Gateway::sendToClient($client_id, json_encode(['type' => 'error', 'msg' => 'Unauthenticated']));
                    break;
                }
                try {
                    $videoService = Container::getInstance()->make(\app\service\VideoService::class);
                    $prompt = $data['prompt'] ?? '';
                    $model = $data['model_identity'] ?? '';
                    $options = $data['options'] ?? [];
                    $res = $videoService->generate($userId, $model, array_merge($options, ['prompt' => $prompt]));
                    Gateway::sendToClient($client_id, json_encode([
                        'type' => 'video_generate_res',
                        'data' => $res
                    ]));
                } catch (\Exception $e) {
                    Gateway::sendToClient($client_id, json_encode(['type' => 'error', 'msg' => $e->getMessage()]));
                }
                break;

            case 'image_matting':
                $userId = Gateway::getUidByClientId($client_id);
                if (!$userId) {
                    Gateway::sendToClient($client_id, json_encode(['type' => 'error', 'msg' => 'Unauthenticated']));
                    break;
                }
                try {
                    $imageService = Container::getInstance()->make(\app\service\ImageService::class);
                    $imageUrl = $data['image_url'] ?? '';
                    $res = $imageService->matting($imageUrl, [], $userId);
                    Gateway::sendToClient($client_id, json_encode([
                        'type' => 'image_matting_res',
                        'data' => $res
                    ]));
                } catch (\Exception $e) {
                    Gateway::sendToClient($client_id, json_encode(['type' => 'error', 'msg' => $e->getMessage()]));
                }
                break;

            case 'image_ocr':
                $userId = Gateway::getUidByClientId($client_id);
                if (!$userId) {
                    Gateway::sendToClient($client_id, json_encode(['type' => 'error', 'msg' => 'Unauthenticated']));
                    break;
                }
                try {
                    $llmService = Container::getInstance()->make(\app\service\LlmService::class);
                    $imageUrl = $data['image_url'] ?? '';
                    $res = $llmService->ocr($imageUrl, $userId);
                    Gateway::sendToClient($client_id, json_encode([
                        'type' => 'image_ocr_res',
                        'data' => $res
                    ]));
                } catch (\Exception $e) {
                    Gateway::sendToClient($client_id, json_encode(['type' => 'error', 'msg' => $e->getMessage()]));
                }
                break;
        }
    }

    /**
     * onClose 事件
     * @param string $client_id
     */
    public static function onClose($client_id)
    {
        // 自动解绑，GatewayWorker 会处理
    }
}
