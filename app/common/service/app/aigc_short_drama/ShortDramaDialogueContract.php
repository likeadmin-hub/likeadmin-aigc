<?php
namespace app\common\service\app\aigc_short_drama;

/** Validate provider output before normalization can turn missing speakers into narration. */
final class ShortDramaDialogueContract
{
    public const INSTRUCTION = '每个制作分镜必须返回 voice_role、speech_type、dialogue。角色台词的 speech_type=character，voice_role 填模型明确给出的实际说话角色完整名称；可见主体使用 subjects 中的名称，画外角色也保留其明确名称，但不要根据画面中的人物猜测说话人或新建角色。只有旁白使用 speech_type=narration、voice_role=""；无台词使用 speech_type=none、dialogue=""、voice_role=""。禁止将角色台词统一标为旁白。';

    public static function prepare(array $payload): array
    {
        $names = [];
        foreach ((array)($payload['subjects'] ?? []) as $subject) {
            if (is_array($subject) && !empty($subject['name'])) $names[(string)($subject['id'] ?? '')] = (string)$subject['name'];
        }
        $issues = [];
        $walk = static function (array $node, string $path = '') use (&$walk, &$issues, $names): array {
            if (array_key_exists('dialogue', $node)) {
                $role = $node['voice_role'] ?? $node['speaker'] ?? $node['speaker_name'] ?? null;
                $role = is_string($role) ? trim($role) : '';
                $role = $names[$role] ?? $role;
                if (in_array($role, ['旁白', '无', 'narrator'], true)) {
                    $role = '';
                    $node['speech_type'] = 'narration';
                }
                // A speaker prefix is an unambiguous piece of returned
                // dialogue, not a guess based on people visible in the shot.
                // Normalize it before quality review so `七公：...` remains a
                // character line and `旁白：...` remains narration even when a
                // provider omitted the redundant metadata fields.
                $prefixAttribution = self::attributionFromDialoguePrefix((string)($node['dialogue'] ?? ''), $names);
                $roleIsKnown = $role !== '' && in_array($role, $names, true);
                if ($prefixAttribution !== [] && (!$roleIsKnown || ($node['speech_type'] ?? '') === 'narration')) {
                    $role = (string)$prefixAttribution['voice_role'];
                    $node['speech_type'] = (string)$prefixAttribution['speech_type'];
                }
                $node['voice_role'] = $role;
                if (trim((string)$node['dialogue']) !== '') {
                    $validNarration = $role === '' && ($node['speech_type'] ?? '') === 'narration';
                    // A speaker can be an off-screen character (for example a
                    // veterinarian speaking from outside the frame). The LLM
                    // has explicitly supplied that role, so it is not an
                    // attribution inferred from visible subjects. Require a
                    // non-empty role and character type, but do not force an
                    // off-screen voice into the visual-subject asset list.
                    $validCharacter = is_string($role) && $role !== ''
                        && !in_array($node['speech_type'] ?? '', ['narration', 'none'], true);
                    if (!$validNarration && !$validCharacter) $issues[] = ['code' => 'dialogue.speaker', 'severity' => 'blocking',
                        'path' => $path . '.voice_role', 'message' => '台词必须标明实际说话角色；确属旁白时明确 speech_type=narration，不能把缺失说话人当旁白'];
                }
            }
            foreach ($node as $key => $value) if (is_array($value)) $node[$key] = $walk($value, $path === '' ? (string)$key : $path . '.' . $key);
            return $node;
        };
        $payload = $walk($payload);
        return ['payload' => $payload, 'issues' => $issues];
    }

