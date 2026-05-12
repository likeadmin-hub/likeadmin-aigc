<?php

namespace app\api\controller;

use app\common\service\app\AppFrontendManifestService;

class AppController extends BaseApiController
{
    public array $notNeedLogin = ['frontend'];

    public function frontend()
    {
        $terminal = (string)$this->request->get('terminal', 'uniapp');
        $tenantId = (int)($this->request->tenantId ?? 0);
        return $this->success('获取成功', AppFrontendManifestService::tenantEntries($tenantId, $terminal, $this->userId));
    }
}
