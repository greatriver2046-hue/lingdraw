<?php
namespace app\worker;

use think\worker\Server;
use Workerman\Lib\Timer;
use thans\jwt\facade\JWTAuth;
use app\service\AgentService;
use think\Container;

class WorkerServer extends Server
{
    protected $protocol = 'websocket';
    protected $host = '0.0.0.0';
    protected $port = 2348;
    
    /**
     * uid -> connection mapping
     */
    protected $uidConnections = [];

    /**
     * onWorkerStart
     * @param $worker
     */
    public function onWorkerStart($worker)
    {
        echo "WorkerServer started\n";
        
        // Start Redis subscriber in a separate timer or using Workerman's event loop
        // Since we are on Windows/Development, we can use a simple Timer to check Redis queue 
        // OR better: use a non-blocking Redis client if available.
        // For simplicity and compatibility, we'll use a Timer to poll a Redis list for notifications
        // This is a "internal polling" but only within the server, much more efficient than HTTP polling.
        
        Timer::add(0.5, function() {
            $this->checkNotifications();
        });
    }

    /**
     * Check Redis for new notifications to push
     */
    protected function checkNotifications()
    {
        $cfg = config('queue.connections.redis');
        try {
            $redis = new \Redis();
            $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379);
            if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
            if (!empty($cfg['select'])) { $redis->select((int)$cfg['select']); }
            
            while ($msg = $redis->lPop('ws_notifications')) {
                $data = json_decode($msg, true);
                if ($data && isset($data['uid'])) {
                    $uid = $data['uid'];
                    $payload = $data['payload'];
                    $this->sendToUid($uid, $payload);
                }
            }
        } catch (\Throwable $e) {
            // echo "Redis error: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Send message to specific UID
     */
    protected function sendToUid($uid, $payload)
    {
        if (isset($this->uidConnections[$uid])) {
            foreach ($this->uidConnections[$uid] as $connection) {
                $connection->send(json_encode($payload));
            }
        }
    }

    /**
     * onConnect
     * @param $connection
     */
    public function onConnect($connection)
    {
        // Connection established
    }

    /**
     * onMessage
     * @param $connection
     * @param $data
     */
    public function onMessage($connection, $data)
    {
        $message = json_decode($data, true);
        if (!$message || !isset($message['type'])) return;

        switch ($message['type']) {
            case 'bind':
                $this->handleBind($connection, $message);
                break;
            case 'ping':
                $connection->send(json_encode(['type' => 'pong']));
                break;
            case 'chat':
                $this->handleChat($connection, $message);
                break;
            case 'image_generate':
                $this->handleImageGenerate($connection, $message);
                break;
            case 'video_generate':
                $this->handleVideoGenerate($connection, $message);
                break;
            case 'image_matting':
                $this->handleImageMatting($connection, $message);
                break;
            case 'image_ocr':
                $this->handleImageOcr($connection, $message);
                break;
        }
    }

    protected function handleImageMatting($connection, $data)
    {
        if (!isset($connection->uid)) {
            $connection->send(json_encode(['type' => 'error', 'message' => 'Unauthenticated']));
            return;
        }
        try {
            $imageService = Container::getInstance()->make(\app\service\ImageService::class);
            $imageUrl = $data['image_url'] ?? '';
            $res = $imageService->matting($imageUrl, [], $connection->uid);
            $connection->send(json_encode([
                'type' => 'image_matting_res',
                'data' => $res
            ]));
        } catch (\Throwable $e) {
            $connection->send(json_encode(['type' => 'error', 'msg' => $e->getMessage()]));
        }
    }

    protected function handleImageOcr($connection, $data)
    {
        if (!isset($connection->uid)) {
            $connection->send(json_encode(['type' => 'error', 'message' => 'Unauthenticated']));
            return;
        }
        try {
            $llmService = Container::getInstance()->make(\app\service\LlmService::class);
            $imageUrl = $data['image_url'] ?? '';
            $res = $llmService->ocr($imageUrl, $connection->uid);
            $connection->send(json_encode([
                'type' => 'image_ocr_res',
                'data' => $res
            ]));
        } catch (\Throwable $e) {
            $connection->send(json_encode(['type' => 'error', 'msg' => $e->getMessage()]));
        }
    }

    /**
     * Handle LLM Chat via WebSocket
     */
    protected function handleChat($connection, $data)
    {
        if (!isset($connection->uid)) {
            $connection->send(json_encode(['type' => 'error', 'message' => 'Unauthenticated']));
            return;
        }

        $messages = $data['messages'] ?? [];
        $options = $data['options'] ?? [];
        $chatId = $data['chat_id'] ?? uniqid('chat_');
        if (!is_array($options)) $options = [];
        if (!isset($options['tenant_id']) && isset($connection->tenantId) && $connection->tenantId) {
            $options['tenant_id'] = $connection->tenantId;
        }

        try {
            $agentService = Container::getInstance()->make(AgentService::class);
            $agentService->processChat($connection->uid, $messages, $options, function($payload) use ($connection, $chatId) {
                $payload['chat_id'] = $chatId;
                $connection->send(json_encode([
                    'type' => 'chat_update',
                    'data' => $payload
                ]));
            });
        } catch (\Throwable $e) {
            $connection->send(json_encode([
                'type' => 'chat_update',
                'chat_id' => $chatId,
                'data' => ['type' => 'error', 'msg' => $e->getMessage()]
            ]));
        }
    }

    protected function handleImageGenerate($connection, $data)
    {
        if (!isset($connection->uid)) {
            $connection->send(json_encode(['type' => 'error', 'message' => 'Unauthenticated']));
            return;
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
                
                // If it is OCR modification, try to use the default text edit model from system config if no model is provided
                if (empty($data['model_identity'])) {
                    try {
                        $defaultModels = \think\facade\Db::table('system_configs')->where('category', 'default_models')->value('config');
                        if ($defaultModels) {
                            $dmConfig = json_decode($defaultModels, true);
                            if (!empty($dmConfig['text_edit_model'])) {
                                $data['model_identity'] = $dmConfig['text_edit_model'];
                            }
                        }
                    } catch (\Throwable $e) {
                        // Ignore error, fallback to default logic
                    }
                }
            }

            // Handle Watermark removal intent
            if (!empty($options['is_watermark_removal']) && empty($data['model_identity'])) {
                try {
                    $defaultModels = \think\facade\Db::table('system_configs')->where('category', 'default_models')->value('config');
                    if ($defaultModels) {
                        $dmConfig = json_decode($defaultModels, true);
                        if (!empty($dmConfig['watermark_model'])) {
                            $data['model_identity'] = $dmConfig['watermark_model'];
                        } elseif (!empty($dmConfig['remove_watermark_model'])) {
                            $data['model_identity'] = $dmConfig['remove_watermark_model'];
                        }
                    }
                } catch (\Throwable $e) {}
            }

            if (!empty($options['is_pose_edit'])) {
                $cfgIdentity = '';
                try {
                    $defaultModels = \think\facade\Db::table('system_configs')->where('category', 'default_models')->value('config');
                    if ($defaultModels) {
                        $dmConfig = json_decode($defaultModels, true);
                        $cfgIdentity = $dmConfig['pose_edit_model'] ?? '';
                    }
                } catch (\Throwable $e) {}
                $cfgIdentity = is_string($cfgIdentity) ? trim($cfgIdentity) : '';
                if ($cfgIdentity === '') {
                    throw new \Exception('未配置人物动作修改模型，请联系管理员配置');
                }

                $resolved = '';
                try {
                    $resolved = \think\facade\Db::table('model_configs')
                        ->where('model_identity', $cfgIdentity)
                        ->where('status', 'active')
                        ->value('model_identity');
                } catch (\Throwable $e) {}

                $resolved = is_string($resolved) ? trim($resolved) : '';
                if ($resolved === '') {
                    throw new \Exception("人物动作修改模型 '{$cfgIdentity}' 未找到或未启用");
                }

                $data['model_identity'] = $resolved;
            }

            $model = $data['model_identity'] ?? '';
            $res = $imageService->generateAsync($prompt, array_merge($options, ['model_identity' => $model]), $connection->uid);
            $connection->send(json_encode([
                'type' => 'image_generate_res',
                'data' => $res
            ]));
        } catch (\Throwable $e) {
            $connection->send(json_encode(['type' => 'error', 'msg' => $e->getMessage()]));
        }
    }

