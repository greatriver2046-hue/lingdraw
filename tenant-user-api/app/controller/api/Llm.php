<?php
namespace app\controller\api;

use app\BaseController;
use app\service\LlmService;
use app\service\ImageService;
use app\service\VideoService;
use app\service\AgentService;
use app\model\ModelConfig;
use think\Request;
use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
use think\facade\Cache;
use app\model\LlmLog;

class Llm extends BaseController
{
    protected $llmService;
    protected $imageService;
    protected $videoService;
    protected $agentService;

    public function __construct(LlmService $llmService, ImageService $imageService, VideoService $videoService, AgentService $agentService)
    {
        $this->llmService = $llmService;
        $this->imageService = $imageService;
        $this->videoService = $videoService;
        $this->agentService = $agentService;
    }

    protected function mapAspectRatioToSize($ratio)
    {
        switch ($ratio) {
            case '16:9': return '2560x1440';
            case '9:16': return '1440x2560';
            case '4:3':  return '2048x1536';
            case '3:4':  return '1536x2048';
            case '3:2':  return '2160x1440';
            case '2:3':  return '1440x2160';
            case '21:9': return '2560x1080';
            case '9:21': return '1080x2560';
            case '1:1':
            default:     return '2048x2048';
        }
    }

    public function ocr(Request $request)
    {
        $params = $request->post();
        $imageUrl = $params['image_url'] ?? '';

        if (!$imageUrl) {
            return json(['code' => 400, 'msg' => 'Image URL is required', 'data' => null]);
        }

        try {
            $userId = $request->userId ?? null;
            $texts = $this->llmService->ocr($imageUrl, $userId);
            return json(['code' => 200, 'msg' => 'Success', 'data' => $texts]);
        } catch (\Exception $e) {
            Log::error('OCR Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    public function chat(Request $request)
    {
        $params = $request->post();
        $messages = $params['messages'] ?? [];
        $options = $params['options'] ?? [];
        $userId = $request->userId ?? null;

        if (empty($messages)) {
            return json(['code' => 400, 'msg' => 'Messages are required', 'data' => null]);
        }

        try {
            $isStream = $options['stream'] ?? false;

            if ($isStream) {
                @ini_set('output_buffering', 'off');
                @ini_set('zlib.output_compression', 0);
                if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }

                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('Connection: keep-alive');
                header('X-Accel-Buffering: no');
                header('Access-Control-Allow-Origin: *');

                $this->agentService->processChat($userId, $messages, $options, function($payload) {
                    echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
                    if (ob_get_level() > 0) { ob_flush(); }
                    flush();
                });

                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) { ob_flush(); }
                flush();
                exit;
            }

            // Non-stream mode: we still use agentService but collect output
            $fullContent = '';
            $error = null;
            $this->agentService->processChat($userId, $messages, $options, function($payload) use (&$fullContent, &$error) {
                if ($payload['type'] === 'content_delta') {
                    $fullContent .= $payload['content'];
                } elseif ($payload['type'] === 'done') {
                    $fullContent = $payload['content'];
                } elseif ($payload['type'] === 'error') {
                    $error = $payload['msg'];
                }
            });

            if ($error) {
                throw new \Exception($error);
            }

            return json(['code' => 200, 'msg' => 'Success', 'data' => $fullContent]);

        } catch (\Exception $e) {
            Log::error('LLM Chat Error: ' . $e->getMessage());
            if (!headers_sent()) {
                return json(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
            } else {
                echo 'data: ' . json_encode(['type' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n\n";
                echo "data: [DONE]\n\n";
                flush();
                exit;
            }
        }
    }

    public function agent(Request $request)
    {
        // Agent and Chat now both use the same underlying service logic
        return $this->chat($request);
    }
}
