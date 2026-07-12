<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;

class InspirationController extends BaseApiController
{
    public array $notNeedLogin = ['lists', 'detail'];

    public function lists()
    {
        try {
            return $this->success('获取成功', AigcShortDramaService::inspirationLists((int)$this->request->tenantId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcShortDramaService::inspirationDetail((int)$this->request->tenantId, (int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
