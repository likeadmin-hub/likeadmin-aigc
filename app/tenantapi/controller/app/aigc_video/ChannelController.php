<?php

namespace app\tenantapi\controller\app\aigc_video;

use app\common\service\app\aigc_video\AigcVideoChannelService;
use app\tenantapi\controller\BaseAdminController;

class ChannelController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcVideoChannelService::tenantLists($this->tenantId));
    }

    public function save()
    {
        return $this->fail('视频通道价格已转为历史只读，请在租户算力市场配置 SKU 销售价和上下架');
    }

    public function batchSave()
    {
        return $this->fail('视频通道价格已转为历史只读，请在租户算力市场配置 SKU 销售价和上下架');
    }

    public function status()
    {
        return $this->fail('视频通道价格已转为历史只读，请在租户算力市场配置 SKU 销售价和上下架');
    }
}
