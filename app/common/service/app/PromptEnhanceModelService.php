<?php
namespace app\common\service\app;

use app\common\model\app\aigc_image\AigcImageConfig;
use app\common\model\app\aigc_video\AigcVideoConfig;
use app\common\service\power\MarketTextModelRuntimeService;
use Exception;

/** Tenant-owned selection, independent of the user's chat or generation model. */
class PromptEnhanceModelService
{
    public static function options(int $tenantId): array
    {
        return array_map(static function (array $model): array {
            return ['id' => (string)$model['id'], 'name' => (string)$model['name'],
                'model_code' => (string)$model['model_code']];
        }, MarketTextModelRuntimeService::textOptions($tenantId));
    }

    public static function normalizeSelection($value): string
    {
        if ($value === null || $value === '') return '';
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value)) {
            throw new Exception('请选择有效的提示词增强模型');
        }
        return (string)$value;
    }

    public static function validateConfig(int $tenantId, array $config): array
    {
        if (array_key_exists('prompt_enhance_system_prompt', $config)) {
            $prompt = $config['prompt_enhance_system_prompt'];
            if (!is_string($prompt) || mb_strlen(trim($prompt)) > 12000) throw new Exception('增强提示词指令不能超过12000字');
            $config['prompt_enhance_system_prompt'] = trim($prompt);
        }
        if (!array_key_exists('prompt_enhance_model_id', $config)) return $config;
        $selection = self::normalizeSelection($config['prompt_enhance_model_id']);
        if ($selection !== '') MarketTextModelRuntimeService::resolveModel($tenantId, $selection);
        $config['prompt_enhance_model_id'] = $selection;
        return $config;
    }

    public static function describe(int $tenantId, array $config): array
    {
        $selection = (string)($config['prompt_enhance_model_id'] ?? '');
        $info = ['id' => $selection, 'automatic' => $selection === '', 'available' => false,
            'name' => '', 'model_code' => ''];
        try {
            $model = MarketTextModelRuntimeService::resolveModel($tenantId, $selection);
            $info['available'] = true;
            $info['name'] = (string)$model['name'];
            $info['model_code'] = (string)$model['model_code'];
        } catch (Exception $e) {
            // An unavailable enhancement model must not block image/video creation.
        }
        return $info;
    }

    public static function requestParams(int $tenantId, string $appCode, string $defaultPrompt): array
    {
        if (!in_array($appCode, ['aigc_image', 'aigc_video'], true)) throw new Exception('不支持的提示词增强应用');
        $class = $appCode === 'aigc_image' ? AigcImageConfig::class : AigcVideoConfig::class;
        $row = $class::where('tenant_id', $tenantId)->findOrEmpty();
        $config = $row->isEmpty() ? [] : (array)$row['config_json'];
        $selection = self::normalizeSelection($config['prompt_enhance_model_id'] ?? '');
        return ['model_code' => $selection, '_disable_model_fallback' => $selection !== '',
            'system_prompt' => trim((string)($config['prompt_enhance_system_prompt'] ?? '')) ?: $defaultPrompt];
    }

    public static function resultModel(array $result): array
    {
        return ['model_code' => (string)($result['model_code'] ?? ''),
            'model_name' => (string)($result['model_name'] ?? $result['model_code'] ?? '')];
    }
}
