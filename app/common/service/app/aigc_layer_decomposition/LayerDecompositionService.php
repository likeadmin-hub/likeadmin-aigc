<?php
declare(strict_types=1);

namespace app\common\service\app\aigc_layer_decomposition;

use app\common\model\app\aigc_image\AigcImageResult;
use app\common\model\app\TenantAppConfig;
use app\common\service\ai\StudioMediaInputService;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\power\MarketLayerDecomposition as Market;
use InvalidArgumentException;
use think\facade\Db;

final class LayerDecompositionService
{
    public static function config(int $tenant, bool $admin = false): array
    {
        $display = AppDisplayConfigService::detail($tenant, Market::APP);
        $code = self::configuredModel($display);
        $catalog = Market::catalog($tenant, $admin ? null : $code);
        $sizes = static function (array $rows): array {
            $options = \app\common\service\power\MarketHealth::attachOptions($rows);
            return array_values(array_map(static fn(array $row): array => array_intersect_key($row, ['value' => true, 'health_24h' => true]), $options));
        };
        $data = [
            'model' => Market::profile($code),
            'sizes' => $sizes($catalog[$code] ?? []),
            'display_config' => $display,
        ];
        if ($admin) {
            $data['default_model'] = $code;
            $data['models'] = [];
            foreach ($catalog as $model => $rows) {
                $data['models'][] = Market::profile($model) + ['sizes'=>$sizes($rows)];
            }
        }
        return $data;
    }

    public static function setup(int $tenant, array $params): void
    {
        Db::transaction(static function () use ($tenant, $params) {
            $scope = ['tenant_id'=>$tenant, 'app_code'=>Market::APP];
            TenantAppConfig::where($scope)->lock(true)->find();
            $current = self::configuredModel(AppDisplayConfigService::detail($tenant, Market::APP));
            $code = array_key_exists('default_model', $params) ? $params['default_model'] : $current;
            if (!is_string($code) || Market::profile($code) === null) {
                throw new InvalidArgumentException('请选择图层分离模型');
            }
            if ($code !== $current && empty(Market::catalog($tenant, $code)[$code])) {
                throw new InvalidArgumentException('所选模型暂无有效上架规格，请重新选择');
            }
            AppDisplayConfigService::saveFromConfigPayload($tenant, Market::APP, $params);
            if ($code === $current) return;
            $row = TenantAppConfig::where($scope)->findOrEmpty();
            if ($row->isEmpty()) {
                AppDisplayConfigService::save($tenant, Market::APP, []);
                $row = TenantAppConfig::where($scope)->findOrEmpty();
            }
            $extra = (array)$row['extra'];
            $extra[Market::MODEL_SETTING] = $code;
            $row->save(['extra'=>$extra, 'update_time'=>time()]);
        });
    }

    private static function configuredModel(array $display): string
    {
        $code = $display['extra'][Market::MODEL_SETTING] ?? Market::MODEL;
        return is_string($code) && Market::profile($code) !== null ? $code : Market::MODEL;
    }

    public static function estimate(int $tenant, int $user, array $params): array
    {
        return AigcImageService::estimateMarketModel($tenant, self::request($tenant, $user, $params), [], Market::APP);
    }

    public static function generate(int $tenant, int $user, array $params): array
    {
        $key = $params['batch_key'] ?? null;
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{8,120}$/D', $key)) {
            throw new InvalidArgumentException('提交标识无效，请重新提交');
        }
        if ($existing = AigcImageService::existingMarketSubmission($tenant, $user, $params, Market::APP)) return $existing;
        $request = self::request($tenant, $user, $params);
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
        $code = self::configuredModel(AppDisplayConfigService::detail($tenant, Market::APP));
        if (($params['model_code'] ?? Market::MODEL) !== $code) {
            throw new InvalidArgumentException('租户的默认模型已调整，请刷新配置后重新报价');
        }
        $profile = Market::profile($code);
        $sizes = Market::catalog($tenant, $code)[$code] ?? [];
        if (!$sizes) throw new InvalidArgumentException('默认图层分离模型暂无有效规格，请联系管理员');
        $prompt = $params['prompt'] ?? '';
        if (!is_string($prompt)) throw new InvalidArgumentException('拆分描述格式不正确');
        $prompt = trim($prompt);
        $length = mb_strlen($prompt);
        if ($length > $profile['prompt_max']) throw new InvalidArgumentException('拆分描述最多 '.$profile['prompt_max'].' 字');
        if ($length > 0 && $length < $profile['prompt_min']) throw new InvalidArgumentException('拆分描述请至少输入 '.$profile['prompt_min'].' 字，或留空自动识别');
        $size = $params['size'] ?? array_key_first($sizes);
        if (!is_string($size) || !isset($sizes[$size])) throw new InvalidArgumentException('所选分辨率当前不可用');
        $format = $params['output_format'] ?? 'png';
        if (!in_array($format, ['png', 'jpeg'], true)) throw new InvalidArgumentException('底图格式须为 PNG 或 JPEG');
        $providerParams = ['size'=>$size, 'output_format'=>$format];
        if ($profile['configurable_output']) {
            $limit = filter_var($params['max_images'] ?? 30, FILTER_VALIDATE_INT, ['options'=>['min_range'=>2, 'max_range'=>$profile['max_output']]]);
            if ($limit === false) throw new InvalidArgumentException('最大输出张数须为 2 至 100 的整数');
            $recognize = filter_var($params['text_recognition'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($recognize === null) throw new InvalidArgumentException('文字识别设置无效');
            $providerParams += ['max_images'=>$limit, 'text_recognition'=>$recognize, 'nsfw_checker'=>true];
        }
        $imageLimits = $profile['configurable_output'] ? null : [
            'max_mb'=>$profile['image_max_mb'], 'mimes'=>$profile['image_formats'],
            'min_pixels'=>262144, 'max_pixels'=>36000000, 'max_ratio'=>16,
        ];
        $image = StudioMediaInputService::validateImages($tenant, $user, [['uri'=>$source]], 1, $imageLimits)[0];

        return [
            'prompt' => $prompt,
            'reference_images' => [$image['uri']],
            'style' => Market::STYLE,
            'quantity' => 1,
            'model_id' => 'market_image_model:' . $sizes[$size]['market_product_id'],
            'market_product_id' => $sizes[$size]['market_product_id'],
            'market_sku_id' => $sizes[$size]['market_sku_id'],
            'layer_model_code' => $code,
            'provider_params' => $providerParams,
        ];
    }

    public static function tasks(int $tenant, int $user, array $params): array
    {
        $params = array_intersect_key($params, array_flip(['page_no', 'page_size', 'status', 'task_id', 'user_keyword']));
        $params['style'] = Market::STYLE;
        $data = AigcImageService::taskLists($tenant, $user, $params);
        if (!isset($data['lists'])) return array_map(self::withTaskModel(...), $data);
        $data['lists'] = array_map(self::withTaskModel(...), $data['lists']);
        return $data;
    }

    public static function detail(int $tenant, int $user, int $id): array
    {
        $task = AigcImageService::taskDetail($tenant, $id, $user);
        if (($task['style'] ?? '') !== Market::STYLE) throw new InvalidArgumentException('图层分离任务不存在');
        return self::withTaskModel($task);
    }

    private static function withTaskModel(array $task): array
    {
        $code = (string)($task['provider_params_json']['_layer_model_code'] ?? Market::MODEL);
        $model = Market::profile($code);
        $task['layer_model'] = ['code'=>$code, 'name'=>$model['name'] ?? $code];
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
