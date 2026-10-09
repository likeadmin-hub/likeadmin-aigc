<?php
namespace app\common\service\power;

/** Fixed responses only. This class replaces the market adapter in isolated PHP test processes. */
class MarketTextModelRuntimeService
{
    public static array $requests = [];
    public static bool $failSettlementOnce = false;
    public static int $recoveries = 0;
    public static function modelGroups(int $tenantId): array { return [['key' => 'script_plan', 'default' => 'frozen_model', 'options' => [self::resolveModel($tenantId, '')]]]; }
    public static function resolveModel(int $tenantId, $selection, bool $vision = false): array { return ['id' => 'frozen_model', 'name' => 'Fixture', 'provider' => 'power_market', 'model_code' => 'frozen_model', 'context_window' => 32768, 'max_output_tokens' => 8192]; }
    public static function estimateTokens(string $value): int { return mb_strlen($value); }
    public static function generate(int $tenantId, int $userId, array $params, ?callable $onEvent = null, bool $admin = false, ?callable $persist = null): array
    {
        self::$requests[] = $params;
        $input = json_decode($params['content'], true, 512, JSON_THROW_ON_ERROR);
        if (isset($input['current_episode'])) {
            $location = array_values(array_filter($input['entities'], static fn($e) => $e['kind'] === 'location'))[0];
            $characters = array_values(array_filter($input['entities'], static fn($e) => $e['kind'] === 'character'));
            $reply = ['title' => $input['current_episode']['title'], 'entities' => [],
                'scenes' => [['location' => $location['ref'], 'units' => [['type' => 'action', 'characters' => array_column($characters, 'ref'),
                    'text' => $input['current_episode']['story_outline']]]]]];
        } elseif (!isset($input['document'])) $reply = ['title' => '完整结尾', 'entities' => [['ref' => 'l1', 'kind' => 'location', 'name' => '书房']],
            'scenes' => [['location' => 'l1', 'units' => [['type' => 'dialogue', 'speech_type' => 'narration', 'text' => str_repeat('正文。', 400) . '结尾保留。']]]]];
        elseif (!isset($input['shot_plan'])) $reply = ['shots' => [['visual_description' => '书房天亮了', 'refs' => [['unit' => 'u1']]]]];
        else {
            $checks = []; $ids = array_merge(array_column($input['requirements'], 'id'), array_column($input['events'] ?? [], 'id'), ['aspect:identity', 'aspect:continuity', 'aspect:shot_fidelity']);
            $series = isset($input['document']['dependency_versions']['series']);
            $text = $input['document']['scenes'][0]['units'][0]['text'];
            foreach ($ids as $id) $checks[] = ['id' => $id, 'status' => 'covered', 'evidence' => [['unit' => 'u1', 'start' => $series ? 0 : 1200, 'end' => $series ? mb_strlen($text) : 1205, 'quote' => $series ? $text : '结尾保留。']]];
            $reply = ['document_hash' => $input['document']['content_hash'], 'shot_hash' => $input['shot_plan']['content_hash'], 'checks' => $checks, 'narrative_changes' => []];
            if ($series) {
                $character = array_values(array_filter($input['document']['entities'], static fn($e) => $e['kind'] === 'character'))[0];
                $reply['narrative_changes'][] = ['kind' => 'world', 'subject_id' => $character['entity_id'], 'predicate' => 'location', 'value' => '书房',
                    'observer_id' => null, 'certainty' => 'confirmed', 'evidence' => [['unit' => 'u1', 'start' => 0, 'end' => mb_strlen($text), 'quote' => $text]]];
            }
        }
        $receipt = ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
            'billing' => ['tenant_cost_points' => '1.00', 'user_charge_points' => '2.00', 'billing_status' => 'settled']];
        if ($persist) $persist($receipt);
        if (self::$failSettlementOnce) { self::$failSettlementOnce = false; throw new \RuntimeException('fixture settlement failure'); }
        return $receipt;
    }
    public static function recoverDurableResult(int $tenant, int $user, string $table, int $business, array $receipt): array
    { self::$recoveries++; return $receipt; }
}

class MarketImageModelRuntimeService
{
    public static array $requests = [];
    public static int $limit = 8;
    public static function options(int $tenant): array { return [['id' => 'market_image_model:fixture', 'name' => 'Fixture']]; }
    public static function modelGroup(int $tenant): array { return ['key' => 'image', 'options' => self::options($tenant)]; }
    public static function referenceImageLimitFromSnapshot(array $snapshot): int { return self::$limit; }
    public static function quote(int $tenant, array $params, int $quantity = 1): array
    {
        return ['tenant_cost_points' => '0.00', 'user_charge_points' => '0.00', 'market_snapshot' => ['model_code' => 'fixture'], 'billing_unit' => 'image', 'quantity' => 1];
    }
    public static function reserve(int $tenant, int $user, string $type, string $task, array $params, array $image, int $quantity): array
    {
        return ['app_task_id' => 0, 'consumption_id' => 0, 'market_snapshot' => []];
    }
    public static function linkBusinessTask(int $app, int $business): void {}
    public static function submit(int $consumption, array $params): array
    {
        self::$requests[] = $params;
        return ['status' => 'success', 'provider_task_id' => 'fixture_' . count(self::$requests), 'images' => [
            ['image_uri' => 'uploads/test/vnext-fixture-' . count(self::$requests) . '.png', 'width' => 900, 'height' => 1600, 'storage_engine' => 'local', 'storage_scope' => 'tenant']]];
    }
}
