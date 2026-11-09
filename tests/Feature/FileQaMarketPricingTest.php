<?php
namespace Tests\Feature;

use app\common\service\open_platform\{OpenException, OpenFileQaPricing, OpenMarketBilling, OpenStore};
use app\common\service\power\{MarketFileQaTokenPricing, PowerMarketService, TenantPowerMarketService};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class FileQaMarketPricingTest extends TestCase
{
    private const DECLARATION = ['usage_unit_size' => 1000000, 'effective_rate_config' => ['input' => 2600, 'cached_input' => 0, 'output' => 13000]];

    public function testCachedTokensMarkupAndOutOfBoundReceipts(): void
    {
        $sku = ['id' => 123, 'product_id' => 234, 'sku_key' => 'token', 'source_payload' => self::DECLARATION];
        $markup = [['scope_type' => 'global', 'scope_id' => 0, 'mode' => 'percent', 'value' => '10']];
        $sku['token_rates'] = MarketFileQaTokenPricing::display($sku, 1, [], $markup);
        $rule = ['basis' => 'usage', 'max_units' => 11000, 'usage_path' => 'usage.total_tokens'];
        $quote = OpenFileQaPricing::quote($rule, $sku);
        self::assertSame(143., $quote['tenant']);
        self::assertSame(157.3, $quote['user']);
        self::assertNull(OpenFileQaPricing::settle($quote['rule'], ['usage' => ['total_tokens' => 11000]]));
        $usage = ['input_tokens' => 10000, 'cached_input_tokens' => 2000, 'output_tokens' => 1000];
        $settled = OpenFileQaPricing::settle($quote['rule'], ['usage' => $usage]);
        self::assertSame(33.8, $settled['tenant']);
        self::assertSame(37.18, $settled['user']);
        $usage['output_tokens']++;
        $this->expectException(OpenException::class);
        OpenFileQaPricing::settle($quote['rule'], ['usage' => $usage]);
    }

    /** @dataProvider operations */
    public function testSyncedSkuDrivesDisplayReservationAndIdempotentLedger(string $api): void
    {
        if (getenv('FILE_QA_BILLING_DB_TESTS') !== '1') self::markTestSkipped('Local rollback-only billing fixture');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'), ['localhost','127.0.0.1']);
        self::assertContains(Db::connect()->getConfig('database'), ['x_cn','z_cn']);
        request()->tenantId = 1;
        Db::startTrans();
        try {
            $resource = ['app_code' => 'file_qa', 'api_code' => $api];
            $item = ['type' => 'app_api', 'resource' => $resource];
            $product = (new \ReflectionMethod(PowerMarketService::class, 'upsertProduct'))->invoke(null, 'app_api', $resource, $item);
            $id = (int)$product['id'];
            $key = 'fixture-'.bin2hex(random_bytes(4));
            $declaration = self::DECLARATION + ['sku_key' => $key, 'billing_mode' => 'usage', 'usage_unit' => 'token', 'price' => ['points' => 0]];
            (new \ReflectionMethod(PowerMarketService::class, 'upsertSku'))->invoke(null, $id, $declaration, $item);
            $skuId = (int)Db::name('power_market_sku')->where(['product_id' => $id, 'sku_key' => $key])->value('id');
            foreach ([0, 1] as $owner) {
                Db::name('power_market_price_rule')->insert(['owner_id' => $owner, 'scope_type' => 'sku', 'scope_id' => $skuId, 'mode' => 'percent', 'value' => 0]);
                $setting = ['tenant_id' => $owner, 'enabled' => 1, 'config' => json_encode(['models' => [(string)$id]])];
                OpenStore::table('setting')->replace()->insert($setting);
            }
            $platform = array_column(PowerMarketService::detail($id)['skus'], null, 'id')[$skuId];
            $tenant = array_column(TenantPowerMarketService::detail(1, $id)['skus'], null, 'id')[$skuId];
            self::assertSame('2600.000000', $platform['token_rates']['cost']['input']);
            self::assertSame('13000.000000', $tenant['token_rates']['sale']['output']);
            self::assertArrayNotHasKey('effective_rate_config', $tenant['source_payload']);
            $path = 'data.result.usage.total_tokens';
            $op = ['executor' => 'json', 'billable' => true, 'product_id' => $id,
                'billing' => [['sku_id' => $skuId, 'basis' => 'usage', 'max_units' => 11000, 'usage_path' => $path]]];
            $budget = OpenMarketBilling::quote(1, $op, []);
            self::assertSame(143., $budget['user']);
            $rid = 'req_'.bin2hex(random_bytes(12));
            $request = ['tenant_id' => 1, 'user_id' => 1, 'key_id' => 0, 'request_id' => $rid, 'kind' => 'market', 'method' => 'POST',
                'path' => '/api/v1/apps/file_qa/'.$api, 'payload_hash' => hash('sha256',$rid), 'snapshot' => '{}', 'response_blob' => '', 'consumption_ids' => '[]'];
            $request['id'] = OpenStore::table('request')->insertGetId($request);
            Db::name('user')->where('id', 1)->update(['user_money' => 200]);
            Db::name('tenant')->where('id', 1)->update(['point_balance' => 200]);
            OpenMarketBilling::reserve($request, $budget);
            OpenMarketBilling::settle($request, []);
            self::assertSame('pending_usage', OpenStore::table('request')->where('id', $request['id'])->value('billing_status'));
            $usage = ['usage' => ['input_tokens' => 10000, 'output_tokens' => 1000, 'total_tokens' => 11000]];
            $result = $api === 'parse' ? ['episodes' => [['episode_no' => 1]]] : ['answer' => '文档摘要'];
            $receipt = ['data' => ['result' => $result + $usage, 'usage' => ['points_cost' => 999]]];
            OpenMarketBilling::settle($request, $receipt);
            OpenMarketBilling::settle($request, $receipt);
            $bill = Db::name('ai_consumption_log')->where('consume_no', $rid)->find();
            self::assertSame('settled', $bill['billing_status']);
            self::assertSame(39., (float)$bill['actual_user_price']);
            self::assertSame(39., (float)$bill['actual_tenant_cost']);
            self::assertSame(10000, OpenStore::json($bill['usage_snapshot'])[0]['token_breakdown']['input']);
            self::assertSame(161., (float)Db::name('user')->where('id', 1)->value('user_money'));
            self::assertSame(161., (float)Db::name('tenant')->where('id', 1)->value('point_balance'));
        } finally { Db::rollback(); }
    }

    public static function operations(): array { return [['parse'], ['chat']]; }
}
