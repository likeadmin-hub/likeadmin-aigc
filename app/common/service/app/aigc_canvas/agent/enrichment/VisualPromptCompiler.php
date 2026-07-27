<?php

namespace app\common\service\app\aigc_canvas\agent\enrichment;

/**
 * Extracts reusable visual direction from reference analysis.
 *
 * This class deliberately does not build a provider-ready prompt. Image Prompt
 * rendering is owned exclusively by PromptSpecCompiler.
 */
final class VisualPromptCompiler
{
    /** @return array<string, mixed> */
    public static function compile(array $insights, string $request, array $policy = [], array $copyPlan = []): array
    {
        $facts = [];
        $styles = [];
        $composition = [];
        foreach ($insights as $insight) {
            $facts = array_merge($facts, (array)($insight['visible_facts'] ?? []));
            $styles = array_merge($styles, (array)($insight['visual_style'] ?? []));
            if ($composition === [] && !empty($insight['composition'])) $composition = (array)$insight['composition'];
        }
        $facts = array_slice(array_values(array_unique(array_filter(array_map('strval', $facts)))), 0, 6);
        $styles = array_slice(array_values(array_unique(array_filter(array_map('strval', $styles)))), 0, 4);
        $subjectPosition = (string)($composition['subject_position'] ?? 'center');
        $safeArea = (string)($composition['text_safe_area'] ?? 'right');
        $allowRenderedCopy = !empty($policy['render_copy_in_image']) || self::requestsRenderedCopy($request);
        $visualSystem = $styles;
        if ($composition !== []) {
            $visualSystem[] = '主体位置：' . $subjectPosition;
            $visualSystem[] = '文字安全区：' . $safeArea;
        }
        if ($visualSystem === []) {
            $visualSystem[] = '保持参考主体清晰可辨，画面简洁克制';
        }
        if ($allowRenderedCopy) {
            $copy = array_values(array_filter([
                trim((string)($copyPlan['headline'] ?? '')),
                trim((string)($copyPlan['subheadline'] ?? '')),
                ...array_slice(array_map('strval', (array)($copyPlan['selling_points'] ?? [])), 0, 3),
            ]));
            $visualSystem[] = '电商详情页版式，保留清晰可读的文字区域';
        }
        return [
            'visible_facts' => $facts,
            'visual_system' => array_values(array_unique($visualSystem)),
            'text_safe_area' => $safeArea,
            'rendered_copy_policy' => $allowRenderedCopy ? 'copy_in_image' : 'semantic_only',
        ];
    }

    private static function requestsRenderedCopy(string $request): bool
    {
        return preg_match('/文字|文案|标题|副标题|口号|slogan|卖点|价格|参数|字幕|caption|headline|copy|text/u', $request) === 1;
    }
}
