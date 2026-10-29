<?php

namespace Tests\Feature;

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\ai\AiConsumptionLog;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use app\common\service\power\MarketApplicationApiRuntimeService as Applications;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Synthetic market rows; reserve/fail only, never calls submit or a supplier. */
class MarketVideoPreparationLifecycleTest extends TestCase
{
    /** @dataProvider resources */
    public function testPreparedQuotesReservationsAndRefundsAreConsistent(string $resource): void
    {
        if (getenv('AIGC_VIDEO_PREPARATION_DB_TESTS') !== '1') self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        self::assertContains(Db::connect()->getConfig('database'), ['x_cn', 'z_cn']);
        $user = (int)Db::name('user')->where('tenant_id', 1)->where('user_money', '>', 100)->order('user_money', 'desc')->value('id');
        if (!$user) self::markTestSkipped('Funded local rollback fixture unavailable');
        $key = 'phase2-' . bin2hex(random_bytes(8));
        Db::startTrans();
        try {
            Db::name('tenant')->where('id', 1)->lock(true)->find();
            Db::name('user')->where('id', $user)->lock(true)->find();
            $before = $this->balances($user);
            $metadata = ['params_schema' => ['properties' => [
                'prompt' => ['type' => 'string'], 'duration' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8],
                'resolution' => ['type' => 'string', 'enum' => ['720P']], 'aspect_ratio' => ['type' => 'string', 'enum' => ['1:1']],
            ], 'required' => ['prompt', 'duration']], 'category_code' => 'video', 'category_name' => '视频生成',
                'supported_durations' => [4], 'supported_ratios' => ['1:1'], 'supported_resolutions' => ['720P']];
            if ($resource === 'model') $metadata['material_capabilities'] = ['modes' => [['code' => 'text_to_video', 'method' => 'text_to_video', 'fields' => []]]];
            $product = PowerMarketProduct::create(['product_code' => $key, 'name' => 'Offline phase 2 fixture', 'resource_type' => $resource,
                'model_type' => 'video', 'source_code' => 'offline-fixture', 'upstream_resource_key' => $key,
                'upstream_model_code' => 'offline-video', 'upstream_channel_code' => 'offline',
                'upstream_app_code' => $resource === 'app_api' ? 'full_video' : '', 'upstream_api_code' => $resource === 'app_api' ? 'submit' : '',
                'source_payload' => ['category_code' => 'video', 'category_name' => '视频生成', 'market_metadata' => $metadata],
                'status' => 1, 'create_time' => time(), 'update_time' => time()]);
            $sku = PowerMarketSku::create(['product_id' => $product['id'], 'sku_key' => $key, 'title' => 'Offline fixture',
                'locked_params' => ['duration' => 4, 'resolution' => '720P'], 'usage_unit' => 'output_second', 'usage_unit_size' => 1,
                'upstream_price' => 1, 'sale_points' => 1, 'sale_status' => 1, 'status' => 1,
                'source_payload' => ['meter_summary' => '生成秒数，总点数向上取整'], 'create_time' => time(), 'update_time' => time()]);
            $request = ['resource_type' => $resource, 'model_id' => ($resource === 'model' ? 'market_video_model:' : 'market_video_app:') . $product['id'],
                'market_sku_id' => $sku['id'], 'prompt' => 'Offline fixture', 'duration' => 4, 'quality' => '720P', 'ratio' => '1:1', 'generation_method' => 'text_to_video'];
            if ($resource === 'app_api') $request['upstream_app_code'] = 'full_video';
            $quote = $resource === 'model' ? Videos::quote(1, $request) : Applications::quote(1, $request);
            $reserve = fn(string $businessKey) => $resource === 'model'
                ? Videos::reserve(1, $user, 'aigc_video', 'generate', 'aigc_video_task', $businessKey, $request, $request)
                : Applications::reserve(1, $user, 'generate', $businessKey, $request, $request, 1, 'aigc_video', 'aigc_video_task');
            $reserved = $reserve($key);
            self::assertSame($reserved['consumption_id'], $reserve($key)['consumption_id']);
            $log = AiConsumptionLog::find($reserved['consumption_id']);
            self::assertSame((float)$quote['user_charge_points'], (float)$log['reserved_user_price']);
            self::assertSame((float)$quote['tenant_cost_points'], (float)$log['reserved_tenant_cost']);
            self::assertSame(4.0, (float)$log['quantity']);
            self::assertSame('', $log['upstream_task_id']);
            self::assertSame(round($before['user'] - (float)$log['reserved_user_price'], 2), $this->balances($user)['user']);
            Videos::fail((int)$log['id'], 'Offline fixture failure');
            self::assertSame($before, $this->balances($user));
            Videos::fail((int)$log['id'], 'Duplicate failure');
            self::assertSame($before, $this->balances($user));
            self::assertSame('refunded', AiConsumptionLog::find($log['id'])['billing_status']);
            self::assertSame(1, (int)Db::name('ai_consumption_event')->where(['consumption_id' => $log['id'], 'event_type' => 'refund'])->count());
            $cancel = $reserve($key . '-cancel');
            Videos::cancel((int)$cancel['consumption_id']);
            Videos::cancel((int)$cancel['consumption_id']);
            self::assertSame($before, $this->balances($user));
            self::assertSame('refunded', AiConsumptionLog::find($cancel['consumption_id'])['billing_status']);
        } finally {
            Db::rollback();
        }
        self::assertSame(0, (int)Db::name('power_market_product')->where('product_code', $key)->count());
    }

    public static function resources(): array { return [['model'], ['app_api']]; }
    private function balances(int $user): array
    {
        return ['tenant' => (float)Db::name('tenant')->where('id', 1)->value('point_balance'),
            'user' => (float)Db::name('user')->where('id', $user)->value('user_money')];
    }
}
