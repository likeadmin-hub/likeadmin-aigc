<?php
namespace app\common\service\app\aigc_short_drama;

/** Validate provider output before normalization can turn missing speakers into narration. */
final class ShortDramaDialogueContract
{
    public const INSTRUCTION = '每个制作分镜必须返回 voice_role、speech_type、dialogue。角色台词的 speech_type=character，voice_role 填实际说话角色的完整名称（与 subjects 一致），不要用可见人物猜测说话人；画外角色说话也填写该角色。临时说话角色必须纳入 subjects。只有旁白使用 speech_type=narration、voice_role=""；无台词使用 speech_type=none、dialogue=""、voice_role=""。禁止将角色台词统一标为旁白。';

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
                if (is_string($role)) {
                    $role = trim($role);
                    $role = $names[$role] ?? $role;
                    if (in_array($role, ['旁白', '无', 'narrator'], true)) {
                        $role = '';
                        $node['speech_type'] = 'narration';
                    }
                    $node['voice_role'] = $role;
                }
                if (trim((string)$node['dialogue']) !== '') {
                    $validNarration = $role === '' && ($node['speech_type'] ?? '') === 'narration';
                    $validCharacter = is_string($role) && $role !== '' && in_array($role, $names, true)
                        && !in_array($node['speech_type'] ?? '', ['narration', 'none'], true);
                    if (!$validNarration && !$validCharacter) $issues[] = ['code' => 'dialogue.speaker', 'severity' => 'blocking',
                        'path' => $path . '.voice_role', 'message' => '台词必须标明 subjects 中实际说话角色；确属旁白时明确 speech_type=narration，不能把缺失说话人当旁白'];
                }
            }
            foreach ($node as $key => $value) if (is_array($value)) $node[$key] = $walk($value, $path === '' ? (string)$key : $path . '.' . $key);
            return $node;
        };
        $payload = $walk($payload);
        return ['payload' => $payload, 'issues' => $issues];
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
}
