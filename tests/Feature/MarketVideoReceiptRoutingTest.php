<?php

namespace Tests\Feature;

use app\common\model\ai\{AiAppTask, AiConsumptionLog};
use app\common\service\power\{MarketGenerationRuntime as Runtime, MarketVideoRuntimeService as Videos,
    MarketVideoModelRuntimeService, MarketVideoAppRuntimeService};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\facade\Db;

/** Frozen routing only: never submits to a supplier or resumes a paid task. */
class MarketVideoReceiptRoutingTest extends TestCase
{
    /** @dataProvider supplierProtocols */
    public function testSupplierProtocolCannotReplaceTheLocalVideoLifecycle(string $resource, array $source): void
    {
        $metadata = $source + ['generation_protocol' => 'flat_video_v1'];
        $market = ['product' => ['id' => 2147483000, 'resource_type' => $resource, 'model_type' => $resource === 'model' ? 'video' : '',
            'upstream_model_code' => 'offline-video', 'upstream_channel_code' => 'offline',
            'upstream_app_code' => $resource === 'app_api' ? 'wan' : '', 'upstream_api_code' => 'submit',
            'source_payload' => ['market_metadata' => $metadata]],
            'sku' => ['id' => 2147483000, 'sku_key' => 'offline', 'locked_params' => [], 'usage_unit' => 'per_call',
                'usage_unit_size' => 1, 'upstream_price' => 1, 'sale_points' => 1, 'source_payload' => []], 'tenant_price' => 1];
        $snapshot = (new ReflectionMethod(Videos::class, 'snapshot'))->invoke(null, $market, 1.0);
        self::assertSame('video_generate', $snapshot['protocol']);
        self::assertSame($metadata, $snapshot['market_metadata']);
        self::assertSame($resource === 'model' ? 'video' : '', $snapshot['model_type']);
    }

    public static function supplierProtocols(): array
    {
        $rows = [];
        foreach (['model', 'app_api'] as $resource) foreach ([[], ['protocol' => ''], ['protocol' => null],
            ['protocol' => 'native_v1'], ['protocol' => 'application_api']] as $source) $rows[] = [$resource, $source];
        return $rows;
    }

    /** @dataProvider legacyReceipts */
    public function testOwnedLegacyAndCanonicalVideoReceiptsReplayTerminalStateWithoutCatalogueOrCharging(array $snapshot, string $protocol, string $app): void
    {
        $this->fixture($snapshot, $protocol, $app, function ($task, $receipt) use ($snapshot) {
            $before = [$task->toArray(), $receipt->toArray()];
            $runtime = Runtime::consumption((int)$receipt['id'], (int)$receipt['tenant_id'], (int)$receipt['user_id']);
            self::assertSame($snapshot['resource_type'] === 'model' ? MarketVideoModelRuntimeService::class : MarketVideoAppRuntimeService::class, $runtime->adapter);
            foreach (['submit', 'refresh'] as $operation) {
                $result = $operation === 'submit'
                    ? $runtime->submit((int)$receipt['id'], [], (int)$receipt['tenant_id'], (int)$receipt['user_id'])
                    : $runtime->refresh((int)$receipt['id'], (int)$receipt['tenant_id'], (int)$receipt['user_id']);
                self::assertSame('failed', $result['status']);
                self::assertSame('', $result['provider_task_id']);
                self::assertSame('failed', $result['generation_task']['status']);
            }
            self::assertSame($before, [AiAppTask::find($task['id'])->toArray(), AiConsumptionLog::find($receipt['id'])->toArray()]);
            self::assertSame(0, (int)Db::name('ai_consumption_event')->where('consumption_id', $receipt['id'])->count());
            self::assertSame(0, (int)Db::name('ai_task_job')->where('consumption_id', $receipt['id'])->count());
            foreach (['tenant_id', 'user_id'] as $foreign) {
                $owner = ['tenant_id' => (int)$receipt['tenant_id'], 'user_id' => (int)$receipt['user_id']];
                $owner[$foreign]++;
                try { Runtime::consumption((int)$receipt['id'], $owner['tenant_id'], $owner['user_id']); self::fail('Foreign receipt accepted'); }
                catch (\InvalidArgumentException $error) { self::assertStringContainsString('无权访问', $error->getMessage()); }
            }
        });
    }

