<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaFileQaLifecycleContractTest extends TestCase
{
    public function testOnlyTheExplicitMarketFileQaSourceUsesTheApplicationLifecycle(): void
    {
        self::assertTrue($this->invoke('isMarketFileQaParseRequest', ['source' => 'market_file_qa_parse']));
        self::assertFalse($this->invoke('isMarketFileQaParseRequest', ['source' => 'script_prompt']));
        self::assertFalse($this->invoke('isMarketFileQaParseRequest', []));
    }

    public function testFileQaMirrorBindsConsumptionAndBypassesTheLlmStaleGuard(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/app/aigc_short_drama/AigcShortDramaService.php');

        self::assertIsString($source);
        self::assertStringContainsString("'consumption_id' => (int)\$reserve['consumption_id']", $source);
        self::assertStringContainsString("'source_app_code' => 'file_qa'", $source);
        self::assertStringContainsString('if (self::isMarketFileQaParseRequest(self::jsonDecode((string)($taskData[\'request_json\'] ?? \'\'))))', $source);
        self::assertStringContainsString('Historical rows may have been created before the consumption link', $source);

        $guard = strpos($source, 'private static function recoverStaleScriptPlanTask');
        $genericFail = strpos($source, 'MarketTextModelRuntimeService::failAppTask', $guard);
        $fileQaGuard = strpos($source, 'isMarketFileQaParseRequest', $guard);
        self::assertIsInt($guard);
        self::assertIsInt($genericFail);
        self::assertIsInt($fileQaGuard);
        self::assertLessThan($genericFail, $fileQaGuard);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
