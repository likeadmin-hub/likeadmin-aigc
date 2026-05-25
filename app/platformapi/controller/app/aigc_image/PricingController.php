<?php

namespace app\platformapi\controller\app\aigc_image;

use app\common\model\app\aigc_image\AigcImageChannel;
use app\common\service\app\UpstreamPricingService;
use app\platformapi\controller\BaseAdminController;

class PricingController extends BaseAdminController
{
    public function batch()
    {
        try {
            $items = $this->request->post('items', []);
            if (!is_array($items)) {
                return $this->fail('参数格式错误');
            }
            $channelMap = array_column(AigcImageChannel::where('tenant_id', 0)->select()->toArray(), null, 'code');
            $payload = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $channelCode = (string)($item['channel_code'] ?? '');
                $channel = $channelMap[$channelCode] ?? [];
                $config = is_array($channel['config_json'] ?? null) ? $channel['config_json'] : [];
                $payload[] = [
                    'model' => (string)($channel['model'] ?? ''),
                    'channel' => (string)($config['upstream_channel'] ?? $config['channel'] ?? ''),
                    'local_key' => (string)($item['local_key'] ?? ''),
                ];
            }
            return $this->success('获取成功', UpstreamPricingService::queryBatch($payload));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
