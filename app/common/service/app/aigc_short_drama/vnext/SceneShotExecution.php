<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Partition visual planning only. Full-episode semantic review remains mandatory. */
final class SceneShotExecution
{
    public const VERSION = 1;
    private const WINDOW_INSTRUCTION = '本次只规划当前连续场次/正文单元窗口，不能续写其他窗口。保留输入中全局ref短别名，不重新编号。';

    public static function run(array $document, callable $call, string $key, callable $checkpoint, array $execution, int $projection, bool &$windowed): array
    {
        $plan = ($execution['plan'])($key, $document, null);
        if ($plan === null) {
            $plan = self::partition($document, $execution['capacity'], $projection, false);
            if (count($plan['windows']) === 1) {
                try {
                    return $call($key, ['document' => $document], GenerationPipeline::shotInstruction(),
                        static fn($reply) => ShotCompiler::compile($document, $reply, $document['revision_id'] . '_shots'));
                } catch (RuntimeException $error) {
                    // Only a received, explicitly truncated reply permits changing the
                    // execution plan. Transport and preflight failures must not do so.
                    if (!$error instanceof \app\common\service\app\aigc_short_drama\ShortDramaOutputLimitException) throw $error;
                    $plan = self::partition($document, $execution['capacity'], $projection, true);
                }
            }
            $plan = ($execution['plan'])($key, $document, $plan);
        }
        self::assertPlan($document, $plan);
        $windowed = true; $compiled = [];
        foreach ($plan['windows'] as $index => $ids) {
            $view = self::view($document, $ids);
            $partial = $call($key . '_window_' . ($index + 1), self::data($view, $index + 1, count($plan['windows'])),
                GenerationPipeline::shotInstruction() . self::WINDOW_INSTRUCTION,
                static fn($reply) => ShotCompiler::compile($view, $reply, $document['revision_id'] . '_window_' . ($index + 1)));
            $compiled = array_merge($compiled, $partial['shots']);
            $checkpoint(['document' => $document, 'shot_windows' => ['plan_hash' => $plan['plan_hash'],
                'completed' => $index + 1, 'total' => count($plan['windows']), 'shots' => $compiled]]);
        }
        $refs = array_column(ContentDocument::units($document), 'ref', 'unit_id'); $shots = [];
        foreach ($compiled as $shot) {
            $links = [];
            foreach ($shot['unit_refs'] as $link) $links[] = ['unit' => $refs[$link['unit_id']], 'start' => $link['start'], 'end' => $link['end']];
            $shots[] = ['visual_description' => $shot['visual_description'], 'composition' => $shot['composition'],
                'camera_movement' => $shot['camera_movement'], 'refs' => $links];
        }
        // Recompile once against the full document: global order, complete coverage,
        // stable final shot IDs and one audio segment per canonical dialogue unit.
        return ShotCompiler::compile($document, ['shots' => $shots], $document['revision_id'] . '_shots');
    }

    public static function partition(array $document, callable $capacity, int $projection, bool $afterTruncation): array
    {
        $ids = array_keys(ContentDocument::units($document));
        if ($afterTruncation && count($ids) < 2) throw new RuntimeException('单个完整正文单元仍超出输出容量，原文和收据保留', 413);
        $limit = $afterTruncation ? max(1, (int)ceil(count($ids) / 2)) : count($ids);
        $windows = []; $current = [];
        foreach ($ids as $id) {
            $trial = array_merge($current, [$id]);
            if (count($trial) > $limit || !self::fits(self::view($document, $trial), $capacity, $projection)) {
                if (!$current) throw new RuntimeException('单个完整正文单元无法容纳，不能截断对白或人物要求', 413);
                $windows[] = $current; $current = [$id];
                if (!self::fits(self::view($document, $current), $capacity, $projection)) throw new RuntimeException('单个完整正文单元无法容纳，已完成内容保留', 413);
            } else $current = $trial;
        }
        if ($current) $windows[] = $current;
        if (count($windows) > 32) throw new RuntimeException('所需分镜窗口超过当前执行上限，完整正文保留', 413);
        $plan = ['schema_version' => self::VERSION, 'document_hash' => $document['content_hash'], 'windows' => $windows];
        $plan['plan_hash'] = ContentDocument::hash($plan);
        return $plan;
    }
    private static function fits(array $view, callable $capacity, int $projection): bool
    {
        try {
            // Reserve enough output for references and visual descriptions. This is
            // planning guidance, never a token billing estimate or permission to trim.
            $budget = $capacity(GenerationPipeline::messages(self::data($view, 999, 999), GenerationPipeline::shotInstruction() . self::WINDOW_INSTRUCTION, $projection));
            $estimate = 256 + count(ContentDocument::units($view)) * 256 + count($view['scenes']) * 128;
            return $budget['max_tokens'] >= $estimate;
        } catch (RuntimeException $error) { if ($error->getCode() !== 413) throw $error; return false; }
    }
    private static function data(array $view, int $number, int $total): array
    { return ['document' => $view, 'window' => ['number' => $number, 'total' => $total, 'scope' => 'continuous_units']]; }
    public static function view(array $document, array $ids): array
    {
        $selected = array_fill_keys($ids, true); $scenes = [];
        foreach ($document['scenes'] as $scene) {
            $scene['units'] = array_values(array_filter($scene['units'], static fn($unit) => isset($selected[$unit['unit_id']])));
            if ($scene['units']) $scenes[] = $scene;
        }
        $document['scenes'] = $scenes;
        // This is a view of the full canonical revision, not a new canonical hash.
        return $document;
    }
    public static function assertPlan(array $document, array $plan): void
    {
        $unsigned = $plan; unset($unsigned['plan_hash']);
        if (($plan['schema_version'] ?? 0) !== self::VERSION || ($plan['document_hash'] ?? '') !== $document['content_hash']
            || ($plan['plan_hash'] ?? '') !== ContentDocument::hash($unsigned) || empty($plan['windows']) || count($plan['windows']) > 32) throw new RuntimeException('分场执行计划已失效', 409);
        $all = [];
        foreach ($plan['windows'] as $window) {
            if (!is_array($window) || !$window || array_values($window) !== $window) throw new RuntimeException('分场执行计划不完整', 409);
            $all = array_merge($all, $window);
        }
        if ($all !== array_keys(ContentDocument::units($document))) throw new RuntimeException('分场执行计划遗漏、重复或重排正文', 409);
    }
}
