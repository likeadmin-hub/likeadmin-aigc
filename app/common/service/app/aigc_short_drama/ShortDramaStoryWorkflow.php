<?php

namespace app\common\service\app\aigc_short_drama;

use InvalidArgumentException;

/** Versioned contracts for the new multi-episode planning workspace. */
final class ShortDramaStoryWorkflow
{
    public const VARIANT = 'story_outline_v2';

    public static function enabled(array $request): bool
    {
        return ($request['workflow_variant'] ?? '') === self::VARIANT
            && !empty($request['multi_episode']) && (int)($request['episode_count'] ?? 0) >= 2;
    }

    public static function nextStage(string $stage, bool $advance): string
    {
        if (!in_array($stage, ['story', 'episodes'], true)) {
            throw new InvalidArgumentException('当前阶段不支持故事设定编辑');
        }
        if (!$advance) return $stage;
        if ($stage !== 'story') throw new InvalidArgumentException('请通过确认大纲开始分集生成');
        return 'episodes';
    }

    public static function unconfirmedStory(array $request): bool
    {
        return self::enabled($request) && ($request['multi_episode_stage'] ?? '') === 'story';
    }

    /** UI planning metadata is not a creative constraint before confirmation. */
    public static function withoutEpisodeAllocation(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['episode_count', 'episode_total_count', 'episode_batch_start', 'episode_batch_end', 'episode_batch_context', 'episode_summaries'], true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = self::withoutEpisodeAllocation($value);
            }
        }
        return $data;
    }

    public static function isCreativeText($value): bool
    {
        return is_string($value) && trim($value) !== ''
            && !preg_match('/^(?:待补充|待生成|同上|略|省略|暂无|TBD|TODO|N\/A|\.{3}|…+)$/iu', trim($value));
    }

    public static function scopeInstruction(array $request): string
    {
        if (($request['workflow_variant'] ?? '') !== self::VARIANT) return '';
        $count = (int)$request['episode_count'];
        $total = (int)($request['episode_total_count'] ?? $count);
        if (($request['multi_episode_stage'] ?? '') === 'story') {
            return '当前仅生成尚未确认的故事设定，集数尚未确定。series_arc 按开端、发展、转折、高潮和结局描述全剧主线，不按集数分配剧情，不出现第几集或集号范围。用户确认故事设定后才按最终集数生成分集大纲；本次 episodes、storyboard 为空，不生成分集摘要或制作分镜。';
        }
        $start = (int)($request['episode_batch_start'] ?? 1);
        $end = $start + $count - 1;
        return "执行范围（系统参数）：全剧总集数={$total}，本次只生成全剧第 {$start}–{$end} 集，共 {$count} 集。"
            . "前文 requested episode count / exactly {$count} 指本批数量，不是全剧长度；局部编号 1 对应全剧第 {$start} 集。"
            . "全剧最终集是第 {$total} 集，批次末尾不等于全剧结尾；当前范围之外的情节只作承接背景，不提前生成或复制。"
            . "当前仅为分集大纲，不是正文制作；每集仅返回 episode_number、title、story_outline、conflict_point、ending_hook，storyboard 为空。不要返回 script_lines、scenes、shots 或媒体提示词。";
    }

    /** Confirmation is stricter than draft saving; returns field-level errors. */
    public static function issues(array $plan, string $stage, int $count): array
    {
        $issues = [];
        foreach (['title' => '剧名', 'type_judgement' => '题材类型', 'core_theme' => '核心主题', 'story_outline' => '故事梗概'] as $key => $label) {
            if (!is_string($plan[$key] ?? null) || trim($plan[$key]) === '') {
                $issues[] = ['path' => $key, 'message' => '请补充' . $label];
            }
        }
        foreach (['subjects' => '人物', 'locations' => '场景'] as $key => $label) {
            $items = $plan[$key] ?? [];
            if (!is_array($items) || !$items) {
                $issues[] = ['path' => $key, 'message' => '请补充' . $label];
                continue;
            }
            $ids = [];
            foreach ($items as $index => $item) {
                $id = is_array($item) && is_string($item['id'] ?? null) ? trim($item['id']) : '';
                if ($id === '' || isset($ids[$id]) || !is_string($item['name'] ?? null) || trim($item['name']) === '') {
                    $issues[] = ['path' => $key . '.' . $index, 'message' => $label . '名称或标识无效'];
                }
                $ids[$id] = true;
            }
        }
        if (!empty($plan['storyboard'])) $issues[] = ['path' => 'storyboard', 'message' => '设定和大纲阶段不能包含制作分镜'];
        if ($stage === 'story') {
            if (!empty($plan['episodes'])) $issues[] = ['path' => 'episodes', 'message' => '确认故事设定后才能生成分集大纲'];
            foreach (['audience' => '目标受众', 'core_hook' => '核心看点', 'logline' => '一句话故事', 'series_arc' => '全剧主线'] as $key => $label) {
                if (!is_string($plan['series_bible'][$key] ?? null) || trim($plan['series_bible'][$key]) === '') $issues[] = ['path' => 'series_bible.' . $key, 'message' => '请补充' . $label];
            }
            foreach (['relationships' => '人物关系', 'world_rules' => '世界规则'] as $key => $label) {
                $items = $plan['series_bible'][$key] ?? null;
                if (!is_array($items) || !$items || array_filter($items, static fn($item) => !is_string($item) || trim($item) === '')) $issues[] = ['path' => 'series_bible.' . $key, 'message' => '请补充有效的' . $label];
            }
        } elseif ($stage === 'episodes') {
            $invalidFields = false;
            foreach ((array)($plan['episodes'] ?? []) as $index => $episode) {
                foreach (['title' => '标题', 'story_outline' => '剧情', 'conflict_point' => '冲突点', 'ending_hook' => '结尾内容'] as $key => $label) {
                    if (!is_array($episode) || !self::isCreativeText($episode[$key] ?? null)) {
                        $issues[] = ['path' => 'episodes.' . $index . '.' . $key, 'message' => '第' . ($index + 1) . '集请补充有效的' . $label];
                        $invalidFields = true;
                    }
                }
            }
            if (!$invalidFields) {
                try { ShortDramaEpisodeService::validateOutline($plan, $count); }
                catch (\Exception $e) { $issues[] = ['path' => 'episodes', 'message' => $e->getMessage()]; }
            }
            $seen = [];
            foreach ((array)($plan['episodes'] ?? []) as $index => $episode) {
                $story = is_array($episode) && is_string($episode['story_outline'] ?? null) ? trim($episode['story_outline']) : '';
                if ($story !== '' && isset($seen[$story])) $issues[] = ['path' => 'episodes.' . $index, 'message' => '第' . ($index + 1) . '集剧情与其他集完全重复'];
                $seen[$story] = true;
            }
        } else {
            $issues[] = ['path' => 'stage', 'message' => '无效的编辑阶段'];
        }
        return $issues;
    }
}
