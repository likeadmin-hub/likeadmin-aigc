<?php

namespace Tests\Feature;

use app\common\service\ai\AiTaskRecordService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AigcVideoTaskModelDisplayContractTest extends TestCase
{
    public function testVideoMarketSelectorExposesItsProductIdForNameLookup(): void
    {
        $method = new ReflectionMethod(AiTaskRecordService::class, 'marketProductId');
        $method->setAccessible(true);

        self::assertSame(103, $method->invoke(null, [
            'channel' => 'market_video_model:103',
            'model' => 'market_video_model:103',
        ]));
    }

    public function testVideoTaskApiAndAdminBundleUseTheReadableModelField(): void
    {
        $root = dirname(__DIR__, 2);
        $service = (string)file_get_contents($root . '/app/common/service/app/aigc_video/AigcVideoService.php');
        $bundle = (string)file_get_contents($root . '/public/admin/assets/task-Dg4_gLl-.js');

        self::assertStringContainsString('self::appendTaskModelDisplay($row);', $service);
        self::assertStringContainsString('self::appendTaskModelDisplay($data);', $service);
        self::assertStringContainsString("\$task['model_name'] = AiTaskRecordService::modelDisplayName", $service);
        self::assertStringContainsString('label:"模型",prop:"model_name"', $bundle);
        self::assertStringNotContainsString('label:"通道",prop:"channel"', $bundle);
    }
}
