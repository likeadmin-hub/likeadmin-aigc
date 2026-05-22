<?php

namespace app\api\controller\app\image_human;

use app\api\controller\BaseApiController;
use app\common\service\app\image_human\ImageHumanService;
use Exception;

class VoiceController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', ImageHumanService::voiceLists(
            (int)$this->request->tenantId,
            $this->userId,
            (string)$this->request->get('source', '')
        ));
    }

    public function save()
    {
        try {
            $row = ImageHumanService::saveVoice(
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
                    \app\common\service\app\aigc_digital_human\AigcDigitalHumanService::processPendingCloneAssets($tenantId, $userId);
                });
            }
            $message = ($row['status'] ?? '') === 'running' ? '提交成功，音色将在后台克隆' : '保存成功';
            return $this->success($message, $row, 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            ImageHumanService::deleteVoice(
                (int)$this->request->tenantId,
                $this->userId,
                (int)$this->request->post('id', 0)
            );
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
