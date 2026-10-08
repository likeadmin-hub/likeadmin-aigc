<?php

namespace app\api\controller\app\aigc_pic_lipsync;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncService;
use Exception;

class GenerateController extends BaseApiController
{
    public function estimate() { try { return $this->success('估价成功', AigcPicLipsyncService::estimate((int)$this->request->tenantId, array_merge($this->request->post(), ['_user_id' => $this->userId]))); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function index() { try { return $this->success('图片数字人提交成功', AigcPicLipsyncService::generate((int)$this->request->tenantId, $this->userId, array_merge($this->request->post(), ['_user_id' => $this->userId]))); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
}
