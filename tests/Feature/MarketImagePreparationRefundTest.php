<?php

namespace Tests\Feature;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionLog;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Explicit local rollback fixture. Invalid input stops before supplier I/O. */
class MarketImagePreparationRefundTest extends TestCase
{
    public function testInvalidPreparedSubmissionClosesOnlyItsReservationIdempotently(): void
    {
        if (getenv('AIGC_PREPARATION_DB_TESTS') !== '1') self::markTestSkipped('Explicit local rollback test only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        self::assertContains(Db::connect()->getConfig('database'), ['x_cn', 'z_cn']);
        $tenant = 1999999757;
        $before = (int)Db::name('ai_consumption_log')->where('tenant_id', $tenant)->count();
        Db::startTrans();
        try {
            $key = 'prepare-fixture-' . bin2hex(random_bytes(8));
            $task = AiAppTask::create(['tenant_id' => $tenant, 'user_id' => 0, 'task_no' => $key,
                'app_code' => 'aigc_image', 'action_code' => 'generate', 'business_table' => 'aigc_image_task',
                'status' => 'running', 'idempotency_key' => $key, 'create_time' => time(), 'update_time' => time()]);
            $log = AiConsumptionLog::create(['tenant_id' => $tenant, 'user_id' => 0, 'app_task_id' => $task['id'],
                'consume_no' => $key, 'app_code' => 'aigc_image', 'action_code' => 'generate', 'resource_type' => 'model',
                'model_code' => 'never-submit-fixture', 'run_status' => 'reserved', 'billing_status' => 'reserved',
                'reserved_tenant_cost' => 0, 'reserved_user_price' => 0, 'price_snapshot' => [
                    'model_code' => 'never-submit-fixture', 'market_metadata' => ['params_schema' => [
                        'prompt' => ['type' => 'string', 'required' => true],
                        'image_size' => ['type' => 'string', 'enum' => ['1K']],
                    ]],
                ], 'create_time' => time(), 'update_time' => time()]);
            try {
                Images::submit((int)$log['id'], ['prompt' => 'fixture', 'quality' => '8k']);
                self::fail('Invalid input must stop before provider submission');
            } catch (InvalidArgumentException) {
                self::assertSame('refunded', AiConsumptionLog::find($log['id'])['billing_status']);
            }
            self::assertSame('request_invalid', AiConsumptionLog::find($log['id'])['error_code']);
            self::assertSame('failed', AiAppTask::find($task['id'])['status']);
            self::assertSame('failed', Images::submit((int)$log['id'], ['prompt' => 'fixture', 'quality' => '8k'])['status']);
            Images::fail((int)$log['id'], 'duplicate failure');
            self::assertSame(1, (int)Db::name('ai_consumption_event')->where(['consumption_id' => $log['id'], 'event_type' => 'refund'])->count());
            self::assertSame('', AiConsumptionLog::find($log['id'])['upstream_task_id']);
        } finally {
            Db::rollback();
        }
        self::assertSame($before, (int)Db::name('ai_consumption_log')->where('tenant_id', $tenant)->count());
    }
}
