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
