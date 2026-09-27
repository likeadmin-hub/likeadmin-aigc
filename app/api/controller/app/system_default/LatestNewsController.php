<?php
namespace app\api\controller\app\system_default;
use app\api\controller\BaseApiController;
use app\common\service\home\LatestNewsService;
class LatestNewsController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];
    public function lists() { return $this->success('获取成功', LatestNewsService::lists((int)$this->request->tenantId, true)); }
}
