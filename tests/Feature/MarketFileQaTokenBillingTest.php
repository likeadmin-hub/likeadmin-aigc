<?php
namespace Tests\Feature;

use app\common\service\power\{MarketFileQaTokenPricing as Pricing, MarketFileQaAppRuntimeService as Runtime};
use app\common\model\ai\{AiAppTask, AiConsumptionLog};
use app\common\model\user\UserAccountLog;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class MarketFileQaTokenBillingTest extends TestCase
{
    private function pricing(array $platform = [], array $tenant = []): array
    {
        return Pricing::snapshot(['id' => 257, 'product_id' => 172, 'usage_unit_size' => 1000000],
            ['effective_rate_config' => ['input' => 2600, 'cached_input' => 0, 'output' => 13000]], $platform, $tenant);
    }

    private function receipt(): array
    {
        return ['data' => ['status' => 'success', 'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'points_cost' => 38.43],
            'result' => ['episodes' => [['episode_no' => 1], ['episode_no' => 2]], 'usage' => ['input_tokens' => 2355, 'output_tokens' => 2485, 'total_tokens' => 4840]]]];
    }

    public function testSeparateRatesUseRealTokenBreakdownInsteadOfTheZeroScalarPrice(): void
    {
        $snapshot = ['platform_price' => 0, 'tenant_price' => 0, 'token_pricing' => $this->pricing()];
        [$tenant, $user, $usage] = Pricing::amounts($snapshot, $this->receipt());
        self::assertSame(38.43, $tenant);
        self::assertSame(38.43, $user);
        self::assertSame(['input' => 2355, 'cached_input' => 0, 'output' => 2485, 'total_tokens' => 4840], $usage);
        $changed = $this->receipt(); $changed['data']['usage']['points_cost'] = 999999;
        self::assertSame([$tenant, $user, $usage], Pricing::amounts($snapshot, $changed), 'Reported points are audit evidence, not an unbounded charge instruction');
    }

    public function testCachedInputIsNotChargedTwiceAndMarkupUsesFrozenRules(): void
    {
        $rule = [['scope_type' => 'global', 'scope_id' => 0, 'mode' => 'percent', 'value' => '20']];
        $snapshot = ['token_pricing' => $this->pricing([], $rule)];
        $response = ['data' => ['result' => ['usage' => ['input_tokens' => 2000, 'output_tokens' => 1000, 'cached_input_tokens' => 1000, 'total_tokens' => 3000]]]];
        [$tenant, $user] = Pricing::amounts($snapshot, $response);
        self::assertSame(15.6, $tenant);self::assertSame(18.72, $user);
        self::assertSame('3120.000000', $snapshot['token_pricing']['tenant']['input']);
        self::assertSame('15600.000000', $snapshot['token_pricing']['tenant']['output']);
    }

    /** @dataProvider invalidUsages */
    public function testIncompleteInvalidAndUnrelatedUsageNeverSettlesAsFree(array $receipt): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pricing::amounts(['token_pricing' => $this->pricing()], $receipt);
    }
    public static function invalidUsages(): array
    {
        return [[[]], [['data' => ['metadata' => ['usage' => ['input_tokens' => 2, 'output_tokens' => 3]]]]],
            [['data' => ['usage' => ['total_tokens' => 500]]]],
            [['usage' => ['input_tokens' => -1, 'output_tokens' => 3]]],
            [['usage' => ['input_tokens' => true, 'output_tokens' => 3]]],
            [['usage' => ['input_tokens' => 2, 'output_tokens' => 3, 'total_tokens' => 6]]],
            [['usage' => ['input_tokens' => 2, 'output_tokens' => 3, 'cached_input_tokens' => 4]]]];
    }

    public function testIncompleteAndBelowCostPricesCannotBecomeFree(): void
    {
        self::assertNull(Pricing::snapshot([], [], [], []), 'Historical scalar contracts stay separate');
        $this->expectExceptionMessage('低于进货价');
        $this->pricing([], [['scope_type' => 'sku', 'scope_id' => 257, 'mode' => 'manual', 'value' => '0']]);
    }

    public function testFreshDeclarationRetainsItsBillingUnitWhenTheLocalCatalogueIsStale(): void
    {
        $pricing = Pricing::snapshot(['id' => 257, 'product_id' => 172, 'usage_unit_size' => 1000],
            ['usage_unit_size' => 1000000, 'effective_rate_config' => ['input' => 2600, 'output' => 13000]], [], []);
        self::assertSame(1000000., $pricing['usage_unit_size']);
        self::assertSame(38.43, Pricing::amounts(['token_pricing' => $pricing], $this->receipt())[1]);
    }

    /** @dataProvider scenarios */
    public function testLocalTransactionalSettlementAndDuplicateReceipts(string $scenario): void
    {
        if (getenv('FILE_QA_BILLING_DB_TESTS') !== '1') self::markTestSkipped('Local rollback-only billing fixture');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        self::assertContains(Db::connect()->getConfig('database'), ['x_cn', 'z_cn']);
        request()->tenantId = 1;
        Db::startTrans();
        try {
            Db::name('tenant')->where('id', 1)->lock(true)->find();
            Db::name('user')->where('id', 1)->lock(true)->find();
            $beforeUser = (float)Db::name('user')->where('id', 1)->value('user_money');
            $beforeTenant = (float)Db::name('tenant')->where('id', 1)->value('point_balance');
            self::assertGreaterThan(38.43, $beforeUser);self::assertGreaterThan(38.43, $beforeTenant);
            $key = 'fileqa-bill-' . bin2hex(random_bytes(8));
            $task = AiAppTask::create(['task_no' => $key, 'tenant_id' => 1, 'user_id' => 1, 'app_code' => 'aigc_short_drama', 'status' => 'running', 'idempotency_key' => $key]);
            $snapshot = ['product_id' => 172, 'sku_id' => 257, 'platform_price' => 0, 'tenant_price' => 0, 'usage_unit' => 'token', 'usage_unit_size' => 1000000,
                'token_pricing' => $this->pricing()];
            if ($scenario === 'historical') unset($snapshot['token_pricing']);
            $c = AiConsumptionLog::create(['consume_no' => $key, 'app_task_id' => $task['id'], 'tenant_id' => 1, 'user_id' => 1, 'app_code' => 'aigc_short_drama',
                'run_status' => 'running', 'billing_status' => 'pending_usage', 'price_snapshot' => $snapshot]);
            $receipt = $this->receipt();
            if ($scenario === 'missing_usage') unset($receipt['data']['result']['usage']);
            if ($scenario === 'user_insufficient') Db::name('user')->where('id', 1)->update(['user_money' => 0]);
            if ($scenario === 'tenant_insufficient') Db::name('tenant')->where('id', 1)->update(['point_balance' => 0]);
            if ($scenario === 'canceled') Runtime::cancel((int)$c['id']);
            $settle = new \ReflectionMethod(Runtime::class, 'settle');
            $invoke = fn() => $settle->invoke(null, (int)$c['id'], $receipt, $receipt['data']['result'], '', 'offline-fileqa');
            if (in_array($scenario, ['user_insufficient', 'tenant_insufficient', 'missing_usage'], true)) {
                try { $invoke(); self::fail('Unsettled receipts must not succeed'); } catch (\RuntimeException | \InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
                self::assertSame('running', AiConsumptionLog::find($c['id'])['run_status']);
                self::assertSame(0, UserAccountLog::where('source_sn', $key)->count());
                self::assertSame(0, Db::name('tenant_point_log')->where('source_sn', $key)->count());
                return;
            }
            // Changing current catalogue metadata cannot reprice the frozen task.
            Db::name('power_market_sku')->where('id', 257)->update(['source_payload' => json_encode(['effective_rate_config' => ['input' => 99999, 'output' => 99999]])]);
            $invoke();$invoke();
            $expected = in_array($scenario, ['historical', 'canceled'], true) ? 0. : 38.43;
            $saved = AiConsumptionLog::find($c['id']);
            self::assertSame($expected, (float)$saved['actual_user_price']);self::assertSame($expected, (float)$saved['actual_tenant_cost']);
            self::assertSame(round($beforeUser - $expected, 2), (float)Db::name('user')->where('id', 1)->value('user_money'));
            self::assertSame(round($beforeTenant - $expected, 2), (float)Db::name('tenant')->where('id', 1)->value('point_balance'));
            self::assertSame($expected > 0 ? 1 : 0, UserAccountLog::where('source_sn', $key)->count());
            self::assertSame($expected > 0 ? 1 : 0, Db::name('tenant_point_log')->where('source_sn', $key)->count());
        } finally { Db::rollback(); }
    }
    public static function scenarios(): array { return [['paid'], ['historical'], ['canceled'], ['user_insufficient'], ['tenant_insufficient'], ['missing_usage']]; }
}
