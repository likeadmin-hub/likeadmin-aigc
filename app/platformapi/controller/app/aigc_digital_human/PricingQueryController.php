<?php

namespace app\platformapi\controller\app\aigc_digital_human;

use app\common\service\app\UpstreamPricingService;
use app\platformapi\controller\BaseAdminController;

class PricingQueryController extends BaseAdminController
{
    public function model()
    {
        try {
            return $this->success('获取成功', UpstreamPricingService::queryAppApi('lipsync', 'submit'));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function clone()
    {
        try {
            $voiceClone = UpstreamPricingService::queryAppApi('voice_tts', 'clone_voice');
            return $this->success('获取成功', [
                'voice_clone' => $voiceClone,
                'avatar_clone' => [
                    'available' => false,
                    'message' => '当前形象克隆为本地素材上传计费，未映射上游应用 API',
                    'price_view' => [
                        'formula' => '暂无上游价格',
                        'billing_type_desc' => '本地计费',
                    ],
                    'requested_at' => date('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function batch()
    {
        try {
            return $this->success('获取成功', UpstreamPricingService::queryBatch([
                [
                    'type' => 'app_api',
                    'app_code' => 'voice_tts',
                    'api_code' => 'clone_voice',
                    'local_key' => 'voice_clone',
                ],
                [
                    'type' => 'app_api',
                    'app_code' => 'lipsync',
                    'api_code' => 'submit',
                    'local_key' => 'generate',
                ],
            ]));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
