<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\ShortDramaTemplateService;
use Exception;

class TemplateController extends BaseApiController
{
    public array $notNeedLogin = ['homepage', 'detail'];
    public function homepage() { try { return $this->success('获取成功', ShortDramaTemplateService::homepage((int)$this->request->tenantId, $this->request->get())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function detail() { try { return $this->success('获取成功', ShortDramaTemplateService::detail((int)$this->request->tenantId, (int)$this->request->get('id', 0), true)); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
}
