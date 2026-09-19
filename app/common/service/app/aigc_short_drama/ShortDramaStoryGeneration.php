<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** New planning stages only. Existing production generation is intentionally not handled here. */
final class ShortDramaStoryGeneration
{
    public static function generate(array $request, array $model, callable $assemble, callable $provider, ?callable $progress = null): array
    {
        $stage = (string)$request['multi_episode_stage'];
        $total = (int)$request['episode_count'];
        $receipts = [];
        $call = static function (string $key, array $messages, int $count, bool $public) use (&$model, &$receipts, $provider): array {
            $budget = ($public || str_starts_with($key, 'roadmap'))
                ? ShortDramaPlanningBudget::stage($messages['system_prompt'] . $messages['content'], $model, $public ? 'story' : 'roadmap')
                : ShortDramaPlanningBudget::calculate($messages['system_prompt'] . $messages['content'], $model, $count, false);
            if ($budget['count'] < $count) throw new RuntimeException('本批超出模型容量', 413);
            if (str_contains($key, '_repair')) {
                $budget['max_tokens'] = ShortDramaPlanningBudget::repairMaxTokens($budget, $count, $public);
            }
            $receipt = $provider($key, $messages, $budget, $model);
            $model = (array)($receipt['model'] ?? $model);
            $receipts[$key] = (array)($receipt['result'] ?? []);
            return ShortDramaStructuredResponse::decode((array)($receipt['result'] ?? []));
        };
        if ($stage === 'story') {
            $messages = $assemble($request);
            try {
                $payload = $call('story', $messages, 1, true);
                $issues = ShortDramaStoryWorkflow::issues($payload, 'story', $total);
            } catch (RuntimeException $error) {
                if (!in_array($error->getCode(), [413, 422], true)) throw $error;
                $issues = [['path' => 'result', 'message' => $error->getMessage()]];
            }
            if ($issues) {
                $messages['content'] .= "\n上次内容未通过校验，请返回完整故事设定。具体缺失：" . json_encode($issues, JSON_UNESCAPED_UNICODE);
                $payload = $call('story_repair', $messages, 1, true);
                $issues = ShortDramaStoryWorkflow::issues($payload, 'story', $total);
            }
            if ($issues) throw new RuntimeException($issues[0]['message'], 422);
        } else {
            $base = (array)($request['confirmed_story_snapshot'] ?? []);
            if (!$base) throw new RuntimeException('请先确认故事设定');
            $payload = $base;
            $payload['episodes'] = [];
            $sourceEpisodes = array_values((array)($request['revision_base_result']['episodes'] ?? []));
            $isRevision = $sourceEpisodes && !empty($request['revision_message']);
            $roadmap = [];
            if ((int)($request['_generation_version'] ?? 0) >= 3) {
                $roadmap = (array)($request['revision_base_result']['series_roadmap'] ?? []);
                if (!$roadmap) {
                    $roadmapInput = $assemble($request);
                    $roadmapInput['system_prompt'] .= "\n当前只做全剧节奏分配，不生成逐集大纲或分镜。返回 {\"segments\":[{\"start\":1,\"end\":5,\"goal\":\"本阶段剧情目标\",\"reveal\":\"本阶段允许揭露的信息\",\"ending\":\"阶段结尾与下一阶段交接\"}]}。阶段集号连续完整覆盖全剧，最多30个阶段，不改变已确认设定，不在非最终阶段提前结束全剧。";
                    $roadmapInput['content'] = json_encode(['total_episodes' => $total, 'confirmed_story' => ShortDramaPlanningContext::lockedStory($base)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    for ($attempt = 0; $attempt < 2; $attempt++) {
                        try {
                            $roadmap = (array)($call('roadmap' . ($attempt ? '_repair' : ''), $roadmapInput, 1, false)['segments'] ?? []);
                            ShortDramaContinuity::assertRoadmap($roadmap, $total);
                            break;
                        } catch (RuntimeException $error) {
                            if ($attempt || !in_array($error->getCode(), [413, 422], true)) throw $error;
                            $roadmapInput['content'] .= "\n上次校验失败：" . $error->getMessage();
                        }
                    }
                }
                ShortDramaContinuity::assertRoadmap($roadmap, $total);
                $payload['series_roadmap'] = $roadmap;
            }
            $targetEpisode = $isRevision && ($request['revision_target']['type'] ?? '') === 'episode' ? (int)$request['revision_target']['id'] : 0;
            if ($targetEpisode && ($targetEpisode < 1 || $targetEpisode > $total || array_column($sourceEpisodes, 'episode_number') !== range(1, $total))) {
                throw new RuntimeException('原大纲集号不完整，无法安全局部修改', 422);
            }
            $expand = function (int $start, int $count) use (&$expand, &$payload, $base, $request, $assemble, $call, $total, $progress, $sourceEpisodes, $isRevision, $roadmap): void {
                $chunk = $request;
                $chunk['episode_count'] = $count;
                $chunk['episode_total_count'] = $total;
                $chunk['episode_batch_start'] = $start;
                $chunk['episode_batch_end'] = $start + $count - 1;
                $chunk['_short_drama_episode_batch'] = true;
                $chunk['series_roadmap'] = $roadmap;
                $chunk['revision_base_result'] = $base;
                $chunk['revision_policy'] = ['mode' => 'outline_confirmed_story', 'rule' => '保留已确认的全剧设定与素材 ID，生成当前批次大纲。'];
                if ($isRevision) {
                    // Keep the user's saved outline, not just the original story setting.
                    // Only the current range is sent, so long revisions remain budgeted.
                    $chunk['revision_base_result']['episodes'] = array_slice($sourceEpisodes, $start - 1, $count);
                    $chunk['revision_policy'] = $request['revision_policy'];
                }
                // Persisted results remain complete. The provider receives a
                // bounded continuity ledger, not the previous raw episodes.
                $chunk['episode_batch_context'] = ShortDramaPlanningContext::episodeMemory([
                    'previous_episodes' => array_slice($payload['episodes'], -2),
                ]);
                if ($progress) $progress('stage', ['status' => 'running', 'progress' => 20 + (int)(60 * count($payload['episodes']) / $total),
                    'current_step' => '已完成 ' . count($payload['episodes']) . '/' . $total . ' 集，正在生成第 ' . $start . '–' . ($start + $count - 1) . ' 集']);
                $messages = $assemble($chunk);
                if ($roadmap) $messages['content'] .= "\n全剧已确定的节奏分配（本批必须服从，阶段结束不等于全剧结束）：" . json_encode($roadmap, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $key = 'outline_' . $start . '_' . $count;
                try {
                    $batch = $call($key, $messages, $count, false);
                    $episodes = self::episodes($batch, $count, $start);
                    self::assertDistinctFromSaved($episodes, $payload['episodes']);
                } catch (RuntimeException $error) {
                    if (!in_array($error->getCode(), [413, 422], true)) throw $error;
                    if ($progress) $progress('story_preview_repair', ['start' => $start, 'count' => $count,
                        'message' => '第' . $start . '–' . ($start + $count - 1) . '集返回内容未通过校验，正在分段修复；已完成内容保留。']);
                    if ($count > 1) {
                        $left = intdiv($count, 2);
                        $expand($start, $left); $expand($start + $left, $count - $left);
                        return;
                    }
                    if ($error->getCode() === 413) throw $error;
                    $messages['content'] .= "\n上次返回未通过完整性校验：" . $error->getMessage() . '。只修复本集，返回完整 JSON。';
                    $episodes = self::episodes($call($key . '_repair_v2', $messages, 1, false), 1, $start);
                    self::assertDistinctFromSaved($episodes, $payload['episodes']);
                }
                foreach ($episodes as $index => $episode) {
                    $episode['episode_number'] = $start + $index;
                    $payload['episodes'][] = $episode;
                }
                if ($progress) $progress('story_preview_saved', ['episodes' => $payload['episodes']]);
            };
            // Capacity is rechecked with each actual assembled input, then split without losing successful receipts.
            if ($targetEpisode) {
                for ($start = 1; $start <= $total; $start++) {
                    if ($start !== $targetEpisode) {
                        $payload['episodes'][] = $sourceEpisodes[$start - 1];
                        continue;
                    }
                    $expand($start, 1);
                    // Only creative outline fields may change; preserve identifiers and metadata too.
                    $payload['episodes'][$start - 1] = array_merge($sourceEpisodes[$start - 1], array_intersect_key(
                        $payload['episodes'][$start - 1], array_flip(['title', 'story_outline', 'conflict_point', 'ending_hook'])
                    ));
                }
                self::assertDistinctFromSaved([$payload['episodes'][$targetEpisode - 1]], array_values(array_filter($sourceEpisodes,
                    static fn(array $episode): bool => (int)$episode['episode_number'] !== $targetEpisode)));
            } else {
                for ($start = 1; $start <= $total; $start += 10) $expand($start, min(10, $total - $start + 1));
            }
            $issues = ShortDramaStoryWorkflow::issues($payload, 'episodes', $total);
            if ($issues) throw new RuntimeException($issues[0]['message'], 422);
            $timing = ShortDramaEpisodeDuration::policy($request);
            if (ShortDramaEpisodeDuration::active($request) && ($timing['scope'] ?? '') === 'series' && $targetEpisode <= 0) {
                $input = ['system_prompt' => '只规划分集时长，不改剧情。返回JSON {"episode_durations":[数字秒数,...]}，按集号顺序完整覆盖每集，均大于0，合计必须等于整部总时长。根据各集剧情分配。',
                    'content' => json_encode(['total_seconds' => $timing['target_seconds'], 'episodes' => array_map(static fn($item) => array_intersect_key($item,
                        array_flip(['episode_number', 'title', 'story_outline'])), $payload['episodes'])], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    try {
                        $allocation = (array)($call('roadmap_timing' . ($attempt ? '_repair' : ''), $input, 1, false)['episode_durations'] ?? []);
                        self::assertDurationAllocation($allocation, $total, (float)$timing['target_seconds']);
                        break;
                    } catch (RuntimeException $error) {
                        if ($attempt || !in_array($error->getCode(), [413, 422], true)) throw $error;
                        $input['content'] .= "\n修复：" . $error->getMessage();
                    }
                }
                foreach ($payload['episodes'] as $index => &$episode) $episode['target_duration_seconds'] = (float)$allocation[$index];
                unset($episode);
            }
        }
        $payload['multi_episode'] = true;
        $payload['episode_count'] = $total;
        $payload['multi_episode_stage'] = $stage;
        $payload['storyboard'] = [];
        $payload['episodes'] = $payload['episodes'] ?? [];
        $payload['script_lines'] = $payload['script_lines'] ?? [];
        $payload['art_style'] = $payload['art_style'] ?? [];
        return ['result' => $payload, 'receipts' => array_values($receipts), 'model_selection' => $model];
    }

    public static function episodes(array $payload, int $count, int $start = 1): array
    {
        $episodes = $payload['episodes'] ?? null;
        if (!is_array($episodes) || count($episodes) !== $count || !empty($payload['storyboard'])) throw new RuntimeException('本批集数不完整或包含制作分镜', 422);
        $numbers = array_map(static fn($episode) => is_array($episode) ? (int)($episode['episode_number'] ?? 0) : 0, array_values($episodes));
        // Providers may use batch-local or global numbers. Accept only an exact complete
        // range in either convention; the caller maps to global numbers once.
        if ($numbers !== range(1, $count) && $numbers !== range($start, $start + $count - 1)) throw new RuntimeException('本批集号不连续', 422);
        $seen = [];
        foreach (array_values($episodes) as $index => $episode) {
            if (!is_array($episode)) throw new RuntimeException('本批集号不连续', 422);
            foreach (['title', 'story_outline', 'conflict_point', 'ending_hook'] as $field) {
                if (!ShortDramaStoryWorkflow::isCreativeText($episode[$field] ?? null)) throw new RuntimeException('第' . ($index + 1) . '集缺少 ' . $field, 422);
            }
            if (isset($seen[trim($episode['story_outline'])])) throw new RuntimeException('本批存在完全重复的集剧情', 422);
            $seen[trim($episode['story_outline'])] = true;
        }
        return array_values($episodes);
    }

    public static function assertDurationAllocation(array $allocation, int $count, float $total): void
    {
        if (count($allocation) !== $count || array_filter($allocation, static fn($n) => !is_numeric($n) || !is_finite((float)$n) || $n <= 0)
            || abs(array_sum($allocation) - $total) > 0.001) {
            throw new RuntimeException('分集时长分配不完整或与整部总时长不一致', 422);
        }
    }

    private static function assertDistinctFromSaved(array $episodes, array $saved): void
    {
        $seen = array_fill_keys(array_map('trim', array_column($saved, 'story_outline')), true);
        foreach ($episodes as $episode) {
            if (isset($seen[trim($episode['story_outline'])])) throw new RuntimeException('本集剧情与已完成的前集完全重复，请推进剧情而非复制前集', 422);
        }
    }
}
