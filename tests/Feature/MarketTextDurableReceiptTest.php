<?php
namespace Tests\Feature;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionLog;
use app\common\service\power\MarketTextModelRuntimeService as Runtime;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Real local ledgers, fixed provider receipts, no network or customer charges. */
final class MarketTextDurableReceiptTest extends TestCase
{
    private bool $transaction = false;
    private array $receipt;
    private int $tenant = 2000000791;
    private int $user;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to local DB fixtures');
        (new \think\App())->initialize();
        Db::startTrans(); $this->transaction = true;
        $this->user = (int)Db::name('user')->insertGetId(['tenant_id' => $this->tenant,
            'sn' => (string)random_int(10000000, 99999999), 'nickname' => 'durable receipt fixture', 'user_money' => 0]);
        $model = ['product_id' => 0, 'model_code' => 'durable_fixture', 'channel_code' => 'fixture',
            'protocol' => 'openai_chat', 'billing_unit_size' => 1000,
            'input' => ['sku_id' => 0, 'platform_price' => 0, 'tenant_price' => 0, 'usage_unit_size' => 1000],
            'output' => ['sku_id' => 0, 'platform_price' => 0, 'tenant_price' => 0, 'usage_unit_size' => 1000]];
        $method = new \ReflectionMethod(Runtime::class, 'createConsumption'); $method->setAccessible(true);
        $context = $method->invoke(null, $this->tenant, $this->user, $model, 'script_plan', ['durable_receipt_version' => 1],
            ['tenant_cost_points' => 0, 'user_charge_points' => 0], 0, 'aigc_short_drama', 'aigc_short_drama_script_task', 123);
        $this->receipt = ['durable_receipt_version' => 1, 'content' => '完整正文，包含最后一句。', 'content_source' => 'content',
            '_require_final_content' => true, 'app_task_id' => (int)$context['appTask']['id'],
            'consumption_id' => (int)$context['consumption']['id'], 'model_code' => 'durable_fixture',
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 2000],
            'billing' => ['user_charge_points' => 99999], 'provider_request_id' => 'fixture_receipt'];
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }
    private function recover(?array $receipt = null): array
    { return Runtime::recoverDurableResult($this->tenant, $this->user, 'aigc_short_drama_script_task', 123, $receipt ?? $this->receipt); }
    public function testIdempotentSettlementUsesFrozenPriceAndIgnoresReceiptBilling(): void
    {
        $a = $this->recover(); $b = $this->recover();
        self::assertSame($a, $b); self::assertSame($this->receipt['content'], $a['content']);
        self::assertSame('settled', $a['billing']['billing_status']); self::assertSame(0.0, $a['billing']['user_charge_points']);
        self::assertSame(1, Db::name('ai_consumption_event')->where(['consumption_id' => $this->receipt['consumption_id'], 'event_type' => 'settle'])->count());
        self::assertSame('success', AiAppTask::find($this->receipt['app_task_id'])['status']);
    }
    public function testMissingUsageAndInvalidFinalContentStayUnsettled(): void
    {
        foreach ([array_replace($this->receipt, ['usage' => []]), array_replace($this->receipt, ['content_source' => 'reasoning'])] as $receipt) {
            try { $this->recover($receipt); self::fail('Incomplete receipt accepted'); }
            catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
            self::assertSame('pending_usage', AiConsumptionLog::find($this->receipt['consumption_id'])['billing_status']);
        }
    }
    public function testTotalOnlyUsageCannotGuessDifferentTokenUnitPrices(): void
    {
        $c = AiConsumptionLog::find($this->receipt['consumption_id']); $price = $c['price_snapshot'];
        $price['output']['usage_unit_size'] = 1; $c->save(['price_snapshot' => $price]);
        $this->expectExceptionCode(409);
        $this->recover(array_replace($this->receipt, ['usage' => ['total_tokens' => 1000]]));
    }
    public function testForeignScopeOldLedgerAndRefundedLedgerCannotBeCharged(): void
    {
        foreach ([[$this->tenant + 1, $this->user, 123], [$this->tenant, $this->user + 1, 123], [$this->tenant, $this->user, 124]] as [$tenant, $user, $business]) {
            try { Runtime::recoverDurableResult($tenant, $user, 'aigc_short_drama_script_task', $business, $this->receipt); self::fail('Foreign receipt accepted'); }
            catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        }
        $c = AiConsumptionLog::find($this->receipt['consumption_id']); $c->save(['request_summary' => []]);
        try { $this->recover(); self::fail('Historical ledger accepted'); }
        catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        $c->save(['request_summary' => ['durable_receipt_version' => 1], 'billing_status' => 'refunded']);
        $this->expectExceptionCode(409); $this->recover();
    }
    public function testBalanceFailureLeavesSameReceiptRecoverableAndUsesStoredPrice(): void
    {
        $c = AiConsumptionLog::find($this->receipt['consumption_id']); $price = $c['price_snapshot'];
        $price['input']['tenant_price'] = 2; $price['output']['tenant_price'] = 3;
        $c->save(['price_snapshot' => $price]);
        // A fixture with no balance cannot settle, but must neither refund nor lose the reply.
        try { $this->recover(); self::fail('Empty account billed'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('不足', $e->getMessage()); }
        self::assertSame('pending_usage', AiConsumptionLog::find($this->receipt['consumption_id'])['billing_status']);
        Db::name('user')->where('id', $this->user)->update(['user_money' => 100]);
        $a = $this->recover(); $this->recover();
        self::assertSame(8.0, $a['billing']['user_charge_points']);
        self::assertSame(92.0, (float)Db::name('user')->where('id', $this->user)->value('user_money'));
        self::assertSame(1, Db::name('user_account_log')->where('user_id', $this->user)->count());
        self::assertSame(8.0, (float)AiAppTask::find($this->receipt['app_task_id'])['actual_user_price']);
    }
}
