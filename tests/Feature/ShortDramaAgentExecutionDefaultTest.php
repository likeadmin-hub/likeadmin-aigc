<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\canvas_agent\FeatureGate;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaAgentExecutionDefaultTest extends TestCase
{
    /** @dataProvider configurations */
    public function testExecutionDefaultsOnWithoutOverridingExplicitTenantSwitches(array $config, bool $expected): void
    {
        $method = new ReflectionMethod(FeatureGate::class, 'executionEnabledInConfig');
        $method->setAccessible(true);
        self::assertSame($expected, $method->invoke(null, $config));
    }

    public function configurations(): array
    {
        return [
            'missing or inactive tenant' => [[], false],
            'legacy config without agent settings' => [['models' => []], true],
            'legacy config with empty agent settings' => [['canvas_agent' => []], true],
            'conversation enabled without execution setting' => [['canvas_agent' => ['enabled' => true]], true],
            'execution explicitly enabled' => [['canvas_agent' => ['execution_enabled' => true]], true],
            'execution explicitly disabled' => [['canvas_agent' => ['execution_enabled' => false]], false],
            'numeric execution disable' => [['canvas_agent' => ['execution_enabled' => 0]], false],
            'string execution disable' => [['canvas_agent' => ['execution_enabled' => '0']], false],
            'agent disabled overrides execution enable' => [['canvas_agent' => ['enabled' => false, 'execution_enabled' => true]], false],
            'legacy enabled string values' => [['canvas_agent' => ['enabled' => '1', 'execution_enabled' => 'true']], true],
        ];
    }
}
