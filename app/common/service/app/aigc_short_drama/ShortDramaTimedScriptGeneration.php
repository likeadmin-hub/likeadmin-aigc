<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Budget-first generation, using the existing durable provider-unit callback. */
final class ShortDramaTimedScriptGeneration
{
    public static function generate(array $request, array $messages, callable $call, ?callable $progress, int $outputBudget = 8192): array
    {
        $policy = ShortDramaEpisodeDuration::policy($request);
        $rule = ShortDramaShotDuration::rule($request);
        $base = ['system_prompt' => $messages['system_prompt']
                . "\n当前采用分阶段生成。上述规则中的创作、角色、风格、剧情与连续性要求完整保留；完整剧本的字段清单和输出示例由下方本阶段输出结构替代。只输出本阶段指定字段。"
                . "\n" . ShortDramaEpisodeDuration::instruction($request) . "\n" . ShortDramaSameSceneCuts::instruction($request),
            'content' => $messages['_stage_content'] ?? $messages['content']];
        $skeletonInput = $base;
        $skeletonInput['system_prompt'] .= "\n本阶段仅生成骨架JSON：title、type_judgement、core_theme、story_outline、script_lines、series_bible、subjects、locations、art_style、scene_beats。"
            . 'subjects每项必须有id、name、description，locations每项必须有id、name、description。沿用稳定主体和场景id。scene_beats按剧情顺序，每项为scene_ref_id（必须引用locations中某项id，不是beat编号）、goal、entry、exit、duration_seconds、shot_durations（每张卡片秒数数组）、key_events（必须保留的具体事件或台词数组）。'
            . '先按实际可见动作顺序、对白字数和说话停顿、运镜及画面必要停留规划每镜内容，再为每镜估算完成内容所需的shot_durations；不要把一个简单动作拉长，也不要把多段动作或长对白塞进短镜头。'
            . '每场shot_durations合计等于duration_seconds；仅用户明确指定时长或时间码时要求整集合计严格匹配，默认时长只作上限参考，不能补镜头或延长静止画面凑数。镜头数量按内容决定，不使用固定数量档位。每个片段时长遵守任务范围。'
            . '时间码存在时，每条scene_beat严格对应一个时间段，时长完全一致；不要跨段合并。每场最多40个片段，总场次最多24。不要返回storyboard。'
            . '这是紧凑骨架，不生成任何图片、视频、三视图、音乐、负面提示词；主体与场景description各不超过120字；保留具体剧情，不写重复风格说明。';
        $skeleton = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $skeleton = self::lockTimelineShotDurations(
                    self::canonicalSkeleton($call('timed_skeleton_' . $attempt, $skeletonInput, 8192)),
                    $request
                );
                if (!empty($skeleton['storyboard'])) {
                    try {
                        self::assertCompletePlan($skeleton, $request);
                    } catch (RuntimeException $error) {
                        if (!self::isDialogueDensityError($error)) throw $error;
                        $part = ['storyboard' => (array)$skeleton['storyboard']];
                        $durations = array_column($part['storyboard'], 'recommended_duration_seconds');
                        try {
                            $part = self::repairDenseDialogues($part, $durations, $call, 0, 0);
                            $skeleton['storyboard'] = $part['storyboard'];
                            self::assertCompletePlan($skeleton, $request);
                            $skeleton['timing_diagnostics'] = ['version' => 1, 'content_repairs' => 0,
                                'dialogue_repairs' => 1, 'dialogue_fallback_repairs' => 0, 'time_repairs' => $attempt,
                                'total_seconds' => array_sum(array_column($skeleton['storyboard'], 'recommended_duration_seconds')), 'policy' => $policy];
                            return $skeleton;
                        } catch (RuntimeException) {
                            $compact = self::compactDenseDialogues($part, $durations);
                            if ($compact === null) throw $error;
                            $skeleton['storyboard'] = $compact['storyboard'];
                            self::assertCompletePlan($skeleton, $request);
                            $skeleton['timing_diagnostics'] = ['version' => 1, 'content_repairs' => 0,
                                'dialogue_repairs' => 0, 'dialogue_fallback_repairs' => 1, 'time_repairs' => $attempt,
                                'total_seconds' => array_sum(array_column($skeleton['storyboard'], 'recommended_duration_seconds')), 'policy' => $policy];
                            return $skeleton;
                        }
                    }
                    $skeleton['timing_diagnostics'] = ['version' => 1, 'content_repairs' => 0,
                        'dialogue_repairs' => 0, 'dialogue_fallback_repairs' => 0, 'time_repairs' => $attempt,
                        'total_seconds' => array_sum(array_column($skeleton['storyboard'], 'recommended_duration_seconds')), 'policy' => $policy];
                    return $skeleton;
                }
                self::assertSkeleton($skeleton, $request);
                break;
            } catch (RuntimeException $e) {
                if ($attempt === 2 || !in_array($e->getCode(), [413, 422], true)) throw $e;
                $skeletonInput['content'] = $base['content'] . "\n骨架修复：" . $e->getMessage()
                    . "\n保留已确定的角色、场景和事实，仅修复不合格预算或结构，返回完整骨架。已有骨架=" . self::json($skeleton);
            }
        }
        $timeRepairs = $attempt;
        if ($progress) $progress('stage', ['status' => 'running', 'progress' => 25, 'current_step' => '已规划各场时长，正在生成分镜']);
        $shots = [];
        $contentRepairs = 0;
        $dialogueRepairs = 0;
        $dialogueFallbackRepairs = 0;
        // Size output units to the selected model, not to a fixed shot count.
        // Reserve room for identifiers, JSON and dialogue as well as descriptions.
        $partSize = max(1, min(4, (int)floor(($outputBudget - 500) / 1600)));
        foreach ($skeleton['scene_beats'] as $sceneIndex => $beat) {
            $durations = array_values($beat['shot_durations']);
            $pending = [];
            for ($offset = 0; $offset < count($durations); $offset += $partSize) {
                $pending[] = [$offset, min($partSize, count($durations) - $offset), ''];
            }
            while ($pending) {
                [$offset, $size, $splitKey] = array_shift($pending);
                $partDurations = array_slice($durations, $offset, $size);
                $ids = array_map(static fn($n) => 's' . ($sceneIndex + 1) . '_' . ($offset + $n + 1), array_keys($partDurations));
                $input = $base;
                $input['system_prompt'] .= "\n本阶段仅返回{\"storyboard\":[...]}，每项包含shot_id、scene_ref_id、subject_ref_ids、visual_description、composition、camera_movement、dialogue、voice_role、speech_type、recommended_duration_seconds。"
                    . '严格采用required_shots的ID及时长，不重新决定数量。覆盖当前片段应承载的key_events并保持前后衔接，不重复其他分段事件。'
                    . '对白务必能在片段内自然说完，保留停顿；speech_type为character、narration或none。voice_role写真实说话角色，静默留空。'
                    . '仅返回列出的10个字段，不额外生成image_prompt、video_prompt、negative_prompt、风格和素材定义；它们由现有系统根据本段字段组装。'
                    . 'visual_description每镜最多120字，composition最多40字，camera_movement最多100字，准确简练保留关键动作、位置、情绪与连续性。';
                $input['content'] = self::json([
                    'creative_context' => $base['content'],
                    'locked_plan' => array_intersect_key($skeleton, array_flip(['title', 'story_outline', 'series_bible', 'subjects', 'locations', 'art_style'])),
                    'scene_continuity' => $skeleton['scene_beats'], 'scene_beat' => $beat,
                    'required_shots' => array_map(static fn($id, $duration) => ['shot_id' => $id, 'duration_seconds' => $duration], $ids, $partDurations),
                    'shot_offset' => $offset, 'previous_shots' => array_slice($shots, -2),
                ]);
                $input['content'] .= "\n以上全局剧情仅用于保持连续性，不要求重写整集。本次只输出" . count($ids)
                    . '个分镜，ID依次为' . implode(',', $ids) . '，对应秒数为' . implode(',', $partDurations)
                    . '。不得输出其他ID、场次或重复已经完成的分镜。忽略上下文模板要求的整集输出格式，当前仅返回storyboard数组。';
                $part = [];
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    try {
                        $part = $call('timed_scene_' . ($sceneIndex + 1) . '_' . ($offset + 1) . $splitKey . ($attempt ? '_repair' : ''), $input, 1800 + count($ids) * 750);
                        self::assertPart($part, $skeleton, $beat, $ids, $partDurations);
                        foreach ($part['storyboard'] as $shot) ShortDramaSameSceneCuts::assertShot($shot, $skeleton, $request);
                        break;
                    } catch (RuntimeException $e) {
                        if ($e->getCode() === 413 && $size > 1) {
                            $left = intdiv($size, 2);
                            // Distinct durable keys prevent replaying the truncated
                            // parent's receipt for a smaller request at the same offset.
                            array_unshift($pending, [$offset, $left, '_split_' . $left],
                                [$offset + $left, $size - $left, '_split_' . ($size - $left)]);
                            continue 2;
                        }
                        // Dialogue density is a field-level quality problem. Asking
                        // the provider to reproduce a full storyboard part has made
                        // otherwise valid scripts fail unnecessarily, especially on
                        // five-second shots where providers sometimes mix action
                        // descriptions into dialogue. Repair only the affected
                        // dialogue fields first and keep the approved shot budget,
                        // scene references and visual copy untouched.
                        if (self::isDialogueDensityError($e) && $part !== []) {
                            try {
                                $part = self::repairDenseDialogues($part, $partDurations, $call, $sceneIndex, $offset);
                                self::assertPart($part, $skeleton, $beat, $ids, $partDurations);
                                foreach ($part['storyboard'] as $shot) ShortDramaSameSceneCuts::assertShot($shot, $skeleton, $request);
                                $dialogueRepairs++;
                                break;
                            } catch (RuntimeException $dialogueRepairError) {
                                // The first response is still useful when it only
                                // leaked staging prose such as “他转身说道：”. Strip
                                // that non-spoken wrapper locally before falling
                                // back to a complete part repair. This operates only
                                // on provider output, never on user-supplied text.
                                $compact = self::compactDenseDialogues($part, $partDurations);
                                if ($compact !== null) {
                                    try {
                                        self::assertPart($compact, $skeleton, $beat, $ids, $partDurations);
                                        foreach ($compact['storyboard'] as $shot) ShortDramaSameSceneCuts::assertShot($shot, $skeleton, $request);
                                        $part = $compact;
                                        $dialogueFallbackRepairs++;
                                        break;
                                    } catch (RuntimeException) {
                                        // Continue through the existing bounded
                                        // full-part repair when compaction cannot
                                        // produce a valid dialogue contract.
                                    }
                                }
                            }
                        }
                        // A scene can be split into several independent provider
                        // calls. A malformed earlier part must not consume the
                        // bounded structural repair available to a later part.
                        // `$attempt` still caps each part at one full retry.
                        if ($attempt || !in_array($e->getCode(), [413, 422], true)) throw $e;
                        $contentRepairs++;
                        $input['content'] .= "\n仅修复本段：" . $e->getMessage() . "\n原返回=" . self::json($part);
                    }
                }
                array_push($shots, ...$part['storyboard']);
                if ($progress) $progress('stage', ['status' => 'running', 'progress' => min(78, 25 + (int)(50 * ($sceneIndex + 1) / count($skeleton['scene_beats']))),
                    'current_step' => '已生成' . count($shots) . '个分镜，约' . round(array_sum(array_column($shots, 'recommended_duration_seconds'))) . '秒']);
            }
        }
        $skeleton['storyboard'] = self::attachTimelineRanges($shots, $request);
        $skeleton['episodes'] = [];
        $skeleton['timing_diagnostics'] = ['version' => ShortDramaEpisodeDuration::VERSION, 'content_repairs' => $contentRepairs,
            'dialogue_repairs' => $dialogueRepairs, 'dialogue_fallback_repairs' => $dialogueFallbackRepairs, 'time_repairs' => $timeRepairs,
            'total_seconds' => array_sum(array_column($shots, 'recommended_duration_seconds')), 'policy' => $policy];
        ShortDramaEpisodeDuration::assertPlan($skeleton, $request);
        return $skeleton;
    }

    public static function assertCompletePlan(array $plan, array $request): void
    {
        foreach (['title', 'story_outline', 'script_lines', 'subjects', 'locations', 'storyboard'] as $field) {
            if (empty($plan[$field])) throw new RuntimeException('完整剧本缺少' . $field, 422);
        }
        $rule = ShortDramaShotDuration::rule($request);
        $seen = [];
        foreach ($plan['storyboard'] as $shot) {
            $id = (string)($shot['shot_id'] ?? '');
            if ($id === '' || isset($seen[$id]) || !ShortDramaShotDuration::contains($shot['recommended_duration_seconds'] ?? null, $rule)
                || !in_array($shot['scene_ref_id'] ?? '', array_column($plan['locations'], 'id'), true)) {
                throw new RuntimeException('完整剧本的分镜ID、场景或时长不符合要求', 422);
            }
            $seen[$id] = true;
            self::assertPart(['storyboard' => [$shot]], $plan, ['scene_ref_id' => $shot['scene_ref_id']], [$id], [$shot['recommended_duration_seconds']]);
            ShortDramaSameSceneCuts::assertShot($shot, $plan, $request);
        }
        ShortDramaEpisodeDuration::assertPlan($plan, $request);
    }

    /** Existing providers use these documented aliases. Never infer a reference. */
    public static function canonicalSkeleton(array $plan): array
    {
        foreach (['subjects' => 'subject_id', 'locations' => 'location_id'] as $collection => $alias) {
            if (!is_array($plan[$collection] ?? null)) continue;
            $seen = [];
            foreach ($plan[$collection] as &$item) {
                if (!is_array($item)) throw new RuntimeException($collection . '必须返回对象数组', 422);
                if (empty($item['id']) && !empty($item[$alias])) $item['id'] = (string)$item[$alias];
                $id = (string)($item['id'] ?? '');
                if ($id === '' || isset($seen[$id])) throw new RuntimeException($collection . '中的id缺失或重复', 422);
                $seen[$id] = true;
            }
            unset($item);
        }
        return $plan;
    }

    public static function assertSkeleton(array $plan, array $request): void
    {
        foreach (['title', 'story_outline', 'subjects', 'locations', 'scene_beats'] as $key) {
            if (empty($plan[$key])) throw new RuntimeException('骨架缺少必要内容：' . $key, 422);
        }
        $beats = $plan['scene_beats'];
        if (!is_array($beats) || count($beats) > 24) throw new RuntimeException('骨架场次数量超出处理范围', 422);
        $timeline = ShortDramaEpisodeDuration::policy($request)['timeline_segments'] ?? [];
        if ($timeline && count($timeline) !== count($beats)) throw new RuntimeException('骨架场次必须逐条对应用户时间码', 422);
        $shots = [];
        $rule = ShortDramaShotDuration::rule($request);
        foreach ($beats as $index => $beat) {
            if (!is_array($beat)) throw new RuntimeException('scene_beats必须返回对象数组', 422);
            if (!in_array($beat['scene_ref_id'] ?? '', array_column($plan['locations'], 'id'), true)) {
                throw new RuntimeException('scene_beats[' . $index . '].scene_ref_id必须引用真实locations.id，可选值=' . implode(',', array_column($plan['locations'], 'id')) . '；不能使用beat编号', 422);
            }
            if (empty($beat['goal']) || empty($beat['entry']) || empty($beat['exit']) || empty($beat['key_events'])
                || !is_array($beat['shot_durations'] ?? null) || !$beat['shot_durations'] || count($beat['shot_durations']) > 40) {
                throw new RuntimeException('场景骨架、关键事件或分镜时间预算缺失', 422);
            }
            foreach ($beat['shot_durations'] as $duration) {
                if (!is_numeric($duration) || $duration <= 0 || !is_finite((float)$duration)
                    || (!$timeline && !ShortDramaShotDuration::contains($duration, $rule)) || $duration > $rule['max_seconds']) {
                    throw new RuntimeException('骨架分镜时间预算不符合片段范围', 422);
                }
                $shots[] = ['recommended_duration_seconds' => $duration];
            }
            $sum = array_sum($beat['shot_durations']);
            if (abs($sum - (float)($beat['duration_seconds'] ?? 0)) > 0.001) throw new RuntimeException('场次时长与片段合计不一致', 422);
            if ($timeline && abs($sum - (float)$timeline[$index]['duration_seconds']) > 0.001) throw new RuntimeException('场次时长与用户时间码不一致', 422);
            if ($timeline) {
                $expectedDurations = ShortDramaEpisodeDuration::timelineShotDurations((float)$timeline[$index]['duration_seconds'], $request);
                if (count($beat['shot_durations']) !== count($expectedDurations)) {
                    throw new RuntimeException('时间码段只能在超过单镜头上限时拆分', 422);
                }
                foreach (array_values($beat['shot_durations']) as $durationIndex => $duration) {
                    if (abs((float)$duration - (float)$expectedDurations[$durationIndex]) > 0.001) {
                        throw new RuntimeException('时间码段只能在超过单镜头上限时拆分', 422);
                    }
                }
            }
        }
        ShortDramaEpisodeDuration::assertPlan(['storyboard' => $shots], $request);
    }

    private static function lockTimelineShotDurations(array $plan, array $request): array
    {
        $timeline = (array)(ShortDramaEpisodeDuration::policy($request)['timeline_segments'] ?? []);
        if (empty($timeline) || !is_array($plan['scene_beats'] ?? null)) {
            return $plan;
        }
        foreach ($timeline as $index => $segment) {
            if (!isset($plan['scene_beats'][$index]) || !is_array($plan['scene_beats'][$index])) {
                continue;
            }
            $duration = (float)($segment['duration_seconds'] ?? 0);
            $plan['scene_beats'][$index]['duration_seconds'] = $duration;
            $plan['scene_beats'][$index]['shot_durations'] = ShortDramaEpisodeDuration::timelineShotDurations($duration, $request);
        }
        return $plan;
    }

    private static function attachTimelineRanges(array $shots, array $request): array
    {
        $timeline = (array)(ShortDramaEpisodeDuration::policy($request)['timeline_segments'] ?? []);
        if (empty($timeline)) {
            return $shots;
        }
        $shotIndex = 0;
        foreach ($timeline as $segment) {
            $start = (float)($segment['start_seconds'] ?? 0);
            foreach (ShortDramaEpisodeDuration::timelineShotDurations((float)($segment['duration_seconds'] ?? 0), $request) as $duration) {
                if (!isset($shots[$shotIndex]) || !is_array($shots[$shotIndex])) {
                    break 2;
                }
                $end = $start + $duration;
                $shots[$shotIndex]['start_seconds'] = $start;
                $shots[$shotIndex]['end_seconds'] = $end;
                $shots[$shotIndex]['time_range'] = self::timeRangeLabel($start, $end);
                $start = $end;
                $shotIndex++;
            }
        }
        return $shots;
    }

    private static function timeRangeLabel(float $start, float $end): string
    {
        $format = static function (float $seconds): string {
            $milliseconds = (int)round(max(0, $seconds) * 1000);
            $minutes = intdiv($milliseconds, 60000);
            $wholeSeconds = intdiv($milliseconds % 60000, 1000);
            $fraction = $milliseconds % 1000;
            return str_pad((string)$minutes, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$wholeSeconds, 2, '0', STR_PAD_LEFT)
                . ($fraction ? '.' . rtrim(str_pad((string)$fraction, 3, '0', STR_PAD_LEFT), '0') : '');
        };
        return $format($start) . '-' . $format($end);
    }

    public static function assertPart(array $part, array $plan, array $beat, array $ids, array $durations): void
    {
        $shots = $part['storyboard'] ?? [];
        if (!is_array($shots) || count($shots) !== count($ids)) throw new RuntimeException('本段分镜数量不完整', 422);
        foreach (array_values($shots) as $index => $shot) {
            if (($shot['shot_id'] ?? '') !== $ids[$index] || ($shot['scene_ref_id'] ?? '') !== $beat['scene_ref_id']
                || empty($shot['visual_description']) || !is_array($shot['subject_ref_ids'] ?? null)
                || array_diff($shot['subject_ref_ids'], array_column($plan['subjects'], 'id'))
                || abs((float)($shot['recommended_duration_seconds'] ?? 0) - $durations[$index]) > 0.001) {
                throw new RuntimeException('本段标识、主体场景绑定或时长与已确认预算不一致', 422);
            }
            $dialogue = $shot['dialogue'] ?? '';
            if (!is_string($dialogue)) throw new RuntimeException('dialogue必须是带角色名的台词字符串，不能返回数组或对象', 422);
            // A deliberately generous guard catches impossible delivery, not acting style.
            $characters = self::dialogueCharacterCount($dialogue);
            if ($characters > $durations[$index] * 8) throw new RuntimeException('本段对白过密，请保留关键含义并缩短台词，为动作和停顿留出时间', 422);
        }
    }

    /**
     * Repair dense dialogue without handing a valid multi-shot result back to
     * the model as an open-ended rewrite request. The returned structure is
     * deliberately tiny and every repaired ID must have been part of the
     * failed provider response.
     */
    private static function repairDenseDialogues(array $part, array $durations, callable $call, int $sceneIndex, int $offset): array
    {
        $targets = [];
        foreach (array_values((array)($part['storyboard'] ?? [])) as $index => $shot) {
            if (!is_array($shot)) continue;
            $dialogue = $shot['dialogue'] ?? '';
            $limit = max(1, (int)floor(((float)($durations[$index] ?? 0)) * 8));
            if (!is_string($dialogue) || self::dialogueCharacterCount($dialogue) <= $limit) continue;
            $shotId = trim((string)($shot['shot_id'] ?? ''));
            if ($shotId === '') throw new RuntimeException('超长对白缺少分镜标识，无法自动修复', 422);
            $targets[$shotId] = [
                'shot_id' => $shotId,
                'duration_seconds' => (float)($durations[$index] ?? 0),
                'max_dialogue_characters' => $limit,
                'dialogue' => $dialogue,
                'voice_role' => (string)($shot['voice_role'] ?? ''),
                'speech_type' => (string)($shot['speech_type'] ?? ''),
            ];
        }
        if ($targets === []) throw new RuntimeException('未找到可自动修复的超长对白', 422);

        $input = [
            'system_prompt' => '你是短剧台词编辑。只返回合法 JSON，不要 Markdown 或解释。只允许输出 {'
                . '"dialogue_repairs":[{"shot_id":"","dialogue":""}]}。逐条改写指定分镜的 dialogue：保留关键剧情和情绪，删除动作、镜头、人物表情、说话方式等非说出口内容；不得新增分镜、人物、字段或改写时长。dialogue 可以为空；每条必须不超过给定的有效字符上限。',
            'content' => self::json(['dialogue_repairs' => array_values($targets)]),
        ];
        $response = $call('timed_dialogue_repair_' . ($sceneIndex + 1) . '_' . ($offset + 1), $input, 1024 + count($targets) * 160);
        $repairs = (array)($response['dialogue_repairs'] ?? []);
        $byShotId = [];
        foreach ($repairs as $repair) {
            if (!is_array($repair)) continue;
            $shotId = trim((string)($repair['shot_id'] ?? ''));
            if ($shotId === '' || !isset($targets[$shotId]) || !array_key_exists('dialogue', $repair) || !is_string($repair['dialogue'])) {
                throw new RuntimeException('对白自动修复返回了无效分镜或台词', 422);
            }
            $byShotId[$shotId] = $repair['dialogue'];
        }
        if (count($byShotId) !== count($targets)) throw new RuntimeException('对白自动修复未覆盖全部超长分镜', 422);

        foreach ((array)($part['storyboard'] ?? []) as $index => $shot) {
            if (!is_array($shot)) continue;
            $shotId = trim((string)($shot['shot_id'] ?? ''));
            if (array_key_exists($shotId, $byShotId)) $part['storyboard'][$index]['dialogue'] = trim($byShotId[$shotId]);
        }
        return $part;
    }

    /**
     * Last-resort cleanup for provider output that embeds screenplay prose in
     * dialogue (for example “甲转身喊道：快走！”). It only succeeds when the
     * resulting spoken text fits the already approved timing budget.
     */
    private static function compactDenseDialogues(array $part, array $durations): ?array
    {
        $changed = false;
        foreach ((array)($part['storyboard'] ?? []) as $index => $shot) {
            if (!is_array($shot) || !is_string($shot['dialogue'] ?? null)) continue;
            $limit = max(1, (int)floor(((float)($durations[$index] ?? 0)) * 8));
            $dialogue = trim($shot['dialogue']);
            if (self::dialogueCharacterCount($dialogue) <= $limit) continue;
            $compact = self::compactDialogue($dialogue, $limit);
            if ($compact === '' || $compact === $dialogue || self::dialogueCharacterCount($compact) > $limit) return null;
            $part['storyboard'][$index]['dialogue'] = $compact;
            $changed = true;
        }
        return $changed ? $part : null;
    }

    private static function compactDialogue(string $dialogue, int $limit): string
    {
        $dialogue = preg_replace('/\s+/u', '', trim($dialogue)) ?? trim($dialogue);
        $clauses = preg_split('/(?<=[。！？!?])/u', $dialogue, -1, PREG_SPLIT_NO_EMPTY) ?: [$dialogue];
        $spoken = [];
        foreach ($clauses as $clause) {
            $fullWidthColon = mb_strrpos($clause, '：', 0, 'UTF-8');
            $asciiColon = mb_strrpos($clause, ':', 0, 'UTF-8');
            if ($fullWidthColon !== false || $asciiColon !== false) {
                $colon = max($fullWidthColon === false ? -1 : $fullWidthColon, $asciiColon === false ? -1 : $asciiColon);
                $after = trim(mb_substr($clause, $colon + 1, null, 'UTF-8'));
                if ($after !== '') $clause = $after;
            }
            if ($clause !== '') $spoken[] = $clause;
        }
        $candidate = implode('', $spoken) ?: $dialogue;
        if (self::dialogueCharacterCount($candidate) <= $limit) return $candidate;

        $kept = '';
        foreach ($spoken as $clause) {
            if (self::dialogueCharacterCount($kept . $clause) > $limit) break;
            $kept .= $clause;
        }
        if ($kept !== '') return $kept;

        // There is no safe sentence boundary inside one overlong spoken line.
        // The provider has already supplied the content, so retain its leading
        // spoken portion rather than failing an entire script for one field.
        return mb_substr($candidate, 0, $limit, 'UTF-8');
    }

    private static function dialogueCharacterCount(string $text): int
    {
        return mb_strlen(preg_replace('/[\s\p{P}]/u', '', $text) ?? $text, 'UTF-8');
    }

    private static function isDialogueDensityError(RuntimeException $error): bool
    {
        return $error->getCode() === 422 && str_contains($error->getMessage(), '本段对白过密');
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
