<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;

class VoiceController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        try {
            return $this->success('success', AigcShortDramaService::voiceLists(
                (int)$this->request->tenantId,
                (int)$this->userId,
                (string)$this->request->get('source', '')
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save()
    {
        try {
            $tenantId = (int)$this->request->tenantId;
            $userId = (int)$this->userId;
            $row = AigcShortDramaService::saveVoice($tenantId, $userId, $this->request->post());
            if (($row['status'] ?? '') === 'running') {
                register_shutdown_function(static function () use ($tenantId, $userId) {
                    if (function_exists('fastcgi_finish_request')) {
                        @fastcgi_finish_request();
                    }
                    AigcShortDramaService::processPendingVoiceClones($tenantId, $userId);
                });
            }
            $message = ($row['status'] ?? '') === 'running' ? '音色克隆任务已提交' : '音色保存成功';
            return $this->success($message, $row, 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function preview()
    {
        try {
            return $this->success('合成成功', AigcShortDramaService::previewVoice(
                (int)$this->request->tenantId,
                (int)$this->userId,
                $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function trim()
    {
        try {
            return $this->success('裁剪成功', AigcShortDramaService::trimVoiceSample(
                (int)$this->request->tenantId,
                (int)$this->userId,
                $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
