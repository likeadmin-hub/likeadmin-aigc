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

    public static function workerOwned(array $request): bool
    {
        // Episode production has its own ordered worker and must not race it.
        return empty($request['episode_id']) && (self::enabled($request)
            || ((int)($request['_generation_version'] ?? 0) >= 3 && empty($request['multi_episode'])));
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

    /** Remove premature episode allocation and stale counts in prior drafts. */
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

    /** The current requested scale informs setting, without allocating episodes. */
    public static function storyContext(array $data, array $request): array
    {
        $data = self::withoutEpisodeAllocation($data);
        $data['story_scale'] = [
            'target_episode_count' => min(500, max(2, (int)($request['episode_count'] ?? 3))),
            // Legacy normalization also extracts whole-film duration from prose.
            // Do not relabel that ambiguous field as per-episode or multiply it.
            'target_duration_seconds' => max(0, (int)($request['target_duration_seconds'] ?? 0)),
        ];
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
            $scale = self::storyContext([], $request)['story_scale'];
            $count = $scale['target_episode_count'];
            $duration = $scale['target_duration_seconds'];
            $durationHint = $duration > 0
                ? "任务时长参数={$duration}秒；按用户原文判断它是单集还是全剧时长。明确为单集时长才结合目标集数估算全剧容量；明确为全剧总时长则在目标集数内分配，含义未明确时不得默认按单集乘以集数。"
                : '未设置单集时长；如用户明确提供单集或全剧时长，按其原意结合目标集数评估容量，区分单集与全剧时长；否则保持时长开放，不擅自假定固定时长。';
            return "当前仅生成待确认的故事设定。用户目标集数={$count}集，这是故事容量依据，确认前可调整。" . $durationHint
                . '据目标集数、时长、题材和用户故事共同规划主线长度、支线深度、阶段性冲突及人物成长；少集数突出集中冲突和完整收束，多集数需要可持续推进的矛盾、递进的阶段目标与伏笔回收，避免重复冲突和灌水。'
                . '主体与场景数量由叙事需要决定，不按集数线性增加，不设置每集新增配额。尊重用户指定阵容、场景和已绑定素材，核心主体的身份、关系及素材 ID 保持稳定；长篇按需要规划阶段性配角、对手和关系演变。'
                . '场景优先规划可复用的主要地点及其叙事用途，在全剧主线中说明后续阶段拓展方向；不预先穷举全部逐集临时人物、道具或地点。把已明确的阶段性角色定位与成长写入 subjects 的 role、arc，把场景用途与出现阶段写入 locations 的 description，便于后续继承。'
                . 'series_arc 按开端、发展、转折、高潮和结局描述足以支撑目标容量的全剧主线，不按集数分配剧情，不出现第几集或集号范围。用户确认故事设定后才按最终集数生成分集大纲；本次 episodes、storyboard 为空，不生成分集摘要或制作分镜。';
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
