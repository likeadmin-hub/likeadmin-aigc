<?php

namespace app\api\controller;

use app\common\service\membership\MembershipService;

class MembershipController extends BaseApiController
{
    public array $notNeedLogin = ['plans'];

    public function plans()
    {
        return $this->success('获取成功', MembershipService::plans((int)$this->request->tenantId, true));
    }

    public function status()
    {
        return $this->success('获取成功', MembershipService::status((int)$this->request->tenantId, $this->userId));
    }

    public function appAccess()
    {
        $appCode = (string)$this->request->get('app_code', '');
        return $this->success('获取成功', MembershipService::appAccess((int)$this->request->tenantId, $this->userId, $appCode));
    }
}
