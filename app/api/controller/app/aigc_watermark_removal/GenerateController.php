<?php

namespace app\api\controller\app\aigc_watermark_removal;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_watermark_removal\AigcWatermarkRemovalService;
use Exception;

class GenerateController extends BaseApiController
{
    public function estimate() { try { return $this->success('估价成功', AigcWatermarkRemovalService::estimate((int)$this->request->tenantId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function index() { try { return $this->success('短视频去水印提交成功', AigcWatermarkRemovalService::generate((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
}
