<?php

namespace Tests\Feature;

use app\common\service\ai\AiTaskRecordService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AigcImageTaskModelDisplayContractTest extends TestCase
{
    public function testNanoBananaSelectorUsesItsReadableModelName(): void
    {
        $selector = 'market_nano_banana:101:' . base64_encode('nano-banana-2');

        self::assertSame('Nano Banana 2', AiTaskRecordService::modelDisplayName([
            'channel' => $selector,
            'model' => 'nano-banana-2',
        ], 'aigc_image'));
    }

    public function testImageMarketSelectorExposesItsProductIdForNameLookup(): void
    {
        $method = new ReflectionMethod(AiTaskRecordService::class, 'marketProductId');
        $method->setAccessible(true);

        self::assertSame(98, $method->invoke(null, [
            'channel' => 'market_image_model:98',
            'model' => 'market_image_model:98',
        ]));
    }

    public function testImageTaskApiAndAdminBundleUseTheReadableModelField(): void
    {
        $root = dirname(__DIR__, 2);
        $service = (string)file_get_contents($root . '/app/common/service/app/aigc_image/AigcImageService.php');
        $bundle = (string)file_get_contents($root . '/public/admin/assets/task-71RKXzI9.js');

        self::assertStringContainsString('self::appendTaskModelDisplay($row);', $service);
        self::assertStringContainsString("\$task['model_name'] = AiTaskRecordService::modelDisplayName", $service);
        self::assertStringContainsString('label:"模型",prop:"model_name"', $bundle);
        self::assertStringNotContainsString('label:"通道",prop:"channel"', $bundle);
    }

    public function testImageTaskTablePrioritizesCompletionStatusAndUsesRowNumbers(): void
    {
        $bundle = (string)file_get_contents(
            dirname(__DIR__, 2) . '/public/admin/assets/task-71RKXzI9.js'
        );

        $sequence = 'label:"序号",type:"index",index:t=>(Number(o(f).page)-1)*Number(o(f).size)+t+1';
        $status = 'label:"完成状态",width:"110",fixed:"left"';

        self::assertStringContainsString($sequence, $bundle);
        self::assertStringContainsString($status, $bundle);
        self::assertStringNotContainsString('label:"ID",prop:"id"', $bundle);
        self::assertLessThan(strpos($bundle, 'label:"任务ID",prop:"provider_task_id"'), strpos($bundle, $status));
        self::assertLessThan(strpos($bundle, 'label:"质量",prop:"quality"'), strpos($bundle, 'label:"用户消费",prop:"user_charge_points"'));
    }
}
