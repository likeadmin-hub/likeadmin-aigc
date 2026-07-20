<?php

namespace app\platformapi\controller\app\aigc_video;

use app\common\service\app\aigc_video\AigcVideoChannelService;
use app\platformapi\controller\BaseAdminController;

class ChannelController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcVideoChannelService::platformLists());
    }

    public function save()
    {
        return $this->fail('视频通道配置已转为历史只读，新任务请在算力市场管理模型、SKU、上下架和价格');
    }

    public function delete()
    {
        return $this->fail('视频通道配置已转为历史只读');
    }

    public function status()
    {
        return $this->fail('视频通道配置已转为历史只读');
    }
}
