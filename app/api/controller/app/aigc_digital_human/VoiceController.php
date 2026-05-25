<?php

namespace app\api\controller\app\aigc_digital_human;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use Exception;

class VoiceController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        return $this->success('获取成功', AigcDigitalHumanService::voiceLists(
            (int)$this->request->tenantId,
            $this->userId,
            (string)$this->request->get('source', '')
        ));
    }

    public function save()
    {
        try {
            $row = AigcDigitalHumanService::saveVoice(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            );
            if (($row['status'] ?? '') === 'running') {
                $tenantId = (int)$this->request->tenantId;
                $userId = (int)$this->userId;
                register_shutdown_function(static function () use ($tenantId, $userId) {
                    if (function_exists('fastcgi_finish_request')) {
                        @fastcgi_finish_request();
                    }
                    AigcDigitalHumanService::processPendingCloneAssets($tenantId, $userId);
                });
            }
            $message = ($row['status'] ?? '') === 'running' ? '提交成功，音色将在后台克隆' : '保存成功';
            return $this->success($message, $row, 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function preview()
    {
        try {
            return $this->success('合成成功', AigcDigitalHumanService::previewVoice(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function trim()
    {
        try {
            return $this->success('裁剪成功', AigcDigitalHumanService::trimVoiceSample(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
