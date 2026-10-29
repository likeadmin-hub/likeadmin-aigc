<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Review saved narrative in bounded blocks. Never ask a model to reproduce a production plan. */
final class ShortDramaEpisodeReview
{
    private const SHOT_FIELDS = ['shot_id','scene_ref_id','subject_ref_ids','visual_description','dialogue','voice_role','speech_type','recommended_duration_seconds'];

    public static function projection(array $plan): array
    {
        $result = ShortDramaPlanningContext::lockedStory($plan);
        $result['storyboard'] = array_map(static fn($shot) => array_intersect_key($shot, array_flip(self::SHOT_FIELDS)), (array)($plan['storyboard'] ?? []));
        return $result;
    }

    public static function generate(array $base, array $request, callable $call, ?callable $progress = null, ?callable $checkpoint = null, array $saved = []): array
    {
        // Persist the complete draft before the first review/provider call.
        // Technical failures must still propagate; a checkpoint is not a success receipt.
        if (ShortDramaInputContract::shotFieldRecovery($request) && $checkpoint && !empty($base['storyboard'])) {
            $signature = ShortDramaReviewCheckpoint::signature($base, $request);
            if (($saved['resume']['signature'] ?? '') !== $signature) $checkpoint($base, 0, ['signature'=>$signature]);
        }
        try {
            return ShortDramaReviewOutcome::complete(self::generateReviewed($base, $request, $call, $progress, $checkpoint, $saved), $request);
        } catch (ShortDramaReviewDeferred|ShortDramaScriptConflict $error) {
            if (!ShortDramaReviewOutcome::enabled($request)) throw $error;
            $plan = $error instanceof ShortDramaReviewDeferred ? ($error->plan ?? $base) : $base;
            return ShortDramaReviewOutcome::pending($plan, $request, '剧本已保存，本次剧情复核未通过；请查看问题并局部修复',
                $error instanceof ShortDramaReviewDeferred && $error->issues ? $error->issues : [['message'=>$error->getMessage(),'shot_ids'=>[]]]);
        }
    }

