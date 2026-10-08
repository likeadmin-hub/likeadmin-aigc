<?php

namespace app\platformapi\controller\app\aigc_pic_lipsync;

use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncService;
use app\platformapi\controller\BaseAdminController;
use Exception;

class ConfigController extends BaseAdminController
{
    public function detail() { return $this->success('获取成功', AigcPicLipsyncService::config(0)); }
    public function setup() { try { AigcPicLipsyncService::saveConfig(0, $this->request->post()); return $this->success('保存成功', [], 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
}
