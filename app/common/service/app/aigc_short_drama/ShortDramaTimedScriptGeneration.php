<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Budget-first generation, using the existing durable provider-unit callback. */
final class ShortDramaTimedScriptGeneration
{
    public static function generate(array $request, array $messages, callable $call, ?callable $progress, int $outputBudget = 8192, ?callable $freezeBatches = null): array
    {
        $policy = ShortDramaEpisodeDuration::policy($request);
        $rule = ShortDramaShotDuration::rule($request);
        $base = ['system_prompt' => $messages['system_prompt']
                . "\n当前采用分阶段生成。上述规则中的创作、角色、风格、剧情与连续性要求完整保留；完整剧本的字段清单和输出示例由下方本阶段输出结构替代。只输出本阶段指定字段。"
                . "\n" . ShortDramaEpisodeDuration::instruction($request) . "\n" . ShortDramaSameSceneCuts::instruction($request),
            'content' => $messages['_stage_content'] ?? $messages['content']];
        $skeletonInput = $base;
        $skeletonInput['system_prompt'] .= "\n本阶段仅生成骨架JSON：title、type_judgement、core_theme、story_outline、script_lines、series_bible、subjects、locations、art_style、scene_beats。"
            . ShortDramaShotSemantics::INSTRUCTION
            . 'subjects每项必须有id、name、description，locations每项必须有id、name、description。沿用稳定主体和场景id。scene_beats按剧情顺序，每项为scene_ref_id（必须引用locations中某项id，不是beat编号）、goal、entry、exit、duration_seconds、shot_durations（每张卡片秒数数组）、key_events（必须保留的具体事件或台词数组）。'
            . '先按实际可见动作顺序、对白字数和说话停顿、运镜及画面必要停留规划每镜内容，再为每镜估算完成内容所需的shot_durations；不要把一个简单动作拉长，也不要把多段动作或长对白塞进短镜头。'
            . '每场shot_durations合计等于duration_seconds；仅用户明确指定时长或时间码时要求整集合计严格匹配，默认时长只作上限参考，不能补镜头或延长静止画面凑数。镜头数量按内容决定，不使用固定数量档位。每个片段时长遵守任务范围。'
            . '时间码存在时，每条scene_beat严格对应一个时间段，时长完全一致；不要跨段合并。每场最多40个片段，总场次最多24。不要返回storyboard。'
            . '这是紧凑骨架，不生成任何图片、视频、三视图、音乐、负面提示词；主体与场景description各不超过120字；保留具体剧情，不写重复风格说明。';
        if ((int)($request['_input_contract_version'] ?? 0) === 15) {
            $skeletonInput['system_prompt'] .= '每个scene_beat另须包含scene_script：action为按表演顺序排列的具体可拍动作数组，dialogue为{speaker,text}对白数组（无对白用空数组）。'
                . '这就是分场剧本，须先写清人物行动、关系变化、关键台词和收场状态，再按其内容预算shot_durations。'
                . '不得只写主题句、镜头术语或把不同实际地点塞进同一场。分镜阶段必须遵守这里的行动与对白，不新增关键剧情。';
        } elseif ((int)($request['_input_contract_version'] ?? 0) >= 16) {
            $skeletonInput['system_prompt'] .= '每场scene_beat补充简洁scene_script：action为按表演顺序的可拍动作数组，dialogue为{speaker,text}对白数组（无对白用空数组）。'
                . '保留用户明确的动作和关键台词，不重复goal、entry、exit或全局风格说明；优先返回完整JSON，不为填字段增加新剧情。';
        }
        $skeleton = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $skeleton = self::normalizeSceneScripts(self::lockTimelineShotDurations(
                    self::canonicalSkeleton($call('timed_skeleton_' . $attempt, $skeletonInput, 8192,
                        static function ($payload) use ($request, &$skeleton): void {
                            $skeleton = $payload;
                            $prepared = self::normalizeSceneScripts(
                                self::lockTimelineShotDurations(self::canonicalSkeleton($payload), $request), $request);
                            if (ShortDramaInputContract::recoveryEnabled($request)) $prepared = self::normalizeSkeletonBudgets($prepared, $request);
                            $skeleton = $prepared;
                            if (empty($prepared['storyboard'])) self::assertSkeleton($prepared, $request);
                        })),
                    $request
                ), $request);
                if (ShortDramaInputContract::recoveryEnabled($request)) $skeleton = self::normalizeSkeletonBudgets($skeleton, $request);
                if (!empty($skeleton['storyboard'])) {
                    if (ShortDramaDialogueSplit::enabled($request)) {
                        $skeleton = ShortDramaDialogueSplit::adapt($skeleton, $request, $call, 'timed_complete');
                        $skeleton['storyboard'] = self::attachTimelineRanges($skeleton['storyboard'], $request);
                    }
                    elseif (ShortDramaInputContract::current($request)) $skeleton = self::fitDialogueTiming($skeleton, $request);
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
        if ((int)($request['_input_contract_version'] ?? 0) >= 15) {
            $skeleton['scene_screenplays'] = array_map(static fn($index, $beat) => [
                'scene_number' => $index + 1,
                'scene_ref_id' => $beat['scene_ref_id'],
                'entry' => $beat['entry'],
                'action' => $beat['scene_script']['action'],
                'dialogue' => $beat['scene_script']['dialogue'],
                'exit' => $beat['exit'],
            ] + ((int)($request['_input_contract_version'] ?? 0) >= 16
                ? ['source' => $beat['scene_script_source'] ?? 'model'] : []),
                array_keys($skeleton['scene_beats']), $skeleton['scene_beats']);
        }
        if ($progress) $progress('script_checkpoint', $skeleton);
        if ($progress) $progress('stage', ['status' => 'running', 'progress' => 25,
            'current_step' => (int)($request['_input_contract_version'] ?? 0) >= 15 ? '分场剧本已完成，正在生成分镜' : '已规划各场时长，正在生成分镜']);
        $shots = [];
        $contentRepairs = 0;
        $dialogueRepairs = 0;
        $dialogueFallbackRepairs = 0;
        // Size output units to the selected model, not to a fixed shot count.
        // Reserve room for identifiers, JSON and dialogue as well as descriptions.
        $partSize = max(1, min(4, (int)floor(($outputBudget - 500) / 1600)));
        $batches = []; $batchParts = []; $batchOriginal = []; $batchSafe = [];
        if (ShortDramaSceneBatch::enabled($request)) {
            $batchPlan = ShortDramaSceneBatch::plan($request, $messages, $skeleton, $partSize);
            if ($freezeBatches) $batchPlan = ShortDramaSceneBatch::restore($batchPlan, $freezeBatches($batchPlan), $skeleton);
            $partSize = (int)$batchPlan['part_size'];
            foreach ($batchPlan['groups'] as $group) $batches[$group[0]] = $group;
        }
        foreach ($skeleton['scene_beats'] as $sceneIndex => $beat) {
            if (isset($batches[$sceneIndex])) {
                $group = $batches[$sceneIndex];
                $batchInput = ShortDramaSceneBatch::input($request, $messages, $base, $skeleton, $group, $shots);
                $count = array_sum(array_map(static fn($i) => count($skeleton['scene_beats'][$i]['shot_durations']), $group));
                $reply = $call('timed_batch_' . ($group[0] + 1) . '_' . ($group[1] + 1), $batchInput, 1800 + $count * 750);
                $batchParts += ShortDramaSceneBatch::partition($reply, $skeleton, $group, $request);
                foreach ($group as $index) {
                    $batchSafe[$index] = ShortDramaSceneBatch::unambiguous($reply, $skeleton, $group);
                    $prefix = 's' . ($index + 1) . '_';
                    $batchOriginal[$index] = ['storyboard' => array_values(array_filter((array)($reply['storyboard'] ?? []),
                        static fn($shot) => is_array($shot) && is_string($shot['shot_id'] ?? null) && str_starts_with($shot['shot_id'], $prefix)))];
                }
            }
            $durations = array_values($beat['shot_durations']);
            $sceneStart = count($shots);
            $pending = [];
            for ($offset = 0; $offset < count($durations); $offset += $partSize) {
                $pending[] = [$offset, min($partSize, count($durations) - $offset), ''];
            }
            while ($pending) {
                [$offset, $size, $splitKey] = array_shift($pending);
                $partDurations = array_slice($durations, $offset, $size);
                $ids = array_map(static fn($n) => 's' . ($sceneIndex + 1) . '_' . ($offset + $n + 1), array_keys($partDurations));
                $input = $base;
                $input['system_prompt'] .= "\n本阶段仅返回{\"storyboard\":[...]}，每项包含shot_id、scene_ref_id、subject_ref_ids、interior_exterior、visual_description、composition、camera_movement、dialogue、voice_role、speech_type、recommended_duration_seconds。"
                    . 'required_shots来自你在上一阶段生成的分镜规划；沿用其中的ID及时长，不重新决定数量。覆盖当前片段应承载的key_events并保持前后衔接，不重复其他分段事件。'
                    . '对白务必能在片段内自然说完，保留停顿；speech_type为character、narration或none。voice_role写真实说话角色，静默留空。'
                    . ShortDramaShotSemantics::INSTRUCTION
                    . '仅返回列出的字段，不额外生成image_prompt、video_prompt、negative_prompt、风格和素材定义；它们由现有系统根据本段字段组装。'
                    . 'visual_description每镜最多120字，composition最多40字，camera_movement最多100字，准确简练保留关键动作、位置、情绪与连续性。';
                if ((int)($request['_input_contract_version'] ?? 0) >= 15) {
                    $input['system_prompt'] .= '当前场的scene_script是已确认的分场剧本。依次拆出动作和对白，保留关键台词原意及事件顺序；不得自行改换场地或增加新冲突。';
                }
                if (ShortDramaSceneBinding::enabled($request)) $input['system_prompt'] .= '补充输出scene_name，按实际拍摄地点核对场景绑定。';
                $context = [
                    'creative_context' => $base['content'],
                    'locked_plan' => array_intersect_key($skeleton, array_flip(['title', 'story_outline', 'series_bible', 'subjects', 'locations', 'art_style'])),
                    'scene_continuity' => (int)($request['_input_contract_version'] ?? 0) >= 15
                        ? array_map(static fn($scene) => array_intersect_key($scene,
                            array_flip(['scene_ref_id', 'goal', 'entry', 'exit', 'key_events'])), $skeleton['scene_beats'])
                        : $skeleton['scene_beats'],
                    'scene_beat' => $beat,
                    'required_shots' => array_map(static fn($id, $duration) => ['shot_id' => $id, 'duration_seconds' => $duration], $ids, $partDurations),
                    'shot_offset' => $offset, 'previous_shots' => array_slice($shots, -2),
                    'scene_progress' => ShortDramaSceneProgress::window($beat, count($durations), $offset, $size, array_slice($shots, $sceneStart)),
                ];
                $input['content'] = self::json($context);
                // Only this server-built scene envelope may be shared after capacity/memory fitting.
                if (ShortDramaScriptContext::enabled($request) && is_string($messages['_stage_content'] ?? null)
                    && $context['creative_context'] === $messages['_stage_content']) $input['_share_script_context'] = true;
                $input['content'] .= "\n以上全局剧情仅用于保持连续性，不要求重写整集。本次只输出" . count($ids)
                    . '个分镜，ID依次为' . implode(',', $ids) . '，对应秒数为' . implode(',', $partDurations)
                    . '。不得输出其他ID、场次或重复已经完成的分镜。忽略上下文模板要求的整集输出格式，当前仅返回storyboard数组。';
                $part = []; $partial = null; $completedPartial = null;
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    try {
                        // A failed attempt must not change the next attempt's locked input.
                        $validationDurations = $partDurations;
                        if (ShortDramaInputContract::partialStoryboardRecovery($request) && $attempt === 0 && $offset === 0
                            && !isset($batchParts[$sceneIndex]) && !empty($batchSafe[$sceneIndex])
                            && !empty($batchOriginal[$sceneIndex]['storyboard'])) {
                            $batchPartial = null;
                            try {
                                $normalized = ShortDramaSceneBinding::normalize(array_replace($skeleton, $batchOriginal[$sceneIndex], ['scene_beats' => [$beat]]), $request);
                                $candidate = ['storyboard' => $normalized['storyboard']];
                                $acceptedBatch = ShortDramaPartialStoryboard::accepted($candidate, $ids,
                                    static function ($shot, $index) use ($skeleton, $beat, $ids, $partDurations, $request): void {
                                        self::assertPart(['storyboard' => [$shot]], $skeleton, $beat, [$ids[$index]], [$partDurations[$index]], false);
                                        ShortDramaSameSceneCuts::assertShot($shot, $skeleton, $request);
                                    });
                                if ($acceptedBatch !== null && $acceptedBatch !== []) $batchPartial = $candidate;
                            } catch (RuntimeException $batchError) {
                                if (!in_array($batchError->getCode(), [409, 413, 422], true)) throw $batchError;
                            }
                            if ($batchPartial !== null) {
                                $partial = $batchPartial;
                                throw new RuntimeException('本段分镜数量不完整', 422);
                            }
                        }
                        if ($completedPartial !== null) {
                            $part = $completedPartial;
                            $completedPartial = null;
                        } elseif ($offset === 0 && $attempt === 0 && isset($batchParts[$sceneIndex])) {
                            $part = $batchParts[$sceneIndex];
                        } else {
                            if (array_key_exists($sceneIndex, $batchParts) && $offset === 0 && $attempt === 0) {
                                $input['content'] .= "\n此前相邻场景合并返回中，本场缺失或未通过校验。本次只生成本场，其他场次的合格结果已保留。";
                                $input['content'] .= "\n本场已收到的内容=" . self::json($batchOriginal[$sceneIndex]);
                                $input['content'] .= "\n只修复本场缺失或不符合当前场景、ID、时长的字段，保留已有合格动作、对白、说话主体及事件顺序。";
                                if (isset($batchParts[$sceneIndex + 1])) $input['content'] .= "\n相邻后场已保留的结果=" . self::json($batchParts[$sceneIndex + 1])
                                    . '。本场仍按已确认骨架完成，保持与已保留后场的事实衔接，不新增或提前演出后场事件。';
                                $contentRepairs++;
                            }
                            $part = $call('timed_scene_' . ($sceneIndex + 1) . '_' . ($offset + 1) . $splitKey . ($attempt ? '_repair' : ''), $input, 1800 + count($ids) * 750,
                                static function ($candidate) use (&$partial, $skeleton, $beat, $ids, $partDurations): void {
                                    $partial = $candidate;
                                    self::assertPart($candidate, $skeleton, $beat, $ids, $partDurations, false);
                                });
                        }
                        $validationIds = $ids;
                        if (ShortDramaDialogueSplit::enabled($request)) {
                            self::assertPart($part, $skeleton, $beat, $ids, $partDurations, false);
                            $part = ShortDramaDialogueSplit::adapt($part, $request, $call, 'timed_' . $sceneIndex . '_' . $offset . $splitKey . '_' . $attempt);
                            $validationIds = array_column($part['storyboard'], 'shot_id');
                            $validationDurations = array_column($part['storyboard'], 'recommended_duration_seconds');
                        } elseif (ShortDramaInputContract::current($request)) {
                            $originalPart = $part;
                            $part = self::fitDialogueTiming($part, $request);
                            foreach ((array)($part['storyboard'] ?? []) as $index => $shot) {
                                $before = $originalPart['storyboard'][$index]['recommended_duration_seconds'] ?? null;
                                if (isset($partDurations[$index]) && is_numeric($before) && (float)$before === (float)$partDurations[$index]
                                    && ($shot['recommended_duration_seconds'] ?? null) !== $before) $validationDurations[$index] = $shot['recommended_duration_seconds'];
                            }
                        }
                        self::assertPart($part, $skeleton, $beat, $validationIds, $validationDurations, !ShortDramaInputContract::providerTiming($request));
                        foreach ($part['storyboard'] as $shot) ShortDramaSameSceneCuts::assertShot($shot, $skeleton, $request);
                        break;
                    } catch (RuntimeException $e) {
                        if (ShortDramaInputContract::partialStoryboardRecovery($request) && $attempt === 0 && $e->getCode() === 422 && $e->getMessage() === '本段分镜数量不完整' && is_array($partial)) {
                            $accepted = ShortDramaPartialStoryboard::accepted($partial, $ids,
                                static function ($shot, $index) use ($skeleton, $beat, $ids, $partDurations, $request): void {
                                    self::assertPart(['storyboard' => [$shot]], $skeleton, $beat, [$ids[$index]], [$partDurations[$index]], false);
                                    ShortDramaSameSceneCuts::assertShot($shot, $skeleton, $request);
                                });
                            if ($accepted === [] && $size > 1) {
                                $left = intdiv($size, 2);
                                array_unshift($pending, [$offset, $left, '_split_' . $left],
                                    [$offset + $left, $size - $left, '_split_' . ($size - $left)]);
                                continue 2;
                            }
                            if ($accepted !== null && $accepted !== []) {
                                foreach ($ids as $index => $id) {
                                    if (isset($accepted[$index])) continue;
                                    ksort($accepted);
                                    $missingContext = $context;
                                    $missingContext['required_shots'] = [['shot_id' => $id, 'duration_seconds' => $partDurations[$index]]];
                                    $missingContext['shot_offset'] = $offset + $index;
                                    $missingContext['retained_shots'] = array_values($accepted);
                                    $before = array_filter($accepted, static fn($key) => $key < $index, ARRAY_FILTER_USE_KEY);
                                    $missingContext['scene_progress'] = ShortDramaSceneProgress::window($beat, count($durations), $offset + $index, 1,
                                        array_merge(array_slice($shots, $sceneStart), array_values($before)));
                                    $missingInput = $input;
                                    $missingInput['content'] = self::json($missingContext)
                                        . "\n只补充缺失分镜 {$id}，时长 {$partDurations[$index]} 秒。已保留分镜仅供衔接，不得重写或重复。只返回该分镜的storyboard数组。";
                                    $missingKey = 'timed_scene_' . ($sceneIndex + 1) . '_' . ($offset + 1) . $splitKey . '_missing_' . ($offset + $index + 1);
                                    for ($missingAttempt = 0; $missingAttempt < 2; $missingAttempt++) {
                                        try {
                                            $missing = $call($missingKey . ($missingAttempt ? '_repair' : ''), $missingInput, 2550,
                                                static fn($value) => self::assertPart($value, $skeleton, $beat, [$id], [$partDurations[$index]], false));
                                            self::assertPart($missing, $skeleton, $beat, [$id], [$partDurations[$index]], false);
                                            ShortDramaSameSceneCuts::assertShot($missing['storyboard'][0], $skeleton, $request);
                                            $accepted[$index] = $missing['storyboard'][0];
                                            break;
                                        } catch (RuntimeException $missingError) {
                                            if ($missingAttempt || !in_array($missingError->getCode(), [413, 422], true)) throw $missingError;
                                            $missingInput['content'] .= "\n仅修复缺失分镜：" . $missingError->getMessage();
                                        }
                                    }
                                }
                                ksort($accepted);
                                $completedPartial = ['storyboard' => array_values($accepted)];
                                $contentRepairs++;
                                continue;
                            }
                        }
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
                if ($progress) $progress('script_checkpoint', array_replace($skeleton, ['storyboard' => self::attachTimelineRanges($shots, $request)]));
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
            self::assertPart(['storyboard' => [$shot]], $plan, ['scene_ref_id' => $shot['scene_ref_id']], [$id], [$shot['recommended_duration_seconds']], !ShortDramaInputContract::providerTiming($request));
            ShortDramaSameSceneCuts::assertShot($shot, $plan, $request);
        }
        ShortDramaEpisodeDuration::assertPlan($plan, $request);
    }

    /** Never alter spoken text to fit a generated budget. Locked timing conflicts
     * are actionable failures, not permission to shorten the user's story. */
    public static function fitDialogueTiming(array $part, array $request): array
    {
        if (ShortDramaInputContract::providerTiming($request)) return $part;
        $policy = ShortDramaEpisodeDuration::policy($request);
        $rule = ShortDramaShotDuration::rule($request);
        foreach ((array)($part['storyboard'] ?? []) as $index => $shot) {
            if (!is_array($shot) || !is_string($shot['dialogue'] ?? null)) continue;
            $duration = (float)($shot['recommended_duration_seconds'] ?? 0);
            $characters = self::dialogueCharacterCount($shot['dialogue']);
            if ($characters <= $duration * 8) continue;
            // Allow normal speech plus a pause, but never stretch a locked timeline.
            $needed = ceil($characters / 6) + 1;
            if (($policy['source'] ?? '') !== 'default' || $needed > $rule['max_seconds']) {
                throw new RuntimeException('分镜 ' . (string)($shot['shot_id'] ?? '') . ' 的完整台词无法在当前时长内自然说完。原文已保留，请拆分该镜头或调整明确指定的时长后新建版本', 409);
            }
            $part['storyboard'][$index]['recommended_duration_seconds'] = $needed;
        }
        return $part;
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

    /** A normally returned beat must not fail only because the optional
     * screenplay extension used another shape or was omitted. Keep its
     * locked events as the fallback; never invent dialogue. */
    public static function normalizeSceneScripts(array $plan, array $request): array
    {
        if ((int)($request['_input_contract_version'] ?? 0) < 16 || !is_array($plan['scene_beats'] ?? null)) return $plan;
        foreach ($plan['scene_beats'] as $index => $beat) {
            if (!is_array($beat)) continue;
            $script = is_array($beat['scene_script'] ?? null) ? $beat['scene_script'] : [];
            $rawActions = $script['action'] ?? $script['actions'] ?? [];
            if (is_string($rawActions)) $rawActions = [$rawActions];
            $actions = is_array($rawActions) ? array_values(array_filter(array_map(
                static fn($item) => is_string($item) ? trim($item) : '', $rawActions),
                static fn($item) => $item !== '')) : [];
            $source = ($beat['scene_script_source'] ?? '') === 'beat_fallback'
                ? 'beat_fallback' : ($actions ? 'model' : 'beat_fallback');
            if (!$actions) {
                $actions = array_values(array_filter(array_map(
                    static fn($item) => is_string($item) ? trim($item) : '', (array)($beat['key_events'] ?? [])),
                    static fn($item) => $item !== ''));
            }
            $rawDialogue = $script['dialogue'] ?? $script['dialogue_lines'] ?? [];
            if (is_string($rawDialogue)) $rawDialogue = [$rawDialogue];
            $dialogue = [];
            foreach ((is_array($rawDialogue) ? $rawDialogue : []) as $line) {
                if (is_array($line)) {
                    $speaker = trim((string)($line['speaker'] ?? $line['character'] ?? $line['role'] ?? ''));
                    $text = trim((string)($line['text'] ?? $line['line'] ?? $line['content'] ?? ''));
                } elseif (is_string($line) && preg_match('/^([^：:]{1,20})[：:]\s*(.+)$/u', trim($line), $match)) {
                    $speaker = trim($match[1]); $text = trim($match[2]);
                } else continue;
                if ($speaker !== '' && $text !== '') $dialogue[] = ['speaker' => $speaker, 'text' => $text];
            }
            $plan['scene_beats'][$index]['scene_script'] = ['action' => $actions, 'dialogue' => $dialogue];
            $plan['scene_beats'][$index]['scene_script_source'] = $source;
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
            ShortDramaSceneBinding::assertBeat($beat, $plan, $request);
            if ((int)($request['_input_contract_version'] ?? 0) >= 15) {
                $strict = (int)($request['_input_contract_version'] ?? 0) === 15;
                $script = $beat['scene_script'] ?? null;
                if (!is_array($script) || !is_array($script['action'] ?? null) || !$script['action']
                    || ($strict && count($script['action']) > 12)
                    || !is_array($script['dialogue'] ?? null) || ($strict && count($script['dialogue']) > 12)) {
                    throw new RuntimeException('第' . ($index + 1) . '场缺少可拍动作或对白结构，请先补全分场剧本', 422);
                }
                foreach ($script['action'] as $action) {
                    if (!is_string($action) || trim($action) === '' || ($strict && mb_strlen($action) > 240)) {
                        throw new RuntimeException('第' . ($index + 1) . '场动作需为简短、具体的可拍内容', 422);
                    }
                }
                foreach ($script['dialogue'] as $line) {
                    if (!is_array($line) || !is_string($line['speaker'] ?? null) || trim($line['speaker']) === ''
                        || !is_string($line['text'] ?? null) || trim($line['text']) === ''
                        || ($strict && mb_strlen($line['text']) > 240)) {
                        throw new RuntimeException('第' . ($index + 1) . '场对白须有说话人和台词', 422);
                    }
                }
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

    /** Reconcile provisional numeric budgets before any shot text is generated.
     * Keep each scene's proposed total; explicit timelines remain authoritative. */
    public static function normalizeSkeletonBudgets(array $plan, array $request): array
    {
        if (ShortDramaInputContract::providerTiming($request)) {
            return self::normalizeDefaultSceneTotals(self::splitDefaultOversizedShots($plan, $request), $request);
        }
        if (!empty($plan['storyboard']) || !empty(ShortDramaEpisodeDuration::policy($request)['timeline_segments'])) return $plan;
        $rule = ShortDramaShotDuration::rule($request);
        $min = (float)$rule['min_seconds']; $max = (float)$rule['max_seconds'];
        foreach ((array)($plan['scene_beats'] ?? []) as $index => $beat) {
            $original = $beat['shot_durations'] ?? [];
            if (!is_array($original) || !$original) continue;
            $durations = [];
            foreach ($original as $duration) {
                if (!is_numeric($duration) || !is_finite((float)$duration) || $duration <= 0) throw new RuntimeException('骨架分镜时长须为有效正数', 422);
                $count = (int)ceil($duration / $max);
                if ($count > 40) throw new RuntimeException('场景时间预算超出处理范围', 422);
                array_push($durations, ...array_fill(0, $count, (float)$duration / $count));
            }
            $total = array_sum($durations);
            if ($total < $min || count($durations) > 40) throw new RuntimeException('场景时间预算无法满足片段范围，请调整该场景时长', 422);
            while (count($durations) > 1 && count($durations) * $min > $total + 0.001) {
                $smallest = array_search(min($durations), $durations, true);
                $neighbor = $smallest === count($durations) - 1 ? $smallest - 1 : $smallest + 1;
                $durations[$neighbor] += $durations[$smallest];
                array_splice($durations, $smallest, 1);
            }
            foreach ($durations as $i => $duration) {
                if ($duration >= $min) continue;
                $needed = $min - $duration;
                foreach ($durations as $j => $available) {
                    if ($i === $j) continue;
                    $move = min($needed, max(0, $available - $min));
                    $durations[$j] -= $move; $durations[$i] += $move; $needed -= $move;
                    if ($needed <= 0.001) break;
                }
            }
            $plan['scene_beats'][$index]['shot_durations'] = $durations;
            $plan['scene_beats'][$index]['duration_seconds'] = $total;
        }
        return $plan;
    }

    /** Split only oversized provisional shots. Keep scene totals, order and all
     * creative content; invalid or user-locked timing still goes to repair. */
    private static function splitDefaultOversizedShots(array $plan, array $request): array
    {
        $policy = ShortDramaEpisodeDuration::policy($request);
        if (($policy['source'] ?? '') !== 'default' || !empty($policy['timeline_segments'])
            || !empty($plan['storyboard']) || !is_array($plan['scene_beats'] ?? null)) return $plan;
        $rule = ShortDramaShotDuration::rule($request);
        $min = (float)$rule['min_seconds']; $max = (float)$rule['max_seconds'];
        $updated = $plan;
        $episodeTotal = 0.0;
        foreach ($plan['scene_beats'] as $index => $beat) {
            if (!is_array($beat)) return $plan;
            $original = $beat['shot_durations'] ?? null;
            if (!is_array($original) || !$original || count($original) > 40) return $plan;
            $durations = [];
            foreach ($original as $duration) {
                if (!is_numeric($duration) || !is_finite((float)$duration) || (float)$duration < $min) return $plan;
                $seconds = (float)$duration;
                if ($seconds <= $max) {
                    $durations[] = $duration;
                    continue;
                }
                $count = (int)ceil($seconds / $max);
                if ($count > 40 || count($durations) + $count > 40) return $plan;
                $part = $seconds / $count;
                if ($part < $min) return $plan;
                array_push($durations, ...array_fill(0, $count, $part));
            }
            if (count($durations) > 40) return $plan;
            $episodeTotal += array_sum($original);
            $updated['scene_beats'][$index]['shot_durations'] = $durations;
        }
        if (is_numeric($policy['max_seconds'] ?? null)
            && $episodeTotal > (float)$policy['max_seconds'] + 0.001) return $plan;
        return $updated;
    }

    /** Correct derived sums only. Shot timing and user-locked totals remain authoritative. */
    private static function normalizeDefaultSceneTotals(array $plan, array $request): array
    {
        $policy = ShortDramaEpisodeDuration::policy($request);
        if (($policy['source'] ?? '') !== 'default' || !empty($policy['timeline_segments'])
            || !empty($plan['storyboard']) || !is_array($plan['scene_beats'] ?? null)) return $plan;
        $rule = ShortDramaShotDuration::rule($request);
        $totals = [];
        foreach ($plan['scene_beats'] as $index => $beat) {
            $durations = $beat['shot_durations'] ?? null;
            if (!is_array($durations) || !$durations || count($durations) > 40) return $plan;
            foreach ($durations as $duration) {
                if (!is_numeric($duration) || !is_finite((float)$duration)
                    || !ShortDramaShotDuration::contains($duration, $rule)) return $plan;
            }
            $totals[$index] = array_sum($durations);
        }
        // Do not conceal an over-budget plan by changing just its displayed total.
        if (!$totals || !is_numeric($policy['max_seconds'] ?? null)
            || array_sum($totals) > (float)$policy['max_seconds'] + 0.001) return $plan;
        foreach ($totals as $index => $total) {
            if (!is_numeric($plan['scene_beats'][$index]['duration_seconds'] ?? null)
                || abs((float)$plan['scene_beats'][$index]['duration_seconds'] - $total) > 0.001) {
                $plan['scene_beats'][$index]['duration_seconds'] = $total;
            }
        }
        return $plan;
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
        if (ShortDramaDialogueSplit::enabled($request)) {
            // Also used by partial checkpoints. Whole-episode validation belongs
            // after every scene has been assembled, not while stamping a prefix.
            $start = (float)$timeline[0]['start_seconds'];
            foreach ($shots as &$shot) {
                $end = $start + (float)$shot['recommended_duration_seconds'];
                $shot['start_seconds'] = $start; $shot['end_seconds'] = $end;
                $shot['time_range'] = self::timeRangeLabel($start, $end); $start = $end;
            }
            unset($shot);
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

    public static function assertPart(array $part, array $plan, array $beat, array $ids, array $durations, bool $checkDialogue = true): void
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
            ShortDramaSceneBinding::assertShot($shot, $plan);
            if (!is_string($dialogue)) throw new RuntimeException('dialogue必须是带角色名的台词字符串，不能返回数组或对象', 422);
            // A deliberately generous guard catches impossible delivery, not acting style.
            $characters = self::dialogueCharacterCount($dialogue);
            if ($checkDialogue && $characters > $durations[$index] * 8) throw new RuntimeException('本段对白过密，请保留关键含义并缩短台词，为动作和停顿留出时间', 422);
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
