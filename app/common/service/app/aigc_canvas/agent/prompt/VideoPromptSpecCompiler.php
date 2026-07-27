<?php

namespace app\common\service\app\aigc_canvas\agent\prompt;

/** Deterministic compiler for time-based video prompts. */
final class VideoPromptSpecCompiler
{
    public const VERSION = 'video-prompt-v1';

    public static function compile(array $input): array
    {
        $mode = self::mode($input);
        $context = self::array($input['creative_context'] ?? []);
        $delivery = self::array($input['delivery'] ?? $input['section'] ?? []);
        $request = self::text((string)($input['user_request'] ?? $input['creative_intent'] ?? $input['prompt'] ?? ''), 1800);
        $identity = self::strings($context['product_identity'] ?? $input['product_identity'] ?? []);
        $facts = self::evidence($context['evidence_catalog'] ?? []);
        $direction = self::strings(array_merge(
            self::array($input['visual_direction'] ?? []),
            self::array($input['video_direction'] ?? []),
            self::array($input['shot_direction'] ?? []),
            self::array($delivery['visual_direction'] ?? [])
        ));
        $spec = [
            'mode' => $mode,
            'language' => (string)($input['prompt_language'] ?? 'zh-CN') === 'en-US' ? 'en-US' : 'zh-CN',
            'user_request' => $request,
            'delivery_type' => self::text((string)($delivery['type'] ?? $input['delivery_type'] ?? 'video'), 80),
            'ratio' => trim((string)($input['ratio'] ?? $delivery['ratio'] ?? '')),
            'duration' => max(0, (int)($input['duration'] ?? $delivery['duration'] ?? 0)),
            'product_identity' => $identity,
            'evidence' => $facts,
            'temporal_direction' => $direction,
            'camera_movement' => self::text((string)($input['camera_movement'] ?? $delivery['camera_movement'] ?? ''), 180),
            'motion' => self::text((string)($input['motion'] ?? $delivery['motion'] ?? ''), 260),
            'continuity' => self::strings($input['continuity_constraints'] ?? $delivery['continuity_constraints'] ?? []),
            'constraints' => self::strings($input['constraints'] ?? $delivery['constraints'] ?? []),
            'reference_assets' => self::referenceSummary($input),
        ];
        $spec['constraints'] = self::constraints($spec);
        $prompt = self::render($spec);
        return [
            'creative_spec_json' => [
                'product_identity' => $spec['product_identity'],
                'evidence_catalog' => $spec['evidence'],
                'temporal_direction' => $spec['temporal_direction'],
                'continuity' => $spec['continuity'],
            ],
            'prompt_spec_json' => $spec,
            'compiled_prompt' => $prompt,
            'compiler_version' => self::VERSION,
            'prompt_hash' => hash('sha256', $prompt),
            'evidence_ids' => array_values(array_filter(array_map(static fn(array $fact): string => (string)($fact['fact_id'] ?? ''), $facts))),
            'claim_ids' => [],
        ];
    }

    public static function reuseSnapshot(array $input): array
    {
        self::assertSubmission($input);
        return [
            'creative_spec_json' => self::array($input['creative_spec_json'] ?? []),
            'prompt_spec_json' => self::array($input['prompt_spec_json'] ?? []),
            'compiled_prompt' => (string)$input['prompt'],
            'compiler_version' => self::VERSION,
            'prompt_hash' => (string)$input['prompt_hash'],
            'evidence_ids' => array_values((array)($input['evidence_ids'] ?? [])),
            'claim_ids' => [],
        ];
    }

    public static function assertSubmission(array $input): void
    {
        $prompt = trim((string)($input['prompt'] ?? ''));
        if ($prompt === '' || !is_array($input['prompt_spec_json'] ?? null) || empty($input['prompt_spec_json'])
            || (string)($input['compiler_version'] ?? '') !== self::VERSION
            || !hash_equals((string)($input['prompt_hash'] ?? ''), hash('sha256', $prompt))) {
            throw new \InvalidArgumentException('Video prompt snapshot is invalid');
        }
    }

    private static function render(array $spec): string
    {
        $parts = [];
        $task = (string)$spec['user_request'];
        $meta = [];
        if ((string)$spec['ratio'] !== '') $meta[] = '画面比例 ' . $spec['ratio'];
        if ((int)$spec['duration'] > 0) $meta[] = '时长约 ' . $spec['duration'] . ' 秒';
        if ($task !== '') $parts[] = self::sentence($task . ($meta === [] ? '' : '，' . implode('，', $meta)));
        if ($spec['product_identity'] !== []) $parts[] = self::sentence('保持主体身份一致：' . implode('、', $spec['product_identity']));
        $observations = array_values(array_filter(array_map(static fn(array $item): string => trim((string)($item['observation'] ?? '')), $spec['evidence'])));
        if ($observations !== []) $parts[] = self::sentence('保留参考素材中可见的特征：' . implode('；', $observations));
        $temporal = $spec['temporal_direction'];
        if ((string)$spec['motion'] !== '') $temporal[] = '动作：' . $spec['motion'];
        if ((string)$spec['camera_movement'] !== '') $temporal[] = '镜头：' . $spec['camera_movement'];
        if ($temporal !== []) $parts[] = self::sentence(implode('；', $temporal));
        if ($spec['continuity'] !== []) $parts[] = self::sentence('连续性要求：' . implode('；', $spec['continuity']));
        if ($spec['constraints'] !== []) $parts[] = self::sentence(implode('；', $spec['constraints']));
        return trim(implode("\n\n", $parts));
    }

    private static function constraints(array $spec): array
    {
        $constraints = $spec['constraints'];
        if ($spec['product_identity'] !== [] || $spec['evidence'] !== [] || preg_match('/(?:ecommerce|product|商品|电商|详情页|主图|卖点)/u', (string)$spec['delivery_type']) === 1) {
            $constraints[] = '不虚构价格、认证、规格参数、性能、医疗或比较性宣传';
        }
        return array_values(array_unique($constraints));
    }

    private static function mode(array $input): string
    {
        $mode = (string)($input['video_prompt_mode'] ?? $input['prompt_mode'] ?? 'direct');
        return in_array($mode, ['direct', 'planned', 'edit', 'retry'], true) ? $mode : 'direct';
    }

    private static function evidence($value): array
    {
        $facts = [];
        foreach (self::array($value) as $item) {
            if (!is_array($item) || trim((string)($item['observation'] ?? '')) === '') continue;
            $facts[(string)($item['fact_id'] ?? sha1((string)$item['observation']))] = $item;
        }
        return array_values($facts);
    }

    private static function referenceSummary(array $input): array
    {
        $items = [];
        foreach ((array)($input['reference_assets'] ?? []) as $asset) {
            if (!is_array($asset)) continue;
            $items[] = ['type' => (string)($asset['type'] ?? 'image'), 'role' => (string)($asset['role'] ?? 'reference')];
        }
        return $items;
    }

    private static function array($value): array { return is_array($value) ? $value : []; }
    private static function text(string $value, int $limit): string { return mb_substr(trim($value), 0, $limit, 'UTF-8'); }
    private static function strings($value): array
    {
        $values = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($values as $value) if (is_scalar($value) && trim((string)$value) !== '') $result[] = mb_substr(trim((string)$value), 0, 300, 'UTF-8');
        return array_values(array_unique($result));
    }
    private static function sentence(string $value): string { return rtrim(trim($value), "。；;，,") . '。'; }
}
