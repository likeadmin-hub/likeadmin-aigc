<?php

namespace app\api\controller\app\aigc_pic_lipsync;

use app\api\controller\BaseApiController;
use app\common\service\app\image_human\ImageHumanService;
use Exception;

class VoiceController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

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

    public function preview()
    {
        $id = (int)$this->request->post('voice_id', $this->request->post('id', 0));
        foreach (ImageHumanService::voiceLists((int)$this->request->tenantId, $this->userId) as $voice) {
            if ((int)$voice['id'] === $id && !empty($voice['audio_url'])) {
                return $this->success('获取成功', ['audio_url' => $voice['audio_url'], 'audio_uri' => $voice['audio_uri']]);
            }
        }
        return $this->fail('音色不存在或缺少参考音频');
    }

    public function trim()
    {
        try {
            return $this->success('裁剪成功', \app\common\service\app\aigc_digital_human\AigcDigitalHumanService::trimVoiceSample((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) { return $this->fail($e->getMessage()); }
    }
}
