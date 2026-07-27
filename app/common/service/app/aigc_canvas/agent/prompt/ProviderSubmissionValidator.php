<?php

namespace app\common\service\app\aigc_canvas\agent\prompt;

use app\common\service\app\aigc_canvas\agent\model\CanvasModelRouterService;

/** Deterministic, no-charge checks before a power-market image request is submitted. */
final class ProviderSubmissionValidator
{
    public static function validateImage(int $tenantId, array $input): array
    {
        try {
            PromptSpecCompiler::assertSubmission($input);
        } catch (\InvalidArgumentException $e) {
            self::reject('prompt_invalid', $e->getMessage(), $input);
        }
        $references = self::references($input);
        foreach ($references as $url) {
            if (!self::isReferenceUrl($url)) {
                self::reject('invalid_reference_url', '参考图地址无效，无法提交生成任务。', $input);
            }
        }
        $overview = CanvasModelRouterService::marketOverview($tenantId);
        $option = self::option((array)($overview['image']['options'] ?? []), $input);
        if ($option !== []) {
            $limit = max(0, (int)($option['max_reference_images'] ?? 0));
            if (count($references) > $limit) {
                self::reject(
                    $limit > 0 ? 'reference_limit_exceeded' : 'reference_not_supported',
                    $limit > 0 ? '当前图片模型最多支持 ' . $limit . ' 张参考图。' : '当前图片模型不支持参考图。',
                    $input
                );
            }
            if ($references !== [] && trim((string)($option['reference_input_field'] ?? '')) === '') {
                self::reject('reference_field_unsupported', '当前图片模型没有声明可用的参考图参数字段。', $input);
            }
            $ratios = self::ratios($option, $input);
            $ratio = trim((string)($input['ratio'] ?? ''));
            if ($ratio !== '' && $ratios !== [] && !in_array($ratio, $ratios, true)) {
                self::reject('ratio_not_supported', '当前图片模型不支持所选比例 ' . $ratio . '。', $input);
            }
        }
        return [
            'stage' => 'preflight',
            'status' => 'passed',
            'reference_image_count' => count($references),
            'model' => (string)($input['channel'] ?? $input['model_id'] ?? ''),
            'ratio' => (string)($input['ratio'] ?? ''),
            'reference_input_field' => (string)($option['reference_input_field'] ?? ''),
            'submitted_prompt_hash' => (string)($input['prompt_hash'] ?? ''),
        ];
    }

    private static function option(array $options, array $input): array
    {
        $values = array_filter([
            (string)($input['channel'] ?? ''), (string)($input['model_id'] ?? ''), (string)($input['market_product_id'] ?? '')
        ]);
        foreach ($options as $option) {
            if (!is_array($option)) continue;
            foreach (['id', 'value', 'code', 'market_product_id'] as $key) {
                if (in_array((string)($option[$key] ?? ''), $values, true)) return $option;
            }
        }
        return [];
    }

    private static function ratios(array $option, array $input = []): array
    {
        $selectedSkuId = (int)($input['market_sku_id'] ?? $input['sku_id'] ?? 0);
        if ($selectedSkuId > 0) {
            foreach ((array)($option['skus'] ?? []) as $sku) {
                if (!is_array($sku) || (int)($sku['market_sku_id'] ?? 0) !== $selectedSkuId) {
                    continue;
                }
                return self::ratioValues((array)($sku['ratio_options'] ?? []));
            }
        }
        $ratios = [];
        foreach ((array)($option['ratio_options'] ?? []) as $ratio) $ratios[] = is_array($ratio) ? (string)($ratio['value'] ?? $ratio['ratio'] ?? '') : (string)$ratio;
        foreach ((array)($option['skus'] ?? []) as $sku) foreach ((array)($sku['ratio_options'] ?? []) as $ratio) $ratios[] = is_array($ratio) ? (string)($ratio['value'] ?? $ratio['ratio'] ?? '') : (string)$ratio;
        return array_values(array_unique(array_filter(array_map('trim', $ratios))));
    }

    private static function ratioValues(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn($ratio): string => is_array($ratio) ? (string)($ratio['value'] ?? $ratio['ratio'] ?? '') : (string)$ratio,
            $values
        ), 'trim')));
    }

    private static function references(array $input): array
    {
        $items = [];
        foreach (['reference_images', 'image_urls', 'urls', 'referenceImages'] as $key) {
            foreach ((array)($input[$key] ?? []) as $item) {
                $url = is_array($item)
                    ? (string)($item['url'] ?? $item['uri'] ?? $item['image_url'] ?? '')
                    : (string)$item;
                $url = trim($url);
                if ($url !== '') {
                    $items[] = $url;
                }
            }
        }
        return array_values(array_unique($items));
    }

    private static function isReferenceUrl(string $url): bool
    {
        if (str_starts_with($url, 'data:image/')) {
            return true;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return true;
        }
        return preg_match('#^/?(?!.*(?:^|/)\\.\\.(?:/|$))[A-Za-z0-9_./-]+$#', $url) === 1;
    }

    private static function reject(string $code, string $message, array $input): void
    {
        throw new PromptSubmissionException($message, [
            'stage' => 'preflight',
            'provider_error_code' => $code,
            'provider_error_message' => $message,
            'submitted_prompt_hash' => (string)($input['prompt_hash'] ?? ''),
            'model' => (string)($input['channel'] ?? $input['model_id'] ?? ''),
            'ratio' => (string)($input['ratio'] ?? ''),
            'reference_image_count' => count(self::references($input)),
        ]);
    }
}
