<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;

class HomeController extends BaseApiController
{
    public array $notNeedLogin = ['index'];

    public function index()
    {
        try {
            return $this->success('获取成功', AigcShortDramaService::home((int)$this->request->tenantId, $this->userId));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
