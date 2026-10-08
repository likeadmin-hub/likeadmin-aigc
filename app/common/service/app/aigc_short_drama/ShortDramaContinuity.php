<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Versioned narrative facts, not media/runtime fields. Unknown is never invented. */
final class ShortDramaContinuity
{
    /** Called only by the visual-editor save boundary, never from client metadata. */
    public static function preserveVisualSubjectNarrative(array $plan, array $before): array
    {
        $originals = array_column((array)($before['subjects'] ?? []), null, 'id');
        $overrides = (array)($before['_subject_visual_overrides'] ?? []);
        $kept = [];
        foreach ((array)($plan['subjects'] ?? []) as $subject) {
            $id = (string)($subject['id'] ?? ''); $old = $originals[$id] ?? [];
            if (!$old || ($subject['name'] ?? '') !== ($old['name'] ?? '')
                || ($subject['category'] ?? '') !== ($old['category'] ?? '')) continue;
            $narrative = $old['description'] ?? '';
            if (isset($overrides[$id]) && $narrative === ($overrides[$id]['visual_description'] ?? null)) {
                $narrative = $overrides[$id]['narrative_description'];
            }
            $visual = $subject['description'] ?? '';
            if ($visual !== $narrative) $kept[$id] = ['narrative_description' => $narrative, 'visual_description' => $visual];
        }
        $plan['_subject_visual_overrides'] = $kept;
        return $plan;
    }

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
        foreach ($value['subjects'] as &$subject) {
            $override = $plan['_subject_visual_overrides'][$subject['id'] ?? ''] ?? [];
            if ($override && ($subject['description'] ?? null) === ($override['visual_description'] ?? null)) {
                $subject['description'] = $override['narrative_description'];
            }
        }
        unset($subject);
        // The visual editor adds this fixed UI discriminator to locations.
        // It conveys no story change and is absent in generated location rows.
        foreach ($value['locations'] as &$location) if (($location['category'] ?? '') === 'scene') unset($location['category']);
        unset($location);
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
            'audit_status' => (string)($last['audit_status'] ?? 'verified'),
            'warnings' => (array)($last['warnings'] ?? []),
            'state_note' => in_array(($last['audit_status'] ?? ''), ['pending_review', 'skipped'], true)
                ? '状态表仅为此前已验证记录，可能未反映上一集最新事件；衔接以recent_episodes正文为准，勿为迎合旧状态改写剧情。' : '',
            'previous_digest' => (string)($last['digest'] ?? ''),
            'recent_episodes' => array_map(static fn($item) => array_intersect_key($item, array_flip(['episode_number', 'summary', 'digest', 'audit_status', 'warnings'])), array_slice($previous, -2))];
    }

    public static function dependencyStatus(array $rows, array $current, bool $qualityReviewEnabled = true): array
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
            $row['needs_review'] = $qualityReviewEnabled && ($outdated || ($ledger['audit_status'] ?? '') === 'pending_review') && ($row['status'] ?? '') === 'success';
            $row['review_reason'] = !$row['needs_review'] ? '' : ($outdated
                ? '本集或前集剧情已修改，需复核连续性；原剧本和素材已保留'
                : '连续性审校尚未完成，需继续复核；原剧本和素材已保留');
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
                'review_window' => $context['review_window'] ?? null,
                'script' => self::narrative($plan),
                'response_contract' => [
                    'summary' => '本集已发生事件的简短摘要，不写未来剧情',
                    'changes' => [['entity_id' => 'subjects或locations中的id，也可为world', 'field' => '稳定状态键，如location/knows_secret/injury/item_owner；1至80个中文、英文字母、数字、下划线、斜线或连字符，禁止冒号和空格',
                        'before' => 'previous.state里已有的原值；首次记录用null', 'after' => '正文中明确的新值', 'evidence_refs' => [['shot_id' => '实际镜头id', 'field' => 'visual_description或dialogue；服务器提取原文，不自行抄写或缩写quote']]]],
                    'hooks' => [['id' => '稳定伏笔标识；resolved必须逐字引用previous.open_hooks中已有ID', 'description' => '已登记的open伏笔由服务器原样保留，无需重复提交或改写描述；进展写observed，兑现才resolved。同集新出现且已经完成的事件使用observed，不伪造前集伏笔', 'status' => 'open、resolved或observed', 'evidence_refs' => [['shot_id' => '实际镜头id', 'field' => 'visual_description或dialogue']]]],
                    'warnings' => ['具体连续性疑点，无疑点则空数组'],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }

    /** Validate audit syntax before paying for semantic verification. Schema
     * recovery can change malformed identifiers; evidence repair cannot. */
    public static function assertAuditShape(array $review, array $plan): void
    {
        if (!is_string($review['summary'] ?? null) || trim($review['summary']) === '') throw new RuntimeException('连续性摘要缺失', 422);
        foreach (['changes','hooks','warnings'] as $field) if (!is_array($review[$field] ?? null) || !array_is_list($review[$field])) throw new RuntimeException('连续性审校结构：' . $field . '必须为JSON数组', 422);
        $ids = array_merge(['world'], array_column($plan['subjects'] ?? [], 'id'), array_column($plan['locations'] ?? [], 'id'));
        foreach ($review['changes'] as $index => $record) {
            if (!is_array($record) || !in_array($record['entity_id'] ?? null, $ids, true)) throw new RuntimeException('连续性审校结构：changes[' . $index . '].entity_id只能取' . json_encode($ids, JSON_UNESCAPED_UNICODE), 422);
            if (!is_string($record['field'] ?? null) || !preg_match('~^[a-zA-Z0-9_/\x{4e00}-\x{9fff}-]{1,80}$~u', $record['field'])) throw new RuntimeException('连续性审校结构：changes[' . $index . '].field仅允许1至80个中文、字母、数字、下划线、斜线或连字符，禁止冒号、空格', 422);
            if (!is_scalar($record['after'] ?? null) || mb_strlen(ShortDramaText::value($record['after'])) > 1200
                || (isset($record['before']) && !is_scalar($record['before']))) throw new RuntimeException('连续性审校结构：状态before/after必须为标量文本，首次before用null', 422);
        }
        foreach ($review['hooks'] as $record) if (!is_array($record) || !is_string($record['id'] ?? null) || trim($record['id']) === '' || mb_strlen($record['id']) > 100
            || !is_string($record['description'] ?? null) || !in_array($record['status'] ?? '', ['open','resolved','observed'], true)) throw new RuntimeException('连续性审校结构：伏笔必须含id、description、status(open/resolved/observed)', 422);
        self::resolveEvidenceReferences($review, $plan);
        foreach ($review['warnings'] as $warning) if (!is_string($warning) || mb_strlen($warning) > 1500) throw new RuntimeException('连续性提示格式无效', 422);
    }

    /** The model selects sources; the server owns their verbatim contents.
     * Legacy quoted receipts remain readable. References confer no semantic
     * approval: the independent verifier still evaluates the entire claim. */
    private static function resolveEvidenceReferences(array $review, array $plan): array
    {
        $shots = array_column((array)($plan['storyboard'] ?? []), null, 'shot_id');
        foreach (['changes', 'hooks'] as $group) foreach ($review[$group] ?? [] as $index => $record) {
            if (!is_array($record) || !array_key_exists('evidence_refs', $record)) continue;
            if (!is_array($record['evidence_refs']) || !array_is_list($record['evidence_refs'])
                || !$record['evidence_refs'] || count($record['evidence_refs']) > 20) throw new RuntimeException('审校证据引用格式无效', 422);
            $evidence = [];
            foreach ($record['evidence_refs'] as $ref) {
                if (!is_array($ref) || array_diff(array_keys($ref), ['shot_id', 'field'])
                    || !is_string($ref['shot_id'] ?? null) || !in_array($ref['field'] ?? null, ['visual_description', 'dialogue'], true)
                    || !isset($shots[$ref['shot_id']]) || !is_string($shots[$ref['shot_id']][$ref['field']] ?? null)
                    || trim($shots[$ref['shot_id']][$ref['field']]) === '') throw new RuntimeException('审校证据引用不存在或为空', 422);
                $evidence[] = ['shot_id'=>$ref['shot_id'], 'quote'=>$shots[$ref['shot_id']][$ref['field']]];
            }
            // If a model echoes quote fields, never trust them over the selected source.
            unset($record['evidence_refs'], $record['quote'], $record['shot_id']);
            $record['evidence'] = $evidence;
            $record['shot_id'] = $evidence[0]['shot_id'];
            $record['quote'] = $evidence[0]['quote'];
            $review[$group][$index] = $record;
        }
        return $review;
    }

    /** At most two reference corrections, never rewrite or relax the script. */
    public static function review(array $plan, array $context, int $episode, callable $call, ?callable $verifyMeaning = null, bool $advisory = false, bool $repairClaims = false): array
    {
        $input = self::messages($plan, $context);
        $review = [];
        $originalReview = [];
        $patchBase = [];
        $mutableEvidence = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $sourcePatchVerified = false;
                $validate = $attempt ? static function (array $reply) use ($patchBase, $originalReview, $plan, $mutableEvidence): void {
                    if (isset($reply['evidence_patches'])) self::applyEvidencePatches($patchBase, $reply, $plan, $mutableEvidence);
                    else self::preserveRepairFacts($originalReview, $reply, $plan);
                } : null;
                $review = $call($input, $validate);
                if ($attempt) $review = isset($review['evidence_patches'])
                    ? self::applyEvidencePatches($patchBase, $review, $plan, $mutableEvidence)
                    : self::preserveRepairFacts($originalReview, $review, $plan);
                // A repeated assertion of an already verified state is not a
                // new fact. Do not require every later block to re-prove all
                // clauses of an unchanged historical value. Never drop a
                // changed value or a mismatched before-state this way.
                if (!$attempt && is_array($review['changes'] ?? null)) {
                    $known = (array)($context['continuity']['state'] ?? []);
                    $registered = $known;
                    foreach ($review['changes'] as &$change) {
                        if (!is_array($change) || !is_string($change['entity_id'] ?? null) || !is_string($change['field'] ?? null)) continue;
                        $key = $change['entity_id'] . ':' . $change['field'];
                        // Absence is owned by the persisted ledger, not by an
                        // LLM spelling of null/unknown/a guessed former state.
                        if (!array_key_exists($key, $registered) && ($change['before'] ?? null) !== null) {
                            $review['audit_corrections'][] = ['original'=>$change,'field'=>'before','corrected'=>null,'reason'=>'状态表尚未登记此键，首次旧值由服务端确定为null'];
                            $change['before'] = null;
                        }
                        $registered[$key] = true;
                    }
                    unset($change);
                    $seenKeys = [];
                    $review['changes'] = array_values(array_filter($review['changes'], static function ($item) use ($known, &$seenKeys): bool {
                        if (!is_array($item) || !is_string($item['entity_id'] ?? null) || !is_string($item['field'] ?? null)) return true;
                        $key = $item['entity_id'] . ':' . $item['field'];
                        $unchanged = !isset($seenKeys[$key]) && array_key_exists($key, $known)
                            && array_key_exists('before', $item) && array_key_exists('after', $item)
                            && $item['before'] === $known[$key] && $item['after'] === $known[$key];
                        $seenKeys[$key] = true;
                        return !$unchanged;
                    }));
                }
                if (!$attempt && is_array($review['hooks'] ?? null)) {
                    $knownHooks = (array)($context['continuity']['open_hooks'] ?? []); $seenHooks = [];
                    $continuations = [];
                    $review['hooks'] = array_values(array_filter($review['hooks'], static function ($hook) use ($knownHooks, &$seenHooks, &$continuations): bool {
                        if (!is_array($hook) || !is_string($hook['id'] ?? null)) return true;
                        $id = $hook['id'];
                        $unchanged = !isset($seenHooks[$id]) && ($hook['status'] ?? '') === 'open' && isset($knownHooks[$id]);
                        // The original promise is immutable until resolution.
                        // Rewording an open hook must not erase/replace it or
                        // require the script to repeat its original setup.
                        if ($unchanged && $knownHooks[$id] !== ($hook['description'] ?? null)) $continuations[] = [
                            'original'=>$hook,'field'=>'description','corrected'=>$knownHooks[$id],
                            'reason'=>'已有未兑现伏笔保持原始承诺，忽略审校员的重复描述；未执行新增或回收'];
                        $seenHooks[$id] = true;
                        return !$unchanged;
                    }));
                    if ($continuations) $review['audit_corrections'] = array_merge($review['audit_corrections'] ?? [], $continuations);
                }
                $review = self::resolveEvidenceReferences($review, $plan);
                if ($verifyMeaning) {
                    $draft = $review;
                    $verified = $verifyMeaning($review, $plan);
                    if (is_array($verified)) $review = $verified;
                    if ($repairClaims && !empty($review['unverified_claims']) && empty($draft['unverified_claims'])) {
                        $review = self::repairAuditClaims($draft, $review, $plan, $context, $call, $verifyMeaning);
                    }
                    // Classify claims before repairing their citations. An
                    // invented claim has no reference to repair; demanding one
                    // encourages fabricated evidence and futile retries.
                    // Every retained assertion still needs an exact citation.
                    $review = self::isolateInvalidEvidence($review, $plan, $attempt >= 2);
                    // The verifier also checks every source-backed shot insertion.
                    // A later ledger error is only about the audit metadata.
                    $sourcePatchVerified = true;
                    $review = self::isolateUncertainStateChains($review);
                }
                try {
                    $ledger = self::ledger($review, $plan, $context, $episode);
                } catch (RuntimeException $ledgerError) {
                    // Only local audit-data errors are advisory. Provider errors,
                    // capacity limits and unverified source insertions stay hard failures.
                    if (!$advisory || (!empty($plan['_continuity_source_patch']) && !$sourcePatchVerified)
                        || !in_array($ledgerError->getCode(), [409, 422], true)) throw $ledgerError;
                    $warnings = array_values(array_filter((array)($review['warnings'] ?? []),
                        static fn($warning) => is_string($warning) && mb_strlen($warning) <= 1500));
                    $warnings[] = '剧本已生成，连续性记录待复核；本轮未更新事实状态：'
                        . mb_substr($ledgerError->getMessage(), 0, 900);
                    $ledger = self::ledger(['summary' => '以本集正文为准', 'changes' => [], 'hooks' => [],
                        'warnings' => $warnings, 'unverified_claims' => [[
                            'collection' => 'audit', 'claim' => $review, 'reason' => $ledgerError->getMessage(),
                        ]]], $plan, $context, $episode);
                    $ledger['audit_status'] = 'pending_review';
                }
                // Quarantined claims are deliberately absent from the state
                // ledger. Keep the episode available, but make the incomplete
                // audit visible to the next episode and to the editor.
                if (self::hasBlockingAuditClaims($review)) {
                    $ledger['audit_status'] = 'pending_review';
                }
                return $ledger + ['review_repairs' => $attempt];
            } catch (RuntimeException $error) {
                // Audit transport/data validation is not a verdict about the
                // already validated script. Never accept an unverified script
                // insertion or turn a proven 409 contradiction into a warning.
                if ($verifyMeaning && empty($plan['_continuity_source_patch']) && $error->getCode() === 422
                    && (str_starts_with($error->getMessage(), '连续性语义核对')
                        || in_array($error->getMessage(), ['模型输出不是完整 JSON，已保存返回内容', '连续性摘要缺失', '连续性检查结构不完整', '连续性提示格式无效'], true))) {
                    $reason = mb_substr($error->getMessage(), 0, 1000);
                    $deferred = ['summary' => '审校待复核', 'changes' => [], 'hooks' => [],
                        'warnings' => ['剧本已保留；审校数据不完整，未更新连续性事实，待复核：' . $reason],
                        'unverified_claims' => [['collection' => 'audit', 'claim' => $review, 'reason' => $reason]]];
                    return self::ledger($deferred, $plan, $context, $episode)
                        + ['review_repairs' => $attempt, 'audit_status' => 'pending_review'];
                }
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
                $targets = [];
                foreach (['changes', 'hooks'] as $collection) foreach ($review[$collection] ?? [] as $index => $record) {
                    $targets[] = ['collection'=>$collection, 'index'=>$index, 'record'=>$record];
                }
                // Replace the original response contract instead of appending
                // incompatible whole-audit and patch-only schemas together.
                $input = ['system_prompt'=>'仅修正审校JSON的证据引用或before，不修改正文与事实含义。只返回evidence_patches对象，不返回完整审校。',
                    'content'=>json_encode(['instructions'=>[
                        '仅修正审校JSON：只能修改targets列出的当前索引。不得增加、删除、改写事实、伏笔含义或warnings。不得返回entity_id、field、after、description、status、summary、warnings。',
                        '修正证据时只用evidence_refs指定当前storyboard的shot_id及visual_description或dialogue字段，由服务器读取原文。不要同时返回quote、shot_id或evidence。不得引用大纲、不存在或空字段。',
                        '只修正before时省略evidence_refs，不返回空数组占位。before必须逐字等于previous.state或本集同键上一条after；首次登记用JSON null。state_errors列出的before必须修正。',
                        '已有有效证据无需重复提交。一个记录的多个镜头证据放入同一evidence_refs数组。没有可修正项则返回空evidence_patches。',
                    ], 'error'=>$error->getMessage(), 'script'=>self::narrative($plan), 'previous'=>$context['continuity'] ?? [], 'targets'=>$targets,
                        'evidence_errors'=>self::evidenceIssues($review, $plan), 'state_errors'=>self::stateIssues($review, $context),
                        'response_contract'=>['evidence_patches'=>[['collection'=>'changes或hooks','index'=>0,'evidence_refs'=>[['shot_id'=>'实际镜头ID','field'=>'visual_description或dialogue']]]]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
            }
        }
        throw new RuntimeException('连续性审校未完成', 422);
    }

    public static function hasBlockingAuditClaims(array $review): bool
    {
        foreach (array_merge($review['unverified_claims'] ?? [], $review['unverified_hook_resolutions'] ?? []) as $entry) {
            // Only an explicit independent diagnosis of auditor error is
            // advisory. Legacy/uncertain verdicts and real script conflicts
            // remain blocking. Rejected operations never reach the ledger.
            if (($entry['failure_kind'] ?? 'uncertain') !== 'audit_claim') return true;
        }
        return false;
    }

    /** The auditor can invent a qualifier absent from the script. Correct only
     * its rejected metadata, never the script or an already verified claim.
     * Identity, operation, count and before-state remain immutable. */
    private static function repairAuditClaims(array $draft, array $rejected, array $plan, array $context, callable $call, callable $verify): array
    {
        $targets = [];
        foreach ($rejected['unverified_claims'] as $entry) {
            if (($entry['failure_kind'] ?? '') === 'audit_claim') continue;
            $group = $entry['collection'] ?? ''; $index = $entry['index'] ?? -1;
            $claim = $draft[$group][$index] ?? null;
            $unknownResolution = $group === 'hooks' && ($claim['status'] ?? '') === 'resolved'
                && !isset($context['continuity']['open_hooks'][$claim['id'] ?? ''])
                && !isset($context['continuity']['open_hooks'][preg_replace('/_resolved$/', '', $claim['id'] ?? '')]);
            if (!$claim || ($group !== 'changes' && !($group === 'hooks' && ($claim['status'] ?? '') === 'open') && !$unknownResolution)) continue;
            $entry['unknown_resolution'] = $unknownResolution;
            $targets[$group . ':' . $index] = $entry;
        }
        if (!$targets) return $rejected;
        $messages = ['system_prompt'=>'修正审校员自行推断的记录，不修改剧本。只返回claim_patches数组。仅修正被独立核验明确否定的after或伏笔description，使其准确描述当前正文。不得为通过审校改写人物、道具、事件或虚构证据，不删除或新增记录。unknown_resolution为true时：若确实兑现previous.open_hooks中某项，额外填写hook_id为该准确ID，value逐字采用该原伏笔描述；若只是本集新出现且已完成的事件，额外填写status="observed"，value描述当前已发生事件，不作伏笔回收。其他记录禁止修改ID或status。确实无法修正则返回空数组。',
            'content'=>json_encode(['script'=>self::narrative($plan), 'previous'=>$context['continuity'] ?? [], 'rejected_claims'=>array_values($targets),
                'response_contract'=>['claim_patches'=>[['collection'=>'changes或hooks','index'=>0,'value'=>'有正文支持的准确状态或伏笔描述','shot_id'=>'当前镜头ID','quote'=>'该镜头连续原文','reason'=>'说明原审校推断了什么以及正文实际展示什么']]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        $apply = static function (array $reply) use ($draft, $targets, $plan, $context): array {
            if (array_keys($reply) !== ['claim_patches'] || !is_array($reply['claim_patches']) || !array_is_list($reply['claim_patches'])) throw new RuntimeException('审校事实纠正格式无效', 422);
            $candidate = $draft; $seen = []; $shots = array_column($plan['storyboard'], null, 'shot_id');
            foreach ($reply['claim_patches'] as $patch) {
                if (!is_array($patch) || array_diff(array_keys($patch), ['collection','index','value','shot_id','quote','reason','hook_id','status'])
                    || !is_string($patch['collection'] ?? null) || !is_int($patch['index'] ?? null)
                    || !is_string($patch['value'] ?? null) || trim($patch['value']) === '' || mb_strlen($patch['value']) > 1000
                    || !is_string($patch['reason'] ?? null) || trim($patch['reason']) === '') throw new RuntimeException('审校事实纠正字段无效', 422);
                $key = $patch['collection'] . ':' . $patch['index'];
                if (!isset($targets[$key]) || isset($seen[$key])) throw new RuntimeException('审校事实纠正越界', 422);
                if (isset($patch['hook_id']) || isset($patch['status'])) {
                    if (empty($targets[$key]['unknown_resolution'])) throw new RuntimeException('不能修改已登记伏笔的标识或操作', 422);
                    if (isset($patch['hook_id'])) {
                        if (isset($patch['status']) || !is_string($patch['hook_id']) || !isset($context['continuity']['open_hooks'][$patch['hook_id']])
                            || $patch['value'] !== $context['continuity']['open_hooks'][$patch['hook_id']]) throw new RuntimeException('伏笔纠正必须引用已登记的原始ID及含义', 422);
                    } elseif ($patch['status'] !== 'observed') throw new RuntimeException('未知伏笔只可纠正为已观察事件', 422);
                }
                self::evidence(['shot_id'=>$patch['shot_id'] ?? null,'quote'=>$patch['quote'] ?? null], $shots);
                $field = $patch['collection'] === 'changes' ? 'after' : 'description';
                $record = &$candidate[$patch['collection']][$patch['index']];
                $candidate['audit_corrections'][] = ['original'=>$record,'field'=>$field,'corrected'=>$patch['value'],'reason'=>$patch['reason'],'patch'=>$patch];
                $record[$field] = $patch['value']; $record['shot_id'] = $patch['shot_id']; $record['quote'] = $patch['quote']; unset($record['evidence'], $record);
                if (isset($patch['hook_id'])) $candidate[$patch['collection']][$patch['index']]['id'] = $patch['hook_id'];
                if (isset($patch['status'])) $candidate[$patch['collection']][$patch['index']]['status'] = $patch['status'];
                $seen[$key] = true;
            }
            return $candidate;
        };
        try {
            $reply = $call($messages, static fn(array $reply) => $apply($reply));
            if (empty($reply['claim_patches'])) return $rejected;
            $candidate = $apply($reply);
            return $verify($candidate, $plan);
        } catch (RuntimeException $error) {
            if ($error->getCode() !== 422) throw $error;
            $rejected['warnings'][] = '审校记录纠正未通过，保留待复核：' . mb_substr($error->getMessage(), 0, 900);
            return $rejected;
        }
    }

    /** A removed transition cannot remain the assumed basis for a later one. */
    private static function isolateUncertainStateChains(array $review): array
    {
        $uncertain = [];
        foreach ($review['unverified_claims'] ?? [] as $entry) {
            $claim = $entry['claim'] ?? [];
            if (($entry['collection'] ?? '') === 'changes' && is_string($claim['entity_id'] ?? null) && is_string($claim['field'] ?? null)) {
                $uncertain[$claim['entity_id'] . ':' . $claim['field']] = true;
            }
        }
        foreach ($review['changes'] as $index => $claim) {
            if (!isset($uncertain[($claim['entity_id'] ?? '') . ':' . ($claim['field'] ?? '')])) continue;
            $review['unverified_claims'][] = ['collection' => 'changes', 'index' => $index, 'claim' => $claim,
                'reason' => '同一状态链包含未验证的变更，不能据此更新后续状态'];
            $review['warnings'][] = '连续性状态链待复核，已保留前集状态：' . $claim['entity_id'] . ':' . $claim['field'];
            unset($review['changes'][$index]);
        }
        $review['changes'] = array_values($review['changes']);
        return $review;
    }

    /** Invalid references are isolated only after the bounded repair budget. */
    private static function isolateInvalidEvidence(array $review, array $plan, bool $exhausted): array
    {
        foreach (['changes', 'hooks', 'warnings'] as $key) {
            if (!is_array($review[$key] ?? null) || !array_is_list($review[$key])) {
                throw new RuntimeException('连续性检查结构不完整', 422);
            }
        }
        $shots = array_column((array)($plan['storyboard'] ?? []), null, 'shot_id');
        foreach (['changes', 'hooks'] as $collection) {
            foreach ($review[$collection] as $index => $claim) {
                try {
                    self::evidence($claim, $shots);
                } catch (RuntimeException $error) {
                    if ($error->getCode() !== 422) throw $error;
                    if (!$exhausted) throw $error;
                    $review['unverified_claims'][] = compact('collection', 'index', 'claim') + ['reason' => $error->getMessage()];
                    $review['warnings'][] = '审校引用无效，未写入连续性记录，待复核：' . $error->getMessage();
                    unset($review[$collection][$index]);
                }
            }
            $review[$collection] = array_values($review[$collection]);
        }
        return $review;
    }

    /** Repair only references, never delegate ownership of facts to a correction. */
    private static function applyEvidencePatches(array $original, array $response, array $plan, array $mutableEvidence = []): array
    {
        if (array_keys($response) !== ['evidence_patches'] || !is_array($response['evidence_patches'])
            || !array_is_list($response['evidence_patches'])) throw new RuntimeException('审校证据补丁格式无效', 422);
        $seen = [];
        $shots = array_column((array)($plan['storyboard'] ?? []), null, 'shot_id');
        // One fact may span several shots. Coalesce repeated index entries
        // into explicit evidence, but never choose between conflicting states.
        $patches = [];
        foreach ($response['evidence_patches'] as $patch) {
            if (!is_array($patch) || !is_string($patch['collection'] ?? null) || !is_int($patch['index'] ?? null)) throw new RuntimeException('审校证据补丁必须为有效对象', 422);
            if (array_key_exists('evidence_refs', $patch)) {
                if (isset($patch['quote']) || isset($patch['evidence']) || !is_array($patch['evidence_refs'])
                    || !array_is_list($patch['evidence_refs']) || !$patch['evidence_refs'] || count($patch['evidence_refs']) > 20) {
                    throw new RuntimeException('审校证据引用格式无效', 422);
                }
                $patch['evidence'] = [];
                foreach ($patch['evidence_refs'] as $ref) {
                    if (!is_array($ref) || array_diff(array_keys($ref), ['shot_id', 'field'])
                        || !is_string($ref['shot_id'] ?? null) || !in_array($ref['field'] ?? null, ['visual_description', 'dialogue'], true)
                        || !isset($shots[$ref['shot_id']]) || !is_string($shots[$ref['shot_id']][$ref['field']] ?? null)
                        || trim($shots[$ref['shot_id']][$ref['field']]) === '') {
                        throw new RuntimeException('审校证据引用不存在或为空', 422);
                    }
                    $patch['evidence'][] = ['shot_id' => $ref['shot_id'], 'quote' => $shots[$ref['shot_id']][$ref['field']]];
                }
                unset($patch['evidence_refs']);
            }
            // Models sometimes echo a reworded state. The correction does not
            // own it; retain the original assertion and verify its meaning.
            if ($patch['collection'] === 'changes') unset($patch['after']);
            // A correction may echo immutable metadata. Accept only exact
            // echoes; never apply a new identity, hook meaning or status.
            $base = $original[$patch['collection']][$patch['index']] ?? [];
            foreach (['entity_id', 'field', 'id', 'description', 'status'] as $protected) {
                if (!array_key_exists($protected, $patch)) continue;
                if (!array_key_exists($protected, $base) || $patch[$protected] !== $base[$protected]) {
                    throw new RuntimeException('审校纠错不得改写事实标识或伏笔含义', 422);
                }
                unset($patch[$protected]);
            }
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
        $changedInEpisode = (array)($context['_reviewed_block_state_keys'] ?? []);
        foreach ($review['changes'] as $item) {
            self::evidence($item, $shots);
            // JSON scalar spelling is lossless; PHP's generic string cast is
            // not (false becomes empty and true becomes 1). Keep null as the
            // absent-before sentinel and reject objects/arrays as before.
            foreach (['before', 'after'] as $field) {
                $value = $item[$field] ?? null;
                if (is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
                    $item[$field] = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                }
            }
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
                || !is_string($item['description'] ?? null) || !in_array($item['status'] ?? '', ['open', 'resolved', 'observed'], true)) throw new RuntimeException('伏笔记录格式无效', 422);
            if ($item['status'] === 'observed') continue; // Verified current event, no open-hook mutation.
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
        // Once an audit has unsupported claims, do not forward its unverified
        // summary to the next episode. Use existing narrative text verbatim.
        $summary = $review['summary'];
        if (!empty($review['unverified_claims'])) {
            $summary = implode("\n", array_filter((array)($plan['script_lines'] ?? []), 'is_string'));
            if (trim($summary) === '') $summary = implode("\n", array_column((array)($plan['storyboard'] ?? []), 'visual_description'));
        }
        return ['version' => 1, 'episode_number' => $episode, 'summary' => $summary, 'state' => $state, 'open_hooks' => $hooks,
            'digest' => self::fingerprint($plan), 'previous_digest' => (string)($context['continuity']['previous_digest'] ?? ''), 'warnings' => $warnings,
            'unverified_claims' => (array)($review['unverified_claims'] ?? []),
            'audit_corrections' => (array)($review['audit_corrections'] ?? []),
            'observed_events' => array_values(array_filter($review['hooks'], static fn($hook) => ($hook['status'] ?? '') === 'observed')),
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
