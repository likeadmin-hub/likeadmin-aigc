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

    public static function generate(array $base, array $request, callable $call, ?callable $progress = null): array
    {
        if (empty($base['storyboard'])) throw new RuntimeException('缺少可复核的已保存分镜', 422);
        $context = (array)$request['series_context'];
        // Runtime-only provenance. A previous block is not a previous episode.
        $context['_reviewed_block_state_keys'] = [];
        $context['current_episode'] = array_intersect_key((array)($context['current_episode'] ?? []),
            array_flip(['episode_number','title','story_outline','conflict_point','ending_hook']));
        $context['outline'] = ShortDramaPlanningContext::lockedStory((array)($context['outline'] ?? []));
        $previousDigest = (string)($context['continuity']['previous_digest'] ?? '');
        $result = $base; $summaries = []; $warnings = []; $patches = []; $pendingReview = false;
        $shots = array_values($base['storyboard']);
        $process = function (int $offset, int $count) use (&$process, &$result, &$context, &$summaries, &$warnings, &$patches, &$pendingReview, $shots, $request, $call, $progress): void {
            $block = self::projection($result);
            unset($block['script_lines'], $block['story_outline']);
            $block['storyboard'] = array_slice($shots, $offset, $count);
            $block['storyboard'] = array_map(static fn($shot) => array_intersect_key($shot, array_flip(self::SHOT_FIELDS)), $block['storyboard']);
            $context['review_window'] = ['first_shot' => $offset + 1, 'last_shot' => $offset + $count, 'total_shots' => count($shots),
                'instruction' => '当前仅审校本集的一段镜头。仅记录本段已发生的事实；未展示的前后段剧情不算遗漏，不可引用整集摘要推断本段事件。previous.state包含本集前面已核验镜头的最新状态。'];
            $key = 'review_' . $offset . '_' . $count;
            $audit = static fn(array $input, ?callable $validate = null): array => $call($key . '_audit_' . substr(hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE)), 0, 16), $input, 4096,
                static function ($reply) use ($validate): void {
                    if ($validate) $validate($reply);
                    if (isset($reply['evidence_patches']) && is_array($reply['evidence_patches'])) return;
                    if (!is_string($reply['summary'] ?? null) || trim($reply['summary']) === '') throw new RuntimeException('连续性摘要缺失', 422);
                    foreach (['changes','hooks','warnings'] as $field) if (!is_array($reply[$field] ?? null)) throw new RuntimeException('连续性检查结构不完整', 422);
                });
            $verify = static function (array $review, array $plan) use ($key, $call, &$context): array {
                $input = ShortDramaContinuityPatch::meaningMessages($plan, $review, $context);
                $reply = $call($key . '_evidence_' . substr(hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE)), 0, 16), $input, 4096,
                    static fn($response) => ShortDramaContinuityPatch::verifiedReview($review, $response, $context, []));
                return ShortDramaContinuityPatch::verifiedReview($review, $reply, $context, []);
            };
            try {
                try {
                    $ledger = ShortDramaContinuity::review($block, $context, (int)($request['episode_number'] ?? 1), $audit, $verify, false);
                } catch (RuntimeException $error) {
                    if ($error->getCode() !== 409) throw $error;
                    $input = ['system_prompt' => '只修复已保存镜头与最新前集事实的明确冲突，不重写剧本。返回JSON {"patches":[{"shot_id":"原ID","field":"字段名","before":"原值","after":"新值","reason":"明确冲突说明"}]}。'
                        . '仅允许visual_description、dialogue、voice_role、speech_type、scene_ref_id、subject_ref_ids。before必须逐字等于原值，数组保持类型。保留无关剧情、对白和镜头数量；不要修改时长、素材或编号。无明确冲突返回空patches。',
                        'content' => json_encode(['conflict' => $error->getMessage(), 'previous' => $context['continuity'] ?? [],
                            'confirmed' => $context['outline'], 'shots' => $block['storyboard']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                    $reply = $call($key . '_patch', $input, 4096, static fn($reply) => self::apply($block, $reply));
                    $candidate = self::apply($block, $reply);
                    if ($candidate === $block) throw $error;
                    $ledger = ShortDramaContinuity::review($candidate, $context, (int)($request['episode_number'] ?? 1), $audit, $verify, false);
                    $block = $candidate;
                    array_push($patches, ...$reply['patches']);
                }
            } catch (RuntimeException $error) {
                if ($error->getCode() !== 413 || $count <= 1) throw $error;
                $left = intdiv($count, 2); $process($offset, $left); $process($offset + $left, $count - $left);
                return;
            }
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
            if ($progress) $progress('stage', ['status'=>'running','progress'=>min(95, 20 + (int)(75 * ($offset + $count) / count($shots))),
                'current_step'=>'已复核' . ($offset + $count) . '/' . count($shots) . '个分镜，原版本保留']);
        };
        for ($offset = 0; $offset < count($shots); $offset += 4) $process($offset, min(4, count($shots) - $offset));
        // Reflect exact changed excerpts in the prose without paraphrasing unrelated text.
        foreach ($patches as $patch) {
            if (!in_array($patch['field'], ['dialogue','visual_description'], true) || !is_string($patch['before']) || $patch['before'] === '') continue;
            if (is_string($result['story_outline'] ?? null)) $result['story_outline'] = str_replace($patch['before'], $patch['after'], $result['story_outline']);
            foreach ((array)($result['script_lines'] ?? []) as $index => $line) if (is_string($line)) $result['script_lines'][$index] = str_replace($patch['before'], $patch['after'], $line);
        }
        $result['_continuity'] = ['version'=>1,'episode_number'=>(int)($request['episode_number'] ?? 1),
            'summary'=>implode("\n", $summaries),'state'=>$context['continuity']['state'] ?? [],'open_hooks'=>$context['continuity']['open_hooks'] ?? [],
            'digest'=>ShortDramaContinuity::fingerprint($result),'previous_digest'=>$previousDigest,'warnings'=>array_values(array_unique($warnings)),
            'audit_status'=>$pendingReview ? 'pending_review' : 'verified','review_mode'=>'blocks','review_patches'=>$patches];
        return $result;
    }

    public static function apply(array $plan, array $reply): array
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
        foreach ($plan['storyboard'] as $shot) {
            if (ShortDramaDialogueSplit::requiredSeconds((string)($shot['dialogue'] ?? '')) > (float)($shot['recommended_duration_seconds'] ?? 0)) throw new RuntimeException('复核后的台词无法放入原镜头时长，请调整该镜头', 409);
        }
        $dialogue = ShortDramaDialogueContract::prepare(['subjects'=>$plan['subjects'],'storyboard'=>$plan['storyboard']]);
        if ($dialogue['issues']) throw new RuntimeException('复核补丁缺少明确的台词归属，原版本保留', 422);
        return $plan;
    }
}