    private static function generateReviewed(array $base, array $request, callable $call, ?callable $progress, ?callable $checkpoint, array $saved): array
    {
        if (empty($base['storyboard'])) throw new RuntimeException('缺少可复核的已保存分镜', 422);
        $signature = ShortDramaReviewCheckpoint::signature($base, $request);
        if (ShortDramaContentAcceptance::enabled($request)) {
            $base = ShortDramaContentAcceptance::generate($base, $request, $call);
        }
        $context = (array)($request['series_context'] ?? []);
        $context['_server_owned_state'] = true;
        $context['_canonical_state'] = ShortDramaContentAcceptance::enabled($request);
        $context['_bounded_memory'] = (int)($request['_input_contract_version'] ?? 0) >= 7;
        // Runtime-only provenance. A previous block is not a previous episode.
        $context['_reviewed_block_state_keys'] = [];
        $context['current_episode'] = array_intersect_key((array)($context['current_episode'] ?? []),
            array_flip(['episode_number','title','story_outline','conflict_point','ending_hook']));
        $context['outline'] = ShortDramaPlanningContext::lockedStory((array)($context['outline'] ?? []));
        $previousDigest = (string)($context['continuity']['previous_digest'] ?? '');
        $dependencyQuery = ShortDramaNarrativeMemory::query(['current_episode'=>$context['current_episode'], 'script'=>self::projection($base)]);
        $dependency = ShortDramaNarrativeMemory::dependency((array)($context['continuity'] ?? []), $dependencyQuery);
        $result = $base; $summaries = []; $warnings = []; $patches = []; $auditCorrections = []; $unverified = []; $observed = []; $pendingReview = false;
        $shots = array_values($base['storyboard']);
        $start = 0;
        $resume = (array)($saved['resume'] ?? []);
        if (($saved['protocol_version'] ?? 0) === 3 && ($resume['signature'] ?? '') === $signature
            && (int)($saved['reviewed_shots'] ?? -1) >= 0 && (int)$saved['reviewed_shots'] <= count($shots)
            && array_column((array)($saved['plan']['storyboard'] ?? []), 'shot_id') === array_column($shots, 'shot_id')
            && isset($resume['context'], $resume['summaries'], $resume['warnings'], $resume['patches'], $resume['auditCorrections'], $resume['unverified'], $resume['observed'])) {
            $start = (int)$saved['reviewed_shots']; $result = $saved['plan']; $context = $resume['context'];
            $summaries = $resume['summaries']; $warnings = $resume['warnings']; $patches = $resume['patches'];
            $auditCorrections = $resume['auditCorrections']; $unverified = $resume['unverified']; $observed = $resume['observed'];
        }
        $save = static function(int $offset) use ($checkpoint, $signature, &$result, &$context, &$summaries, &$warnings, &$patches, &$auditCorrections, &$unverified, &$observed, &$pendingReview): void {
            // Never mark uncertain blocks as accepted recovery progress.
            if (!$checkpoint || $pendingReview) return;
            $checkpoint($result, $offset, compact('signature','context','summaries','warnings','patches','auditCorrections','unverified','observed'));
        };
        $save($start);
        $process = function (int $offset, int $count) use (&$process, &$result, &$context, &$summaries, &$warnings, &$patches, &$auditCorrections, &$unverified, &$observed, &$pendingReview, $shots, $request, $call, $progress, $save): void {
            $block = self::projection($result);
            unset($block['script_lines'], $block['story_outline']);
            $block['storyboard'] = array_slice($shots, $offset, $count);
            $block['storyboard'] = array_map(static fn($shot) => array_intersect_key($shot, array_flip(self::SHOT_FIELDS)), $block['storyboard']);
            $context['review_window'] = ['first_shot' => $offset + 1, 'last_shot' => $offset + $count, 'total_shots' => count($shots),
                'instruction' => '当前仅审校本集的一段镜头。仅记录本段已发生的事实；未展示的前后段剧情不算遗漏，不可引用整集摘要推断本段事件。previous.state包含本集前面已核验镜头的最新状态。'];
            $key = 'review_' . $offset . '_' . $count;
            $audit = static function (array $input, ?callable $validate = null) use ($key, $call, &$block, &$context): array {
                return ShortDramaStructuredResponse::audit($input, static fn(array $input): array => $call($key . '_audit_' . substr(hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE)), 0, 16), $input, 4096,
                    static function ($reply) use ($validate, &$block, &$context): void {
                        if ($validate) { $validate($reply); return; }
                        ShortDramaContinuity::assertAuditShape($reply, $block, $context);
                    }));
            };
            $verify = static function (array $review, array $plan) use ($key, $call, &$context, &$result, $offset): array {
                $evidenceContext = $context;
                $evidenceContext['_earlier_episode_shots'] = self::earlierEvidence(array_slice($result['storyboard'], 0, $offset), $review);
                $input = ShortDramaContinuityPatch::meaningMessages($plan, $review, $evidenceContext);
                $reply = ShortDramaStructuredResponse::audit($input, static fn(array $input): array => $call($key . '_evidence_' . substr(hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE)), 0, 16), $input, 4096,
                    static fn($response) => ShortDramaContinuityPatch::verifiedReview($review, $response, $context, [], true)));
                return ShortDramaContinuityPatch::verifiedReview($review, $reply, $context, [], true);
            };
            $blockPatches = [];
            try {
                try {
                    $ledger = ShortDramaContinuity::review($block, $context, (int)($request['episode_number'] ?? 1), $audit, $verify, false, true);
                } catch (RuntimeException $error) {
                    if (!$error instanceof ShortDramaScriptConflict) throw $error;
                    $input = ['system_prompt' => '只修复已保存镜头与最新前集事实的明确冲突，不重写剧本。返回JSON {"patches":[{"shot_id":"原ID","field":"字段名","before":"原值","after":"新值","reason":"明确冲突说明"}]}。'
                        . '仅允许visual_description、dialogue、voice_role、speech_type、scene_ref_id、subject_ref_ids。before必须逐字等于原值，数组保持类型。保留无关剧情、对白和镜头数量；不要修改时长、素材或编号。无明确冲突返回空patches。',
                        'content' => json_encode(['conflict' => $error->getMessage(), 'previous' => $context['continuity'] ?? [],
                            'confirmed' => $context['outline'], 'shots' => $block['storyboard']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                    $reply = ShortDramaStructuredResponse::audit($input, static fn($input) => $call($key . '_patch_' . substr(hash('sha256', json_encode($input)), 0, 16), $input, 4096, static fn($reply) => self::apply($block, $reply, $request)));
                    $candidate = self::apply($block, $reply, $request);
                    if ($candidate === $block) throw $error;
                    $block = $candidate;
                    $ledger = ShortDramaContinuity::review($block, $context, (int)($request['episode_number'] ?? 1), $audit, $verify, false, true);
                    $blockPatches = $reply['patches'];
                }
            } catch (RuntimeException $error) {
                // Exhausted audit-format recovery and capacity errors are local to
                // this block. Smaller blocks keep accepted neighbors intact.
                if (!in_array($error->getCode(), [413, 422], true) || $count <= 1) throw $error;
                $left = intdiv($count, 2); $process($offset, $left); $process($offset + $left, $count - $left);
                return;
            }
            if (($ledger['audit_status'] ?? '') === 'pending_review' && $count > 1) {
                $left = intdiv($count, 2); $process($offset, $left); $process($offset + $left, $count - $left);
                return;
            }
            array_push($patches, ...$blockPatches);
            foreach ($block['storyboard'] as $i => $shot) $result['storyboard'][$offset + $i] = array_replace($result['storyboard'][$offset + $i], $shot);
            foreach ($ledger['state'] as $stateKey => $value) {
                if (!array_key_exists($stateKey, $context['continuity']['state'] ?? []) || $context['continuity']['state'][$stateKey] !== $value) {
                    $context['_reviewed_block_state_keys'][$stateKey] = true;
                }
            }
            $context['continuity']['state'] = $ledger['state'];
            $context['continuity']['open_hooks'] = $ledger['open_hooks'];
            $pendingReview = $pendingReview || ($ledger['audit_status'] ?? '') === 'pending_review';
            $summaries[] = $ledger['summary'];
            array_push($warnings, ...(array)$ledger['warnings']);
            array_push($auditCorrections, ...(array)($ledger['audit_corrections'] ?? []));
            array_push($unverified, ...(array)($ledger['unverified_claims'] ?? []));
            array_push($observed, ...(array)($ledger['observed_events'] ?? []));
            $save($offset + $count);
            if ($progress) $progress('stage', ['status'=>'running','progress'=>min(95, 20 + (int)(75 * ($offset + $count) / count($shots))),
                'current_step'=>'已复核' . ($offset + $count) . '/' . count($shots) . '个分镜，原版本保留']);
        };
        // Keep original unit boundaries even when recovery starts inside a split block.
        for ($offset = $start; $offset < count($shots);) {
            $count = min(4 - ($offset % 4), count($shots) - $offset); $process($offset, $count); $offset += $count;
        }
        // Reflect exact changed excerpts in the prose without paraphrasing unrelated text.
        foreach ($patches as $patch) {
            if (!in_array($patch['field'], ['dialogue','visual_description'], true) || !is_string($patch['before']) || $patch['before'] === '') continue;
            if (is_string($result['story_outline'] ?? null)) $result['story_outline'] = str_replace($patch['before'], $patch['after'], $result['story_outline']);
            foreach ((array)($result['script_lines'] ?? []) as $index => $line) if (is_string($line)) $result['script_lines'][$index] = str_replace($patch['before'], $patch['after'], $line);
        }
        if ($patches && ShortDramaContentAcceptance::enabled($request)) {
            $acceptance = ShortDramaContentAcceptance::inspect($result, $result['content_acceptance']['requirements'], $call, $request);
            if ($acceptance['issues']) {
                if (ShortDramaReviewOutcome::enabled($request)) throw new ShortDramaReviewDeferred('衔接修复后仍有内容问题', $acceptance['issues'], $result);
                throw new RuntimeException('衔接修复后仍有内容问题：' . $acceptance['issues'][0]['message'], 422);
            }
            $result['content_acceptance']['coverage'] = $acceptance['coverage'];
        }
        $result['_continuity'] = ['version'=>1,'episode_number'=>(int)($request['episode_number'] ?? 1),
            'summary'=>implode("\n", $summaries),'state'=>$context['continuity']['state'] ?? [],'open_hooks'=>$context['continuity']['open_hooks'] ?? [],
            'digest'=>ShortDramaContinuity::fingerprint($result),'previous_digest'=>$previousDigest,'warnings'=>array_values(array_unique($warnings)),
            'audit_status'=>$pendingReview ? 'pending_review' : 'verified','review_mode'=>'blocks','review_patches'=>$patches,'audit_corrections'=>$auditCorrections,
            'unverified_claims'=>$unverified,'observed_events'=>$observed,'protocol_version'=>2,
            'content_status'=>'ready','dependency_status'=>$pendingReview ? 'blocked' : 'ready'];
        if ($context['_bounded_memory']) $result['_continuity']['memory_dependency'] = [
            'version'=>ShortDramaNarrativeMemory::VERSION, 'history_available'=>array_key_exists('history', $request['series_context']['continuity'] ?? []),
            'query'=>$dependencyQuery, 'fingerprint'=>$dependency];
        if ($context['_bounded_memory'] && array_key_exists('source_history', $request['series_context']['continuity'] ?? [])) {
            $result['_continuity']['memory_dependency']['source_history_available'] = true;
        }
        return $result;
    }

    /** Retrieve bounded, original narrative context. Never include future shots
     * or production prompts, and never turn historical context into a new event. */
    public static function earlierEvidence(array $earlier, array $review): array
    {
        $query = implode(' ', array_map(static fn($item) => ShortDramaText::value($item['after'] ?? $item['description'] ?? ''),
            array_merge($review['changes'] ?? [], $review['hooks'] ?? [])));
        $grams = static function (string $text): array {
            $text = preg_replace('/[\s\p{P}]+/u', '', $text) ?? $text;
            $out = [];
            for ($i = 0, $n = mb_strlen($text) - 1; $i < $n; $i++) $out[mb_substr($text, $i, 2)] = true;
            return $out;
        };
        $terms = $grams($query); $ranked = [];
        foreach ($earlier as $index => $shot) {
            $text = (string)($shot['visual_description'] ?? '') . (string)($shot['dialogue'] ?? '');
            $score = count(array_intersect_key($terms, $grams($text)));
            $ranked[] = ['index'=>$index, 'score'=>$score];
        }
        usort($ranked, static fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($b['index'] <=> $a['index']));
        $indices = array_column(array_slice($ranked, 0, 4), 'index'); sort($indices);
        return array_map(static fn($i) => array_intersect_key($earlier[$i], array_flip(self::SHOT_FIELDS)), $indices);
    }

    public static function apply(array $plan, array $reply, array $request = []): array
    {
        if (!isset($reply['patches']) || !is_array($reply['patches']) || !array_is_list($reply['patches'])) throw new RuntimeException('复核修复未返回有效补丁', 422);
        $allowed = array_diff(self::SHOT_FIELDS, ['shot_id','recommended_duration_seconds']);
        $ids = array_column($plan['storyboard'], 'shot_id'); $seen = [];
        foreach ($reply['patches'] as $patch) {
            if (!is_array($patch)) throw new RuntimeException('复核补丁格式无效', 422);
            $index = array_search($patch['shot_id'] ?? '', $ids, true); $field = $patch['field'] ?? '';
            $key = ($patch['shot_id'] ?? '') . ':' . $field;
            if ($index === false || !in_array($field, $allowed, true) || isset($seen[$key])
                || !array_key_exists('before', $patch) || !array_key_exists('after', $patch)
                || ($plan['storyboard'][$index][$field] ?? null) !== $patch['before'] || !is_string($patch['reason'] ?? null) || trim($patch['reason']) === '') {
                throw new RuntimeException('复核补丁越界或原文已变化，原版本保留', 422);
            }
            if ($field === 'subject_ref_ids') {
                if (!is_array($patch['after']) || !array_is_list($patch['after']) || array_filter($patch['after'], static fn($id) => !is_string($id))
                    || array_diff($patch['after'], array_column($plan['subjects'], 'id'))) throw new RuntimeException('复核补丁引用未知主体', 422);
            } elseif (!is_string($patch['after'])) throw new RuntimeException('复核补丁字段类型无效', 422);
            if ($field === 'scene_ref_id' && !in_array($patch['after'], array_column($plan['locations'], 'id'), true)) throw new RuntimeException('复核补丁引用未知场景', 422);
            if ($field === 'visual_description' && trim($patch['after']) === '') throw new RuntimeException('复核补丁不能清空画面', 422);
            if ($field === 'speech_type' && !in_array($patch['after'], ['character','narration','none'], true)) throw new RuntimeException('复核补丁说话类型无效', 422);
            $plan['storyboard'][$index][$field] = $patch['after']; $seen[$key] = true;
        }
        // Preserve the generation timing contract. Explicit duration edits are
        // still forbidden above; character count is not measured speech time.
        foreach (ShortDramaInputContract::structuredValidation($request ?: $plan) ? [] : $plan['storyboard'] as $shot) {
            if (ShortDramaDialogueSplit::requiredSeconds((string)($shot['dialogue'] ?? '')) > (float)($shot['recommended_duration_seconds'] ?? 0)) throw new RuntimeException('复核后的台词无法放入原镜头时长，请调整该镜头', 409);
        }
        $dialogue = ShortDramaDialogueContract::prepare(['subjects'=>$plan['subjects'],'storyboard'=>$plan['storyboard']]);
        if ($dialogue['issues']) throw new RuntimeException('复核补丁缺少明确的台词归属，原版本保留', 422);
        return $plan;
    }
}
