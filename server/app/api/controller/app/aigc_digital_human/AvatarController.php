<?php

namespace app\api\controller\app\aigc_digital_human;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use Exception;

class AvatarController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        return $this->success('获取成功', AigcDigitalHumanService::avatarLists(
            (int)$this->request->tenantId,
            $this->userId,
            (string)$this->request->get('source', '')
        ));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcDigitalHumanService::saveAvatar(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
