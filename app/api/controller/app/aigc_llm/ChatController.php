<?php

namespace app\api\controller\app\aigc_llm;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_llm\AigcLlmService;
use Exception;

class ChatController extends BaseApiController
{
    public function stream()
    {
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        try {
            AigcLlmService::streamChat((int)$this->request->tenantId, $this->userId, $this->request->post());
        } catch (Exception $e) {
            echo "event: error\n";
            echo 'data: ' . json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            @flush();
        }
        exit;
    }

    public function stop()
    {
        try {
            AigcLlmService::stopChat((int)$this->request->tenantId, $this->userId, (int)$this->request->post('session_id', 0));
            return $this->success('停止成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
