<?php

namespace app\platformapi\controller\app\aigc_video;

use app\common\service\app\aigc_video\AigcVideoChannelService;
use app\platformapi\controller\BaseAdminController;

class SpecController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcVideoChannelService::platformLists());
    }

    public function save()
    {
        return $this->fail('视频规格价格已转为历史只读，新任务价格以算力市场 SKU 为准');
    }

    public function batchSave()
    {
        return $this->fail('视频规格价格已转为历史只读，新任务价格以算力市场 SKU 为准');
    }

    public function delete()
    {
        return $this->fail('视频规格价格已转为历史只读');
    }

    public function status()
    {
        return $this->fail('视频规格价格已转为历史只读');
    }
}
