<?php

namespace app\platformapi\controller\app\aigc_video;

use app\common\model\app\aigc_video\AigcVideoChannel;
use app\common\model\app\aigc_video\AigcVideoChannelSpec;
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
            $channelMap = array_column(AigcVideoChannel::where('tenant_id', 0)->select()->toArray(), null, 'code');
            $payload = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $channelCode = (string)($item['channel_code'] ?? '');
                $channel = $channelMap[$channelCode] ?? [];
                $config = is_array($channel['config_json'] ?? null) ? $channel['config_json'] : [];
                $spec = $this->findSpec($channelCode, (string)($item['quality'] ?? ''), (string)($item['ratio'] ?? ''), (string)($item['local_key'] ?? ''));
                $providerParams = is_array($spec['provider_params_json'] ?? null) ? $spec['provider_params_json'] : [];
                $appCode = (string)($config['app_code'] ?? $channelCode);
                $apiCode = $this->pricingApiCode($config);
                $payload[] = array_filter([
                    'type' => $apiCode !== '' ? 'app_api' : 'model',
                    'model' => (string)($channel['model'] ?? ''),
                    'provider' => (string)($channel['provider'] ?? ''),
                    'app_code' => $appCode,
                    'api_code' => $apiCode,
                    'channel' => (string)($config['upstream_channel'] ?? $config['channel'] ?? ''),
                    'quality' => (string)($spec['quality'] ?? $item['quality'] ?? ''),
                    'quality_label' => (string)($spec['quality_label'] ?? $item['quality_label'] ?? ''),
                    'ratio' => (string)($spec['ratio'] ?? $item['ratio'] ?? ''),
                    'size' => (string)($providerParams['size'] ?? $providerParams['ratio'] ?? $providerParams['aspect_ratio'] ?? $spec['ratio'] ?? $item['ratio'] ?? ''),
                    'aspect_ratio' => (string)($providerParams['aspect_ratio'] ?? $providerParams['ratio'] ?? $providerParams['size'] ?? $spec['ratio'] ?? $item['ratio'] ?? ''),
                    'resolution' => (string)($providerParams['resolution'] ?? $item['resolution'] ?? ''),
                    'duration' => $providerParams['duration'] ?? $item['duration'] ?? null,
                    'width' => (int)($spec['width'] ?? $item['width'] ?? 0),
                    'height' => (int)($spec['height'] ?? $item['height'] ?? 0),
                    'provider_params' => $providerParams,
                    'provider_params_json' => $providerParams,
                    'spec' => [
                        'quality' => (string)($spec['quality'] ?? $item['quality'] ?? ''),
                        'quality_label' => (string)($spec['quality_label'] ?? $item['quality_label'] ?? ''),
                        'ratio' => (string)($spec['ratio'] ?? $item['ratio'] ?? ''),
                        'width' => (int)($spec['width'] ?? $item['width'] ?? 0),
                        'height' => (int)($spec['height'] ?? $item['height'] ?? 0),
                        'provider_params' => $providerParams,
                    ],
                    'local_key' => (string)($item['local_key'] ?? ''),
                ], static fn($value) => $value !== '' && $value !== null && $value !== []);
            }
            return $this->success('获取成功', UpstreamPricingService::queryBatch($payload));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function findSpec(string $channelCode, string $quality, string $ratio, string $localKey): array
    {
        if ($quality === '' || $ratio === '') {
            $parts = explode('|', $localKey);
            $quality = $quality ?: (string)($parts[1] ?? '');
            $ratio = $ratio ?: (string)($parts[2] ?? '');
        }
        if ($channelCode === '' || $quality === '' || $ratio === '') {
            return [];
        }
        $row = AigcVideoChannelSpec::where([
            'tenant_id' => 0,
            'channel_code' => $channelCode,
            'quality' => strtolower($quality),
            'ratio' => $ratio,
        ])->findOrEmpty();
        return $row->isEmpty() ? [] : $row->toArray();
    }

    private function pricingApiCode(array $config): string
    {
        $apiCode = trim((string)($config['pricing_api_code'] ?? $config['api_code'] ?? ''));
        if ($apiCode !== '') {
            return $apiCode;
        }
        $submitPath = trim((string)($config['submit_path'] ?? ''));
        if ($submitPath === '') {
            return '';
        }
        $path = (string)(parse_url($submitPath, PHP_URL_PATH) ?: $submitPath);
        $segment = basename(trim($path, '/'));
        return $segment !== '' ? $segment : 'create';
    }
}
