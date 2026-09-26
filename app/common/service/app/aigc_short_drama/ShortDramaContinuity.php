<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Versioned narrative facts, not media/runtime fields. Unknown is never invented. */
final class ShortDramaContinuity
{
    public static function fingerprint(array $plan): string
    {
        return hash('sha256', json_encode(self::narrative($plan), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function narrative(array $plan): array
    {
        $value = array_intersect_key($plan, array_flip(['title', 'story_outline', 'script_lines']));
        foreach (['subjects', 'locations'] as $key) {
            $value[$key] = array_map(static fn($item) => array_intersect_key((array)$item,
                array_flip(['id', 'name', 'category', 'description', 'age', 'role', 'background', 'motivation', 'arc'])), (array)($plan[$key] ?? []));
        }
        $value['storyboard'] = array_map(static fn($shot) => array_intersect_key((array)$shot,
            array_flip(['shot_id', 'scene_ref_id', 'subject_ref_ids', 'visual_description', 'dialogue', 'voice_role', 'speech_type'])), (array)($plan['storyboard'] ?? []));
        // Normalization may reorder object keys without changing the story.
        $sort = static function (array $value) use (&$sort): array {
            if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) ksort($value);
            foreach ($value as &$item) if (is_array($item)) $item = $sort($item);
            unset($item);
            return $value;
        };
        return $sort($value);
    }

    public static function context(array $previous): array
    {
        $last = $previous ? $previous[count($previous) - 1] : [];
        return ['version' => 1, 'state' => (array)($last['state'] ?? []), 'open_hooks' => (array)($last['open_hooks'] ?? []),
            'previous_digest' => (string)($last['digest'] ?? ''),
            'recent_episodes' => array_map(static fn($item) => array_intersect_key($item, array_flip(['episode_number', 'summary', 'digest'])), array_slice($previous, -2))];
    }

    public static function dependencyStatus(array $rows, array $current): array
    {
        $previousDigest = ''; $upstreamChanged = false;
        foreach ($rows as &$row) {
            $saved = is_array($row['continuity_json'] ?? null) ? $row['continuity_json'] : (json_decode((string)($row['continuity_json'] ?? ''), true) ?: []);
            $version = $current[(int)($row['production_project_id'] ?? 0)] ?? [];
            $ledger = (array)($version['ledger'] ?? $saved);
            $digest = (string)($version['narrative_digest'] ?? $ledger['digest'] ?? '');
            $outdated = ($ledger['version'] ?? 0) === 1 && (
                ($ledger['digest'] ?? '') !== $digest
                || ($previousDigest !== '' && ($ledger['previous_digest'] ?? '') !== $previousDigest)
                || $upstreamChanged);
            // An explicit regeneration may be pending while its previous
            // successful version remains viewable. Do not block that attempt.
            $row['needs_review'] = $outdated && ($row['status'] ?? '') === 'success';
            $row['review_reason'] = $row['needs_review'] ? '本集或前集剧情已修改，需复核连续性；原剧本和素材已保留' : '';
            if (!$outdated && $ledger) $row['continuity_json'] = json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $upstreamChanged = $upstreamChanged || $outdated;
            $previousDigest = $digest;
        }
        unset($row);
        return $rows;
    }

    public static function messages(array $plan, array $context): array
    {
        return ['system_prompt' => '你是短剧连续性审校员，只返回 JSON。不得改写剧本。只记录正文中明确发生、有镜头证据的事实。未知内容不推断。检查上一集状态、本集大纲、已确认人物和规则是否矛盾，检查是否提前揭露未来剧情。语义疑点放 warnings，不要伪造事实。',
            'content' => json_encode([
                'previous' => $context['continuity'] ?? [],
                'confirmed' => ShortDramaPlanningContext::lockedStory((array)($context['outline'] ?? [])),
                'current_episode' => $context['current_episode'] ?? [],
                'series_roadmap' => $context['series_roadmap'] ?? [],
                'script' => self::narrative($plan),
                'response_contract' => [
                    'summary' => '本集已发生事件的简短摘要，不写未来剧情',
                    'changes' => [['entity_id' => 'subjects或locations中的id，也可为world', 'field' => '稳定状态键，如location/knows_secret/injury/item_owner',
                        'before' => 'previous.state里已有的原值；首次记录用null', 'after' => '正文中明确的新值', 'shot_id' => '证据镜头id', 'quote' => '该镜头visual_description或dialogue中的原文片段']],
                    'hooks' => [['id' => '稳定伏笔标识', 'description' => '伏笔内容', 'status' => 'open或resolved', 'shot_id' => '证据镜头id', 'quote' => '镜头原文片段']],
                    'warnings' => ['具体连续性疑点，无疑点则空数组'],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }

    /** At most two reference corrections, never rewrite or relax the script. */
    public static function review(array $plan, array $context, int $episode, callable $call, ?callable $verifyMeaning = null): array
    {
        $input = self::messages($plan, $context);
        $review = [];
        $originalReview = [];
        $patchBase = [];
        $mutableEvidence = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $review = $call($input);
                if ($attempt) $review = isset($review['evidence_patches'])
                    ? self::applyEvidencePatches($patchBase, $review, $plan, $mutableEvidence)
                    : self::preserveRepairFacts($originalReview, $review, $plan);
                $ledger = self::ledger($review, $plan, $context, $episode);
                if ($verifyMeaning) {
                    $verified = $verifyMeaning($review, $plan);
                    if (is_array($verified)) $ledger = self::ledger($verified, $plan, $context, $episode);
                }
                return $ledger + ['review_repairs' => $attempt];
            } catch (RuntimeException $error) {
                if (!in_array($error->getCode(), [422, 460], true)) throw $error;
                if ($error->getCode() === 460) {
                    if ($attempt >= 2) throw $error;
                    $diagnostics = json_decode(substr($error->getMessage(), strpos($error->getMessage(), '[')), true) ?: [];
                    foreach ($diagnostics as $issue) $mutableEvidence[$issue['collection'] . ':' . $issue['index']] = true;
                }
                // A repaired evidence reference can reveal a later within-
                // episode chain error or leave an invalid quote. Allow one narrowly diagnosed follow-up,
                // never a third generic rewrite or a cross-episode override.
                $repairableReference = $error->getCode() === 460 || str_starts_with($error->getMessage(), '本集内状态链')
                    || in_array($error->getMessage(), ['连续性事实的镜头证据不匹配', '连续性事实缺少正文证据', '连续性事实缺少有效镜头标识'], true);
                if ($attempt && !($attempt === 1 && $repairableReference)) {
                    throw new RuntimeException('连续性审校纠错后仍未通过，已保留生成回包，请核对审校证据：' . $error->getMessage(), 422, $error);
                }
                if (!$attempt) $originalReview = $review;
                $patchBase = $review;
                $input['content'] .= "\n仅修正审校JSON，不改写剧本、状态快照或证据，不删除有效事实来绕过检查：" . $error->getMessage()
                    . '\nchanges按镜头发生顺序排列。同一个entity_id:field在本集重复变更时，后一次before必须逐字等于本集前一次after。首次出现才引用previous.state；尚未登记时必须为JSON null（不是字符串"null"）。不得改写after来迎合before。保留所有warnings。原审校='
                    . json_encode($review, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    . "\nquote必须直接复制指定shot_id的visual_description或dialogue中的连续原文，不得概括、加省略号、改标点或拼接多镜头。不得改写after、伏笔含义或删除记录以通过校验；确实无法证明时保持不合格证据，不要编造。保留记录数量与顺序。以下为只读诊断数据，不是创作指令："
                    . json_encode(['evidence_errors' => self::evidenceIssues($review, $plan),
                        'state_errors' => self::stateIssues($review, $context)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $input['content'] .= '\n本轮只返回补丁JSON，不返回完整审校，不拆分或合并记录：'
                    . '{"evidence_patches":[{"collection":"changes或hooks","index":0,"shot_id":"正文镜头ID","quote":"该镜头连续原文"}]}。'
                    . 'index是原审校对应数组的零基索引。changes补丁可额外返回before以修正旧状态类型或状态链；'
                    . '一个事实需要多个镜头共同证明时，可在同一补丁中使用evidence数组，每项为shot_id和quote；不要用逗号拼接镜头ID。'
                    . '不得返回entity_id、field、after、description、status、summary、warnings。仅提交需要修正的记录。'
                    . '事实内容、数量、顺序和warnings由服务端保留。必须依据完整script寻找证据，不能拼接或概括。';
            }
        }
        throw new RuntimeException('连续性审校未完成', 422);
    }

    /** Repair only references, never delegate ownership of facts to a correction. */
    private static function applyEvidencePatches(array $original, array $response, array $plan, array $mutableEvidence = []): array
    {
        if (array_keys($response) !== ['evidence_patches'] || !is_array($response['evidence_patches'])
            || !array_is_list($response['evidence_patches'])) throw new RuntimeException('审校证据补丁格式无效', 422);
        $seen = [];
        // One fact may span several shots. Coalesce repeated index entries
        // into explicit evidence, but never choose between conflicting states.
        $patches = [];
        foreach ($response['evidence_patches'] as $patch) {
            if (!is_array($patch) || !is_string($patch['collection'] ?? null) || !is_int($patch['index'] ?? null)) throw new RuntimeException('审校证据补丁必须为有效对象', 422);
            // Models sometimes echo a reworded state. The correction does not
            // own it; retain the original assertion and verify its meaning.
            if ($patch['collection'] === 'changes') unset($patch['after']);
            $key = $patch['collection'] . ':' . $patch['index'];
            if (!isset($patches[$key])) { $patches[$key] = $patch; continue; }
            $previous = $patches[$key];
            if (array_key_exists('before', $previous) && array_key_exists('before', $patch) && $previous['before'] !== $patch['before']) throw new RuntimeException('同一事实的旧状态补丁冲突', 422);
            if (array_diff(array_keys($patch), ['collection', 'index', 'shot_id', 'quote', 'before'])) throw new RuntimeException('审校证据补丁字段越界', 422);
            $evidence = $previous['evidence'] ?? [['shot_id' => $previous['shot_id'] ?? '', 'quote' => $previous['quote'] ?? '']];
            $evidence[] = ['shot_id' => $patch['shot_id'] ?? '', 'quote' => $patch['quote'] ?? ''];
            $patches[$key]['evidence'] = $evidence;
            if (array_key_exists('before', $patch)) $patches[$key]['before'] = $patch['before'];
        }
        $shots = array_column((array)($plan['storyboard'] ?? []), null, 'shot_id');
        foreach ($patches as $patch) {
            if (!is_array($patch)) throw new RuntimeException('审校证据补丁必须为对象', 422);
            $group = $patch['collection'] ?? ''; $index = $patch['index'] ?? null;
            if (!in_array($group, ['changes', 'hooks'], true) || !is_int($index) || $index < 0
                || !isset($original[$group][$index]) || !is_array($original[$group][$index])) {
                throw new RuntimeException('审校证据补丁引用不存在的记录', 422);
            }
            $allowed = ['collection', 'index', 'shot_id', 'quote', 'evidence'];
            if ($group === 'changes') $allowed[] = 'before';
            if (array_diff(array_keys($patch), $allowed) || isset($seen[$group . ':' . $index])) {
                throw new RuntimeException('审校证据补丁字段越界或索引重复', 422);
            }
            $seen[$group . ':' . $index] = true;
            $verified = true;
            try { self::evidence($original[$group][$index], $shots); }
            catch (RuntimeException $error) { $verified = false; }
            if (isset($mutableEvidence[$group . ':' . $index])) $verified = false;
            if (!$verified && !isset($patch['evidence']) && isset($patch['shot_id'], $patch['quote'])) unset($original[$group][$index]['evidence']);
            foreach (['shot_id', 'quote', 'before', 'evidence'] as $key) {
                if ($verified && $key !== 'before') continue;
                if (array_key_exists($key, $patch)) $original[$group][$index][$key] = $patch[$key];
            }
        }
        return $original;
    }

    /** Diagnose every state reference before paying for a correction, not only
     * the first error encountered after evidence validation. Never persist or
     * silently substitute these expected values. */
    private static function stateIssues(array $review, array $context): array
    {
        $state = (array)($context['continuity']['state'] ?? []); $issues = [];
        foreach ((array)($review['changes'] ?? []) as $index => $item) {
            if (!is_array($item) || !is_string($item['entity_id'] ?? null) || !is_string($item['field'] ?? null)) continue;
            $key = $item['entity_id'] . ':' . $item['field'];
            $expected = $state[$key] ?? null;
            $actual = $item['before'] ?? null;
            if ($expected !== $actual && !( !array_key_exists($key, $state) && $actual === 'null')) {
                $issues[] = ['collection' => 'changes', 'index' => $index, 'key' => $key,
                    'before' => $actual, 'expected_before' => $expected,
                    'reason' => array_key_exists($key, $state) ? '必须逐字引用已登记值或本集前一条after' : '此字段尚未登记，必须为JSON null'];
            }
            if (is_string($item['after'] ?? null)) $state[$key] = $item['after'];
        }
        return $issues;
    }

    /** All evidence failures in one correction, not one paid call per bad quote. */
    public static function evidenceIssues(array $review, array $plan): array
    {
        $shots = array_column((array)($plan['storyboard'] ?? []), null, 'shot_id');
        $issues = [];
        foreach (['changes', 'hooks'] as $group) {
            foreach (is_array($review[$group] ?? null) ? $review[$group] : [] as $index => $item) {
                try { self::evidence($item, $shots); }
                catch (RuntimeException $error) {
                    $id = is_array($item) && is_scalar($item['shot_id'] ?? null) ? (string)$item['shot_id'] : '';
                    $shot = $shots[$id] ?? [];
                    $issues[] = ['path' => $group . '.' . $index, 'shot_id' => $id,
                        'reason' => $error->getMessage(), 'shot_exists' => (bool)$shot,
                        'visual_description' => (string)($shot['visual_description'] ?? ''),
                        'dialogue' => (string)($shot['dialogue'] ?? '')];
                }
            }
        }
        return $issues;
    }

    private static function preserveRepairFacts(array $original, array $repaired, array $plan): array
    {
        $shots = array_column((array)($plan['storyboard'] ?? []), null, 'shot_id');
        foreach (['changes' => ['entity_id', 'field', 'after'], 'hooks' => ['id', 'description', 'status']] as $group => $keys) {
            if (!is_array($original[$group] ?? null)) continue;
            $rows = $repaired[$group] ?? null;
            if (!is_array($rows) || count($original[$group]) !== count($rows)) throw new RuntimeException('审校纠错不得删除或新增事实记录', 422);
            $repaired[$group] = array_values($rows);
            foreach (array_values($original[$group]) as $index => $item) {
                if (!is_array($item)) continue;
                $next = array_values($rows)[$index];
                if (!is_array($next)) throw new RuntimeException('审校纠错事实记录格式无效', 422);
                foreach ($keys as $key) {
                    if (is_string($item[$key] ?? null) && ($next[$key] ?? null) !== $item[$key]) {
                        // Identity must still match in order. The correction
                        // model does not own the original state assertion:
                        // retain it instead of accepting a rewritten value.
                        if ($group === 'changes' && $key === 'after') {
                            $repaired[$group][$index][$key] = $item[$key];
                            continue;
                        }
                        throw new RuntimeException('审校纠错不得改写事实或伏笔含义', 422);
                    }
                }
                try { self::evidence($item, $shots); }
                catch (RuntimeException $error) { continue; }
                // The server owns verified evidence. A repair may expand a
                // correct quote; keep the original verbatim instead of making
                // the entire task fail. Identity/meaning checks above still
                // reject reordered or changed facts, and ledger validates all
                // repaired evidence and before-state values afterwards.
                $repaired[$group][$index]['shot_id'] = $item['shot_id'];
                $repaired[$group][$index]['quote'] = $item['quote'];
            }
        }
        foreach (is_array($original['warnings'] ?? null) ? $original['warnings'] : [] as $warning) {
            if (is_string($warning) && !in_array($warning, (array)($repaired['warnings'] ?? []), true)) {
                throw new RuntimeException('审校纠错不得删除已有连续性疑点', 422);
            }
        }
        return $repaired;
    }

    public static function ledger(array $review, array $plan, array $context, int $episode): array
    {
        if (!is_string($review['summary'] ?? null) || trim($review['summary']) === '') throw new RuntimeException('连续性摘要缺失', 422);
        foreach (['changes', 'hooks', 'warnings'] as $key) {
            if (!is_array($review[$key] ?? null)) throw new RuntimeException('连续性检查结构不完整', 422);
        }
        $state = (array)($context['continuity']['state'] ?? []);
        $hooks = (array)($context['continuity']['open_hooks'] ?? []);
        $ids = array_merge(['world'], array_column((array)($plan['subjects'] ?? []), 'id'), array_column((array)($plan['locations'] ?? []), 'id'));
        $shots = array_column((array)($plan['storyboard'] ?? []), null, 'shot_id');
        $changedInEpisode = [];
        foreach ($review['changes'] as $item) {
            self::evidence($item, $shots);
            if (!in_array($item['entity_id'] ?? '', $ids, true) || !is_string($item['field'] ?? null)
                || !preg_match('~^[a-zA-Z0-9_/\x{4e00}-\x{9fff}-]{1,80}$~u', $item['field'])
                || !is_string($item['after'] ?? null) || mb_strlen($item['after']) > 1200) throw new RuntimeException('剧情状态标识或内容无效', 422);
            $key = $item['entity_id'] . ':' . $item['field'];
            // Some providers serialize the first-registration sentinel as a
            // string. Normalize only this exact token for an absent key;
            // never erase an existing state or infer an unrecorded old value.
            if (!array_key_exists($key, $state) && ($item['before'] ?? null) === 'null') {
                $item['before'] = null;
            }
            if (!array_key_exists($key, $state) && ($item['before'] ?? null) !== null) {
                throw new RuntimeException('连续性审校字段' . $key . '首次登记的before必须为null，不能推断未记录的旧状态', 422);
            }
            if (($state[$key] ?? null) !== ($item['before'] ?? null)) {
                if (isset($changedInEpisode[$key])) {
                    throw new RuntimeException('本集内状态链' . $key . '的before必须逐字等于前一条after：'
                        . json_encode($state[$key], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 422);
                }
                throw new RuntimeException('本集剧情状态与前集不一致，请检查衔接后修改；已完成内容保留', 409);
            }
            $state[$key] = $item['after'];
            $changedInEpisode[$key] = true;
        }
        foreach ($review['hooks'] as $item) {
            self::evidence($item, $shots);
            if (!is_string($item['id'] ?? null) || trim($item['id']) === '' || mb_strlen($item['id']) > 100
                || !is_string($item['description'] ?? null) || !in_array($item['status'] ?? '', ['open', 'resolved'], true)) throw new RuntimeException('伏笔记录格式无效', 422);
            if ($item['status'] === 'resolved') {
                // Some models append the status to an otherwise exact hook
                // identifier. Resolve only an existing, unique base ID; never
                // guess from descriptions or accept an unknown hook.
                if (!isset($hooks[$item['id']]) && str_ends_with($item['id'], '_resolved')) {
                    $baseId = substr($item['id'], 0, -strlen('_resolved'));
                    if (isset($hooks[$baseId])) $item['id'] = $baseId;
                }
                if (!isset($hooks[$item['id']])) throw new RuntimeException('回收了未记录的伏笔，请检查剧情衔接', 409);
                unset($hooks[$item['id']]);
            } else $hooks[$item['id']] = $item['description'];
        }
        // Never silently truncate hard facts. Fail before sending an oversized next context.
        if (count($state) > 1000 || count($hooks) > 500) throw new RuntimeException('剧情状态过多，请分阶段整理后继续', 413);
        $warnings = [];
        foreach ($review['warnings'] as $warning) {
            if (!is_string($warning) || mb_strlen($warning) > 1500) throw new RuntimeException('连续性提示格式无效', 422);
            if (trim($warning) !== '') $warnings[] = trim($warning);
        }
        return ['version' => 1, 'episode_number' => $episode, 'summary' => $review['summary'], 'state' => $state, 'open_hooks' => $hooks,
            'digest' => self::fingerprint($plan), 'previous_digest' => (string)($context['continuity']['previous_digest'] ?? ''), 'warnings' => $warnings,
            'unverified_hook_resolutions' => (array)($review['unverified_hook_resolutions'] ?? [])];
    }

    private static function evidence($item, array $shots): void
    {
        if (is_array($item) && array_key_exists('evidence', $item)) {
            if (!is_array($item['evidence']) || !array_is_list($item['evidence']) || !$item['evidence'] || count($item['evidence']) > 20) throw new RuntimeException('连续性事实多镜头证据格式无效', 422);
            foreach ($item['evidence'] as $proof) {
                if (!is_array($proof) || array_diff(array_keys($proof), ['shot_id', 'quote'])) throw new RuntimeException('连续性事实多镜头证据字段无效', 422);
                self::evidence($proof, $shots);
            }
            return;
        }
        if (!is_array($item) || !is_string($item['quote'] ?? null) || trim($item['quote']) === '') throw new RuntimeException('连续性事实缺少正文证据', 422);
        if (!is_scalar($item['shot_id'] ?? null)) throw new RuntimeException('连续性事实缺少有效镜头标识', 422);
        $shot = $shots[(string)$item['shot_id']] ?? [];
        $text = (string)($shot['visual_description'] ?? '') . "\n" . (string)($shot['dialogue'] ?? '');
        if (!$shot || mb_strpos($text, $item['quote']) === false) throw new RuntimeException('连续性事实的镜头证据不匹配', 422);
    }

    public static function assertRoadmap(array $segments, int $total): void
    {
        if (!$segments || count($segments) > 30) throw new RuntimeException('全剧节奏分配不完整', 422);
        $next = 1;
        foreach ($segments as $segment) {
            if (!is_array($segment) || ($segment['start'] ?? 0) !== $next || !is_int($segment['end'] ?? null)
                || $segment['end'] < $next || $segment['end'] > $total || empty($segment['goal']) || empty($segment['reveal']) || empty($segment['ending'])) {
                throw new RuntimeException('全剧节奏集号、目标或衔接不完整', 422);
            }
            $next = $segment['end'] + 1;
        }
        if ($next !== $total + 1) throw new RuntimeException('全剧节奏未覆盖全部集数', 422);
    }
}