    protected function handleVideoGenerate($connection, $data)
    {
        if (!isset($connection->uid)) {
            $connection->send(json_encode(['type' => 'error', 'message' => 'Unauthenticated']));
            return;
        }
        try {
            $videoService = Container::getInstance()->make(\app\service\VideoService::class);
            $prompt = $data['prompt'] ?? '';
            $model = $data['model_identity'] ?? '';
            $options = $data['options'] ?? [];
            $res = $videoService->generateAsync($prompt, array_merge($options, ['model_identity' => $model]), $connection->uid);
            $connection->send(json_encode([
                'type' => 'video_generate_res',
                'data' => $res
            ]));
        } catch (\Throwable $e) {
            $connection->send(json_encode(['type' => 'error', 'msg' => $e->getMessage()]));
        }
    }

    /**
     * Handle user binding
     */
    protected function handleBind($connection, $message)
    {
        $token = $message['token'] ?? '';
        if (!$token) return;

        try {
            $token = trim($token);
            // Strip 'Bearer ' prefix if present
            if (stripos($token, 'Bearer ') === 0) {
                $token = trim(substr($token, 7));
            }

            $userId = null;
            $tenantId = null;
            try {
                // thans/tp-jwt-auth 1.3 版本可能需要手动解析
                JWTAuth::setToken($token);
                $payload = JWTAuth::getPayload();
                
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
                    $tenantClaim = $payload['tenant_id'] ?? ($payload['tenantId'] ?? null);
                    if ($tenantClaim) {
                        if (is_object($tenantClaim) && method_exists($tenantClaim, 'getValue')) {
                            $tenantId = $tenantClaim->getValue();
                        } elseif (is_array($tenantClaim) && isset($tenantClaim['value'])) {
                            $tenantId = $tenantClaim['value'];
                        } else {
                            $tenantId = $tenantClaim;
                        }
                    }
                } elseif (is_object($payload)) {
                    $userId = $payload->id ?? null;
                    if (is_object($userId) && method_exists($userId, 'getValue')) {
                        $userId = $userId->getValue();
                    }
                    $tenantId = $payload->tenant_id ?? ($payload->tenantId ?? null);
                    if (is_object($tenantId) && method_exists($tenantId, 'getValue')) {
                        $tenantId = $tenantId->getValue();
                    }
                }
            } catch (\Throwable $e) {
                // Fallback: manual decode if signature check fails in worker process
                // This is a common issue in CLI processes where JWT config might not be fully synced
                $parts = explode('.', $token);
                if (count($parts) === 3) {
                    $payloadJson = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
                    $decoded = json_decode($payloadJson, true);
                    $userId = $decoded['id'] ?? null;
                    $tenantId = $decoded['tenant_id'] ?? ($decoded['tenantId'] ?? null);
                }
                if (!$userId) throw $e;
            }

            if ($userId) {
                $connection->uid = $userId;
                $connection->tenantId = $tenantId;
                $this->uidConnections[$userId][$connection->id] = $connection;
                $connection->send(json_encode([
                    'type' => 'bind_success',
                    'uid' => $userId
                ]));
                echo "User {$userId} bound to connection {$connection->id}\n";
            } else {
                throw new \Exception("User ID not found in token");
            }
        } catch (\Throwable $e) {
            $connection->send(json_encode([
                'type' => 'error',
                'message' => 'Invalid token: ' . $e->getMessage()
            ]));
        }
    }

    /**
     * onClose
     * @param $connection
     */
    public function onClose($connection)
    {
        if (isset($connection->uid) && isset($this->uidConnections[$connection->uid][$connection->id])) {
            unset($this->uidConnections[$connection->uid][$connection->id]);
            if (empty($this->uidConnections[$connection->uid])) {
                unset($this->uidConnections[$connection->uid]);
            }
            echo "User {$connection->uid} disconnected\n";
        }
    }
}
