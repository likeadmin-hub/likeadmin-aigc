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
            $data = AigcShortDramaService::home((int)$this->request->tenantId, $this->userId);
            $data = \app\common\service\InspirationBrandService::publicPresentation($data, (int)$this->request->get('presentation_schema', 0) >= 2);
            return $this->success('获取成功', $data);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
