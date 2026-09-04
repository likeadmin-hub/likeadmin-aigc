<?php

namespace app\tenantapi\controller\app\aigc_watermark_removal;

use app\common\service\app\aigc_watermark_removal\AigcWatermarkRemovalService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class ConfigController extends BaseAdminController
{
    public function detail() { return $this->success('获取成功', AigcWatermarkRemovalService::config($this->tenantId)); }
    public function setup() { try { AigcWatermarkRemovalService::saveConfig($this->tenantId, $this->request->post()); return $this->success('保存成功', [], 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
}
