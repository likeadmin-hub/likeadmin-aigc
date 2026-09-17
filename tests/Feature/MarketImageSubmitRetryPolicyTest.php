<?php

namespace Tests\Feature;

use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketImageProviderRequestException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketImageSubmitRetryPolicyTest extends TestCase
{
    public function testOnlyExplicitProviderCapacityRejectionsAreRetryable(): void
    {
        $method = new ReflectionMethod(MarketImageModelRuntimeService::class, 'isExplicitTransientProviderResponse');
        $method->setAccessible(true);

        self::assertTrue($method->invoke(null, 429, ['error' => ['message' => 'too many requests']]));
        self::assertTrue($method->invoke(null, 503, ['error' => ['message' => 'unavailable']]));
        self::assertTrue($method->invoke(null, 400, ['error' => ['code' => 'rate_limit_exceeded']]));
        self::assertFalse($method->invoke(null, 400, ['error' => ['code' => 'invalid_parameter']]));
        self::assertFalse($method->invoke(null, 422, ['error' => ['code' => 'invalid_prompt']]));
    }

    public function testProviderRequestExceptionKeepsRetryabilitySeparateFromMessage(): void
    {
        self::assertTrue((new MarketImageProviderRequestException('temporary', true, 429))->isRetryable());
        self::assertFalse((new MarketImageProviderRequestException('invalid parameter', false, 400))->isRetryable());
    }

    public function testRetryBackoffIsBoundedAndDeterministic(): void
    {
        $method = new ReflectionMethod(MarketImageModelRuntimeService::class, 'submitRetryDelayMs');
        $method->setAccessible(true);

        self::assertSame(700, $method->invoke(null, 1));
        self::assertSame(1800, $method->invoke(null, 2));
        self::assertSame(1800, $method->invoke(null, 99));
    }
}
