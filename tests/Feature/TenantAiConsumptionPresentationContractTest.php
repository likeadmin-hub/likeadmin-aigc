<?php

namespace Tests\Feature;

use app\common\service\ai\AiUsageService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TenantAiConsumptionPresentationContractTest extends TestCase
{
    public function testReservedSkuChargeIsFormattedAsAReadableUserDeduction(): void
    {
        $row = $this->formatConsumption([
            'id' => 116,
            'app_code' => 'aigc_image',
            'product_id' => 8,
            'sku_id' => 97,
            'quantity' => '1.000000',
            'usage_unit' => 'per_call',
            'price_snapshot' => ['sku_id' => 97, 'sku_key' => 'gpt_image_2_pro_1k'],
            'usage_snapshot' => [],
            'run_status' => 'running',
            'billing_status' => 'reserved',
            'reserved_tenant_cost' => '30.000000',
            'reserved_user_price' => '50.000000',
            'actual_tenant_cost' => '0.000000',
            'actual_user_price' => '0.000000',
            'create_time' => 0,
            'finish_time' => 0,
        ], [
            97 => ['id' => 97, 'title' => '图片 1K / 按张', 'sku_key' => 'gpt_image_2_pro_1k'],
        ], [
            8 => 'GPT Image 2 Pro',
        ], [
            'aigc_image' => 'AI 生图',
        ]);

        self::assertSame('consumption:116', $row['record_key']);
        self::assertSame('AI 生图', $row['app_name']);
        self::assertSame('GPT Image 2 Pro', $row['product_name']);
        self::assertSame('图片 1K / 按张', $row['sku_display']);
        self::assertSame('1 次', $row['usage_display']);
        self::assertSame('生成中', $row['run_status_text']);
        self::assertSame('已预扣', $row['billing_status_text']);
        self::assertSame('-50.00 算力（预扣）', $row['user_charge_text']);
        self::assertSame('-30.00 算力（预扣）', $row['tenant_cost_text']);
    }

    public function testTokenUsageShowsInputOutputSkusAndActualUsage(): void
    {
        $row = $this->formatConsumption([
            'id' => 117,
            'app_code' => 'aigc_llm',
            'product_id' => 9,
            'sku_id' => 101,
            'quantity' => '1290.000000',
            'usage_unit' => 'token',
            'price_snapshot' => [
                'input' => ['sku_id' => 101, 'sku_key' => 'input_tokens'],
                'output' => ['sku_id' => 102, 'sku_key' => 'output_tokens'],
            ],
            'usage_snapshot' => ['prompt_tokens' => 1234, 'completion_tokens' => 56],
            'run_status' => 'success',
            'billing_status' => 'settled',
            'reserved_tenant_cost' => 0,
            'reserved_user_price' => 0,
            'actual_tenant_cost' => '8.500000',
            'actual_user_price' => '12.345600',
            'create_time' => 0,
            'finish_time' => 0,
        ], [
            101 => ['id' => 101, 'title' => '输入 Token / 100万', 'sku_key' => 'input_tokens'],
            102 => ['id' => 102, 'title' => '输出 Token / 100万', 'sku_key' => 'output_tokens'],
        ]);

        self::assertSame('输入 Token / 100万 / 输出 Token / 100万', $row['sku_display']);
        self::assertSame('输入 1234 / 输出 56 Token', $row['usage_display']);
        self::assertSame('-12.35 算力', $row['user_charge_text']);
        self::assertSame('已结算', $row['billing_status_text']);
    }

    public function testRefundedReservationShowsTheReturnedAmountInsteadOfACharge(): void
    {
        $row = $this->formatConsumption([
            'id' => 118,
            'quantity' => 1,
            'usage_unit' => 'image',
            'run_status' => 'failed',
            'billing_status' => 'refunded',
            'reserved_tenant_cost' => 20,
            'reserved_user_price' => 30,
            'actual_tenant_cost' => 0,
            'actual_user_price' => 0,
            'create_time' => 0,
            'finish_time' => 0,
        ]);

        self::assertSame(0.0, $row['user_charge_points']);
        self::assertSame('已退回 30.00 算力', $row['user_charge_text']);
        self::assertSame('已退回', $row['billing_status_text']);
    }

    public function testTenantBundleUsesSequenceAndReadableBillingFieldsWithoutUpstreamIds(): void
    {
        $bundle = (string)file_get_contents(dirname(__DIR__, 2) . '/public/admin/assets/consumption-DBPjHJlp.js');

        self::assertStringContainsString('label:"序号",type:"index"', $bundle);
        self::assertStringContainsString('(Number(o(k).page)-1)*Number(o(k).size)+a+1', $bundle);
        self::assertStringContainsString('label:"用户扣费"', $bundle);
        self::assertStringContainsString('a.user_charge_text', $bundle);
        self::assertStringContainsString('label:"SKU 规格"', $bundle);
        self::assertStringContainsString('a.sku_display', $bundle);
        self::assertStringContainsString('label:"实际用量"', $bundle);
        self::assertStringContainsString('a.usage_display', $bundle);
        self::assertStringNotContainsString('上游任务 ID', $bundle);
        self::assertStringNotContainsString('label:"上游任务"', $bundle);
        self::assertStringNotContainsString('upstream_task_id', $bundle);
        self::assertStringNotContainsString('upstream_request_id', $bundle);
    }

    private function formatConsumption(
        array $row,
        array $skuMap = [],
        array $productMap = [],
        array $appNames = []
    ): array {
        $method = new ReflectionMethod(AiUsageService::class, 'formatConsumption');
        $method->setAccessible(true);

        return $method->invoke(null, $row, $skuMap, $productMap, $appNames, '算力');
    }
}
