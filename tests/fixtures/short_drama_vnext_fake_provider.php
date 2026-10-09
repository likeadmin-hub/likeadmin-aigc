<?php
namespace app\common\service\power;

/** Fixed responses only. This class replaces the market adapter in isolated PHP test processes. */
class MarketTextModelRuntimeService
{
    public static array $requests = [];
    public static function modelGroups(int $tenantId): array { return []; }
    public static function estimateTokens(string $value): int { return mb_strlen($value); }
    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::$requests[] = $params;
        $input = json_decode($params['content'], true, 512, JSON_THROW_ON_ERROR);
        if (!isset($input['document'])) $reply = ['title' => '完整结尾', 'entities' => [['ref' => 'l1', 'kind' => 'location', 'name' => '书房']],
            'scenes' => [['location' => 'l1', 'units' => [['type' => 'dialogue', 'speech_type' => 'narration', 'text' => str_repeat('正文。', 400) . '结尾保留。']]]]];
        elseif (!isset($input['shot_plan'])) $reply = ['shots' => [['visual_description' => '书房天亮了', 'refs' => [['unit' => 'u1']]]]];
        else {
            $checks = []; $ids = array_merge(array_column($input['requirements'], 'id'), ['aspect:identity', 'aspect:continuity', 'aspect:shot_fidelity']);
            foreach ($ids as $id) $checks[] = ['id' => $id, 'status' => 'covered', 'evidence' => [['unit' => 'u1', 'start' => 1200, 'end' => 1205, 'quote' => '结尾保留。']]];
            $reply = ['document_hash' => $input['document']['content_hash'], 'shot_hash' => $input['shot_plan']['content_hash'], 'checks' => $checks];
        }
        return ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
            'billing' => ['tenant_cost_points' => '1.00', 'user_charge_points' => '2.00', 'billing_status' => 'settled']];
    }
}
