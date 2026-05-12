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
            return $this->success('保存成功', AigcDigitalHumanService::saveVoice(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ), 1, 1);
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
}
