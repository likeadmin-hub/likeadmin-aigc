<?php

namespace app\api\controller\app\aigc_style_transfer;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_style_transfer\AigcStyleTransferService;
use Exception;

class StyleTemplateController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcStyleTransferService::templateLists(
            (int)$this->request->tenantId,
            array_merge($this->request->get(), ['only_enabled' => 1])
        ));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcStyleTransferService::templateDetail(
                (int)$this->request->tenantId,
                (int)$this->request->get('id', 0)
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

