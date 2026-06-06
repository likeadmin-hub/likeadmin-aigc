<?php

namespace app\api\controller\app\smart_clip;

use app\api\controller\BaseApiController;
use app\common\service\app\smart_clip\SmartClipService;
use Exception;
use think\facade\Log;
use Throwable;

class GenerateController extends BaseApiController
{
    public function estimate()
    {
        try {
            $result = SmartClipService::estimate((int)$this->request->tenantId, $this->request->post());
            return $this->success('估价成功', $result);
        } catch (Throwable $e) {
            return $this->fail($this->formatSubmitError($e));
        }
    }

    public function index()
    {
        try {
            $result = SmartClipService::generate((int)$this->request->tenantId, $this->userId, $this->request->post());
            return $this->success('生成成功', $result);
        } catch (Throwable $e) {
            return $this->fail($this->formatSubmitError($e));
        }
    }

    private function formatSubmitError(Throwable $e): string
    {
        if ($e instanceof Exception) {
            return $e->getMessage();
        }
        Log::write('视频剪辑提交异常：' . $e->getMessage());
        return '提交视频剪辑任务失败，请稍后重试';
    }
}