    public static function legacyReceipts(): array
    {
        $rows = [];
        foreach (['aigc_video', 'aigc_canvas', 'aigc_short_drama', 'aigc_micro_video'] as $app) {
            foreach (['h3-video', 'q4-preview-video', 'wan3.0-video', 'veo3.1-fast', 'generic-video'] as $code) {
                $rows[] = [['resource_type' => 'model', 'model_code' => $code, 'product_id' => 2147483000,
                    'market_metadata' => ['model_type' => 'video', 'protocol' => '']], '', $app];
            }
            $rows[] = [['resource_type' => 'model', 'model_code' => 'pre-metadata', 'product_id' => 2147483000], '', $app];
            $rows[] = [['resource_type' => 'model', 'model_type' => 'video', 'product_id' => 2147483000], 'video_generate', $app];
            foreach (['seedance', 'wan', 'grok_video', 'happy_horse', 'full_video'] as $upstream) {
                $rows[] = [['resource_type' => 'app_api', 'app_code' => $upstream, 'product_id' => 2147483000], '', $app];
                $rows[] = [['resource_type' => 'app_api', 'app_code' => $upstream, 'product_id' => 2147483000], 'video_generate', $app];
            }
        }
        return $rows;
    }

    /** @dataProvider invalidReceipts */
    public function testUnknownOrContradictoryReceiptCannotBeGuessedIntoAVideoRuntime(array $snapshot, string $protocol, string $resource, string $action): void
    {
        $this->fixture($snapshot, $protocol, 'aigc_video', function ($task, $receipt) {
            $this->expectException(\InvalidArgumentException::class);
            Runtime::consumption((int)$receipt['id'], (int)$receipt['tenant_id'], (int)$receipt['user_id']);
        }, $resource, $action);
    }

    public static function invalidReceipts(): array
    {
        return [
            [['resource_type' => 'model', 'model_type' => 'image', 'model_code' => 'image'], '', 'model', 'video_generate'],
            [['resource_type' => 'model', 'model_type' => 'text', 'model_code' => 'text'], '', 'model', 'video_generate'],
            [['resource_type' => 'model', 'model_type' => '', 'market_metadata' => ['model_type' => 'image'], 'model_code' => 'image'], '', 'model', 'video_generate'],
            [['resource_type' => 'model', 'model_type' => 'video', 'market_metadata' => ['model_type' => 'image']], '', 'model', 'video_generate'],
            [['resource_type' => 'model', 'model_type' => 'video'], 'unknown_v9', 'model', 'video_generate'],
            [['resource_type' => 'model', 'model_code' => 'unknown'], '', 'model', 'generate'],
            [['resource_type' => 'model', 'model_type' => 'video'], '', 'app_api', 'video_generate'],
            [['resource_type' => 'app_api', 'app_code' => 'unknown'], '', 'app_api', 'video_generate'],
            [['resource_type' => 'app_api', 'app_code' => 'music_generation'], '', 'app_api', 'video_generate'],
        ];
    }

    private function fixture(array $snapshot, string $protocol, string $app, callable $check, ?string $resource = null, string $action = 'video_generate'): void
    {
        if (getenv('AIGC_TRANSFER_CHECKPOINT_DB_TESTS') !== '1') self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        self::assertContains(Db::connect()->getConfig('database'), ['x_cn', 'z_cn']);
        Db::startTrans();
        try {
            $key = 'video-routing-' . bin2hex(random_bytes(8));
            $owner = ['tenant_id' => 199999953, 'user_id' => 199999953];
            $task = AiAppTask::create($owner + ['task_no' => $key, 'app_code' => $app, 'action_code' => $action,
                'business_table' => 'aigc_video_task', 'business_id' => 199999953, 'idempotency_key' => $key,
                'status' => 'failed', 'progress' => 100, 'create_time' => time(), 'update_time' => time(), 'finish_time' => time()]);
            $receipt = AiConsumptionLog::create($owner + ['consume_no' => $key, 'app_task_id' => $task['id'], 'app_code' => $app,
                'action_code' => $action, 'provider' => 'power_market', 'resource_type' => $resource ?? $snapshot['resource_type'],
                'protocol' => $protocol, 'price_snapshot' => $snapshot, 'run_status' => 'failed', 'billing_status' => 'refunded',
                'error_code' => 'fixture_failure', 'error_message' => 'Offline fixture failure',
                'create_time' => time(), 'update_time' => time(), 'finish_time' => time()]);
            $check($task, $receipt);
        } finally { Db::rollback(); }
    }
}
