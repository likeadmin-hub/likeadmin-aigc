<?php

namespace app\api\controller\app\aigc_music;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_music\AigcMusicService;
use Exception;
use think\facade\Log;
use Throwable;

class GenerateController extends BaseApiController
{
    public function estimate()
    {
        try {
            return $this->success('估价成功', AigcMusicService::estimate((int)$this->request->tenantId, $this->request->post()));
        } catch (Throwable $e) {
            return $this->fail($this->formatSubmitError($e));
        }
    }

    public function index()
    {
        try {
            return $this->success('生成成功', AigcMusicService::generate((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Throwable $e) {
            return $this->fail($this->formatSubmitError($e));
        }
    }

    private function formatSubmitError(Throwable $e): string
    {
        if ($e instanceof Exception) {
            return $e->getMessage();
        }
        Log::write('AI音乐生成提交异常：' . $e->getMessage());
        return '提交音乐生成任务失败，请稍后重试';
    }
}