    /**
     * Only an explicit `主体名：` / `旁白：` prefix may supply attribution.
     * Do not infer speakers from the visual description, visible subjects or
     * prose content: those would turn genuine narration into character speech.
     */
    private static function attributionFromDialoguePrefix(string $dialogue, array $names): array
    {
        $dialogue = trim($dialogue);
        if ($dialogue === '') {
            return [];
        }
        if (preg_match('/^(?:旁白|narrator)\s*[：:]/iu', $dialogue) === 1) {
            return ['voice_role' => '', 'speech_type' => 'narration'];
        }
        $subjectNames = array_values(array_unique(array_filter(array_map(static fn($name): string => trim((string)$name), $names))));
        usort($subjectNames, static fn(string $left, string $right): int => mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8'));
        foreach ($subjectNames as $name) {
            if ($name !== '' && preg_match('/^' . preg_quote($name, '/') . '\s*[：:]/u', $dialogue) === 1) {
                return ['voice_role' => $name, 'speech_type' => 'character'];
            }
        }
        return [];
    }

    public static function review(array $result, array $issues): array
    {
        if (!$issues) return $result;
        $report = (array)($result['review_report'] ?? []);
        $report['issues'] = array_merge((array)($report['issues'] ?? []), $issues);
        $report['blocking_count'] = (int)($report['blocking_count'] ?? 0) + count($issues);
        $report['issue_count'] = count($report['issues']);
        $report['status'] = 'failed';
        $result['review_report'] = $report;
        return $result;
    }

    /**
     * Speaker attribution is the only quality fault that can be repaired
     * without regenerating a complete plan. Structural and creative failures
     * must continue through the full repair path.
     */
    public static function hasOnlySpeakerBlockingIssues(array $reviewReport): bool
    {
        $blocking = 0;
        foreach ((array)($reviewReport['issues'] ?? []) as $issue) {
            if (!is_array($issue) || ($issue['severity'] ?? '') !== 'blocking') continue;
            $blocking++;
            if (($issue['code'] ?? '') !== 'dialogue.speaker') return false;
        }
        return $blocking > 0;
    }

    /** Return only the failed dialogue fields; never expose a whole plan to the small repair request. */
    public static function repairTargets(array $plan, array $issues): array
    {
        $targets = [];
        foreach ($issues as $issue) {
            if (!is_array($issue) || ($issue['code'] ?? '') !== 'dialogue.speaker') continue;
            if (!preg_match('/^storyboard\\.(\\d+)\\.voice_role$/', (string)($issue['path'] ?? ''), $matches)) continue;
            $index = (int)$matches[1];
            $shot = (array)($plan['storyboard'][$index] ?? []);
            if (trim((string)($shot['dialogue'] ?? '')) === '') continue;
            $shotId = trim((string)($shot['shot_id'] ?? ''));
            if ($shotId === '') continue;
            $targets[$shotId] = [
                'shot_id' => $shotId,
                'dialogue' => mb_substr(trim((string)$shot['dialogue']), 0, 500, 'UTF-8'),
                'declared_voice_role' => mb_substr(trim((string)($shot['voice_role'] ?? $shot['speaker'] ?? $shot['speaker_name'] ?? '')), 0, 100, 'UTF-8'),
                'visual_description' => mb_substr(trim((string)($shot['visual_description'] ?? '')), 0, 800, 'UTF-8'),
                'subject_ref_ids' => array_values(array_filter(array_map('strval', (array)($shot['subject_ref_ids'] ?? [])))),
            ];
        }
        return array_values($targets);
    }

    /**
     * Apply a narrow, validated speaker patch. Unknown speakers, non-target
     * shots and any attempt to alter creative fields are deliberately ignored.
     */
    public static function applySpeakerRepairs(array $plan, array $repairPayload, array $targets): array
    {
        $allowedTargets = array_fill_keys(array_column($targets, 'shot_id'), true);
        if (!$allowedTargets) return $plan;

        $subjects = [];
        foreach ((array)($plan['subjects'] ?? []) as $subject) {
            if (!is_array($subject) || trim((string)($subject['name'] ?? '')) === '') continue;
            $name = trim((string)$subject['name']);
            $subjects[(string)($subject['id'] ?? '')] = $name;
            $subjects[$name] = $name;
        }

        $declaredRoles = [];
        foreach ($targets as $target) {
            if (!is_array($target)) continue;
            $shotId = trim((string)($target['shot_id'] ?? ''));
            $role = trim((string)($target['declared_voice_role'] ?? ''));
            if ($shotId !== '' && $role !== '') $declaredRoles[$shotId] = $role;
        }

        $repairs = (array)($repairPayload['dialogue_repairs'] ?? $repairPayload['speaker_repairs'] ?? []);
        $byShotId = [];
        foreach ($repairs as $repair) {
            if (!is_array($repair)) continue;
            $shotId = trim((string)($repair['shot_id'] ?? ''));
            if ($shotId !== '' && isset($allowedTargets[$shotId])) $byShotId[$shotId] = $repair;
        }

        foreach ((array)($plan['storyboard'] ?? []) as $index => $shot) {
            if (!is_array($shot)) continue;
            $shotId = trim((string)($shot['shot_id'] ?? ''));
            $repair = $byShotId[$shotId] ?? null;
            if (!is_array($repair)) continue;
            $speechType = trim((string)($repair['speech_type'] ?? ''));
            $role = trim((string)($repair['voice_role'] ?? $repair['speaker'] ?? ''));
            if ($speechType === 'narration' && $role === '') {
                $plan['storyboard'][$index]['voice_role'] = '';
                $plan['storyboard'][$index]['speech_type'] = 'narration';
                continue;
            }
            $declaredRole = $declaredRoles[$shotId] ?? '';
            if ($speechType !== 'narration' && $speechType !== 'none'
                && (isset($subjects[$role]) || ($declaredRole !== '' && $role === $declaredRole))) {
                $plan['storyboard'][$index]['voice_role'] = $subjects[$role] ?? $declaredRole;
                $plan['storyboard'][$index]['speech_type'] = 'character';
            }
        }
        return $plan;
    }
}
