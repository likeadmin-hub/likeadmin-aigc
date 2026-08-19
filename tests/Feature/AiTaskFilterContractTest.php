<?php

namespace Tests\Feature;

use app\common\service\ai\AiTaskRecordService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AiTaskFilterContractTest extends TestCase
{
    public function testTaskTypeCategoriesMatchTheDisplayedTaskFamilies(): void
    {
        $method = new ReflectionMethod(AiTaskRecordService::class, 'sourceMatchesTaskType');
        $method->setAccessible(true);

        self::assertTrue($method->invoke(null, 'aigc_image', ['media_type' => 'image'], 'image'));
        self::assertTrue($method->invoke(null, 'aigc_video', ['media_type' => 'video'], 'video'));
        self::assertTrue($method->invoke(null, 'aigc_llm', ['media_type' => 'text'], 'text'));
        self::assertTrue($method->invoke(null, 'aigc_short_drama', ['media_type' => 'none'], 'short_drama'));
        self::assertFalse($method->invoke(null, 'aigc_short_drama', ['media_type' => 'none'], 'video'));
    }

    public function testApplicationFilterAcceptsDisplayNamesAndCodes(): void
    {
        $method = new ReflectionMethod(AiTaskRecordService::class, 'matchingAppCodes');
        $method->setAccessible(true);

        self::assertContains('aigc_fitting', $method->invoke(null, 'AI试衣'));
        self::assertContains('aigc_product_promo_video', $method->invoke(null, '宣传视频'));
        self::assertSame(['custom_app'], $method->invoke(null, 'custom_app'));
    }

    public function testUnifiedTasksUseProtocolWithoutLosingShortDramaCategory(): void
    {
        $method = new ReflectionMethod(AiTaskRecordService::class, 'unifiedTaskCategory');
        $method->setAccessible(true);

        self::assertSame('image', $method->invoke(null, [
            'app_code' => 'aigc_canvas',
            'protocol' => 'image_generate',
        ]));
        self::assertSame('short_drama', $method->invoke(null, [
            'app_code' => 'aigc_short_drama',
            'protocol' => 'text_generate',
        ]));
    }

    public function testAdminBundleSendsAllNewFilterParameters(): void
    {
        $bundle = (string)file_get_contents(dirname(__DIR__, 2) . '/public/admin/assets/index-BmV1gMRX.js');

        foreach (['task_type:""', 'app_code:""', 'model:""', 'channel:""'] as $parameter) {
            self::assertStringContainsString($parameter, $bundle);
        }
        foreach (['label:"执行类型"', 'label:"应用"', 'label:"模型"', 'label:"渠道"'] as $control) {
            self::assertStringContainsString($control, $bundle);
        }
        self::assertStringContainsString('label:"短剧",value:"short_drama"', $bundle);
    }

    public function testBackendFiltersBothBaseAndUnifiedTaskRecords(): void
    {
        $recordSource = (string)file_get_contents(dirname(__DIR__, 2) . '/app/common/service/ai/AiTaskRecordService.php');
        $usageSource = (string)file_get_contents(dirname(__DIR__, 2) . '/app/common/service/ai/AiUsageService.php');

        self::assertStringContainsString("(\$params['model'] ?? '')", $recordSource);
        self::assertStringContainsString("(\$params['channel'] ?? '')", $recordSource);
        self::assertStringContainsString('matchingMarketProductIds', $recordSource);
        self::assertStringContainsString('applyApplicationFilter', $recordSource);
        self::assertStringContainsString('applyConsumptionFilters', $usageSource);
        self::assertStringContainsString('whereExists($consumptionQuery)', $usageSource);
        self::assertStringContainsString('attachConsumptionContext($rows, $params, $tenantId)', $usageSource);
    }
}
