<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Derived production instructions never own dialogue. Capability changes only recompile fragments. */
final class ProductionCompiler
{
    public const VERSION = 1;

    public static function compile(array $document, array $plan, array $capability, array $timings, array $assets, string $style): array
    {
        AudioHandoff::assertIntegrity($document, $plan);
        $capabilityVersion = ContentDocument::text($capability['version'] ?? null, 'capability.version');
        $durations = $capability['durations'] ?? [];
        if (!is_array($durations) || !$durations) throw new RuntimeException('制作模型未提供经过验证的片段时长', 422);
        foreach ($durations as $duration) if (!is_numeric($duration) || !is_finite((float)$duration) || (float)$duration <= 0) throw new RuntimeException('制作时长能力无效', 422);
        $durations = array_values(array_unique(array_map('floatval', $durations))); sort($durations);
        if (($capability['audio_mode'] ?? '') !== 'postproduction') throw new RuntimeException('当前编译器需要独立配音合成；原生对白片段须完成适配后使用', 422);
        $maximum = max($durations); $units = ContentDocument::units($document); $fragments = [];
        foreach ($plan['shots'] as $shot) {
            $timing = $timings[$shot['shot_id']] ?? [];
            $seconds = $timing['actual_seconds'] ?? $timing['maximum_seconds'] ?? null;
            if (!is_numeric($seconds) || !is_finite((float)$seconds) || $seconds <= 0 || empty($timing['version'])) throw new RuntimeException('分镜缺少有效时长测量或估算版本', 422);
            $count = (int)ceil($seconds / $maximum);
            // This is a compiler resource cap, not a narrative shot-count rule.
            if ($count > 10000) throw new RuntimeException('制作片段超出单次编译容量', 413);
            $bindings = [];
            foreach (array_merge($shot['entity_ids'], [$shot['location_id']]) as $entity) {
                $asset = $assets[$entity] ?? null;
                if (!$asset || empty($asset['asset_id']) || empty($asset['state_version']) || empty($asset['reference_hash']) || ($asset['style'] ?? '') !== $style) {
                    throw new RuntimeException('当前镜头的主体或场景素材尚未就绪', 409);
                }
                $bindings[] = ['entity_id' => $entity, 'asset_id' => $asset['asset_id'], 'state_version' => $asset['state_version'],
                    'reference_hash' => $asset['reference_hash'], 'style' => $style];
            }
            for ($index = 0; $index < $count; $index++) {
                $from = $index * $maximum; $usable = min($maximum, $seconds - $from);
                $requested = $maximum;
                foreach ($durations as $duration) if ($duration >= $usable) { $requested = $duration; break; }
                $fragment = ['shot_id' => $shot['shot_id'], 'shot_revision_id' => $plan['revision_id'],
                    'shot_hash' => $plan['content_hash'], 'document_revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash'],
                    'unit_refs' => $shot['unit_refs'], 'fragment_index' => $index, 'timeline_start_seconds' => $from,
                    'usable_seconds' => $usable, 'requested_seconds' => $requested, 'timing_version' => $timing['version'],
                    'capability_version' => $capabilityVersion, 'audio_mode' => 'postproduction', 'assets' => $bindings,
                    'prompt_version' => self::VERSION, 'image_prompt' => trim($style . "\n" . $shot['visual_description'] . "\n" . $shot['composition']),
                    'video_prompt' => trim($style . "\n" . $shot['visual_description'] . "\n" . $shot['camera_movement'])
                        . "\n连续画面的第" . ($index + 1) . '/' . $count . "段，仅生成画面；对白由独立声音轨合成，不重复表演整段动作。"];
                $fragment['input_hash'] = ContentDocument::hash($fragment);
                $fragment['fragment_id'] = 'fragment_' . substr($fragment['input_hash'], 0, 24);
                $fragments[] = $fragment;
            }
        }
        $audio = AudioHandoff::segments(['document' => $document, 'shot_plan' => $plan, 'coverage_report' => ['status' => 'ready']]);
        return ['compiler_version' => self::VERSION, 'document_hash' => $document['content_hash'], 'shot_hash' => $plan['content_hash'],
            'fragments' => $fragments, 'audio_segments' => $audio, 'status' => 'ready_to_dispatch'];
    }

    /** Use before dispatch; retain old artifacts, reject new tasks with stale bindings. */
    public static function assertCurrent(array $fragment, array $document, array $plan, array $assets): void
    {
        AudioHandoff::assertIntegrity($document, $plan);
        if (($fragment['document_hash'] ?? '') !== $document['content_hash'] || ($fragment['shot_hash'] ?? '') !== $plan['content_hash']) throw new RuntimeException('制作提示词已过期', 409);
        $signed = $fragment; unset($signed['input_hash'], $signed['fragment_id']);
        if (($fragment['input_hash'] ?? '') !== ContentDocument::hash($signed)
            || ($fragment['fragment_id'] ?? '') !== 'fragment_' . substr((string)$fragment['input_hash'], 0, 24)) throw new RuntimeException('制作输入签名不符', 409);
        foreach ($fragment['assets'] as $binding) {
            $current = $assets[$binding['entity_id']] ?? [];
            foreach (['asset_id', 'state_version', 'reference_hash', 'style'] as $field) if (($current[$field] ?? null) !== $binding[$field]) throw new RuntimeException('制作素材版本已变化', 409);
        }
    }
}
