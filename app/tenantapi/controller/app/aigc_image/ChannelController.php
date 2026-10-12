<?php

namespace app\tenantapi\controller\app\aigc_image;

use app\common\service\app\aigc_image\AigcImageChannelService;
use app\tenantapi\controller\BaseAdminController;

class ChannelController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcImageChannelService::tenantLists($this->tenantId));
    }

    public function save()
    {
        return $this->fail('生图通道调价已停用，请在算力市场配置价格');
    }

    public function batchSave()
    {
        return $this->save();
    }

    public function status()
    {
        return $this->save();
    }
}
