<?php
declare(strict_types=1);

namespace app\common\service\app\aigc_layer_decomposition;

use app\common\model\app\aigc_image\AigcImageResult;
use app\common\service\ai\StudioMediaInputService;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\power\MarketLayerDecomposition as Market;
use InvalidArgumentException;

final class LayerDecompositionService
{
    public static function config(int $tenant): array
    {
        return [
            'sizes' => array_values(array_map(static fn($option) => ['value'=>$option['value']], Market::sizes($tenant))),
            'display_config' => AppDisplayConfigService::detail($tenant, Market::APP),
        ];
    }

    public static function estimate(int $tenant, int $user, array $params): array
    {
        return AigcImageService::estimateMarketModel($tenant, self::request($tenant, $user, $params), [], Market::APP);
    }

    public static function generate(int $tenant, int $user, array $params): array
    {
        $request = self::request($tenant, $user, $params);
        $key = $params['batch_key'] ?? null;
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{8,120}$/D', $key)) {
            throw new InvalidArgumentException('提交标识无效，请重新提交');
        }
        $request['batch_key'] = $key;
        return AigcImageService::generateMarketModelWithBillingOverride($tenant, $user, $request, [], Market::APP);
    }

    private static function request(int $tenant, int $user, array $params): array
    {
        if (AppAccessService::assertTenantAvailable($tenant, Market::APP) !== null) {
            throw new InvalidArgumentException('图层分离暂未开通或已下架');
        }
        $source = $params['source_image'] ?? '';
        if (!is_string($source) || trim($source) === '') throw new InvalidArgumentException('请上传一张待拆分图片');
        $prompt = $params['prompt'] ?? '';
        if (!is_string($prompt) || mb_strlen($prompt) > 2000) throw new InvalidArgumentException('拆分描述最多 2000 字');
        $size = $params['size'] ?? 'auto';
        $sizes = Market::sizes($tenant);
        if (!is_string($size) || !isset($sizes[$size])) throw new InvalidArgumentException('所选分辨率当前不可用');
        $format = $params['output_format'] ?? 'png';
        if (!in_array($format, ['png', 'jpeg'], true)) throw new InvalidArgumentException('底图格式须为 PNG 或 JPEG');
        $limit = filter_var($params['max_images'] ?? 30, FILTER_VALIDATE_INT, ['options'=>['min_range'=>2, 'max_range'=>100]]);
        if ($limit === false) throw new InvalidArgumentException('最大输出张数须为 2 至 100 的整数');
        $recognize = filter_var($params['text_recognition'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($recognize === null) throw new InvalidArgumentException('文字识别设置无效');
        $image = StudioMediaInputService::validateImages($tenant, $user, [['uri'=>$source]], 1)[0];

        return [
            'prompt' => trim($prompt),
            'reference_images' => [$image['uri']],
            'style' => Market::STYLE,
            'quantity' => 1,
            'model_id' => 'market_image_model:' . $sizes[$size]['market_product_id'],
            'market_product_id' => $sizes[$size]['market_product_id'],
            'market_sku_id' => $sizes[$size]['market_sku_id'],
            'provider_params' => [
                'size'=>$size, 'output_format'=>$format, 'max_images'=>$limit,
                'text_recognition'=>$recognize, 'nsfw_checker'=>true,
            ],
        ];
    }

    public static function tasks(int $tenant, int $user, array $params): array
    {
        $params = array_intersect_key($params, array_flip(['page_no', 'page_size', 'status', 'task_id', 'user_keyword']));
        $params['style'] = Market::STYLE;
        return AigcImageService::taskLists($tenant, $user, $params);
    }

    public static function detail(int $tenant, int $user, int $id): array
    {
        $task = AigcImageService::taskDetail($tenant, $id, $user);
        if (($task['style'] ?? '') !== Market::STYLE) throw new InvalidArgumentException('图层分离任务不存在');
        return $task;
    }

    public static function deleteTask(int $tenant, int $user, int $id): void
    {
        $task = self::detail($tenant, $user, $id);
        if (!in_array($task['status'], ['success', 'failed', 'canceled'], true)) throw new InvalidArgumentException('任务处理中，请稍后再删除');
        AigcImageService::deleteTask($tenant, $id, $user);
    }

    public static function deleteResult(int $tenant, int $user, int $id): void
    {
        $result = AigcImageResult::where(['tenant_id'=>$tenant, 'user_id'=>$user, 'id'=>$id, 'delete_time'=>0])->findOrEmpty();
        if ($result->isEmpty()) throw new InvalidArgumentException('图层不存在');
        self::detail($tenant, $user, (int)$result['task_id']);
        AigcImageService::deleteResult($tenant, $id, $user);
    }
}
