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
            return $this->success('保存成功', ImageHumanService::saveVoice(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ), 1, 1);
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
