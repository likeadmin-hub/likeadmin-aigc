<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class UnifiedCreateImageQuantityPricingContractTest extends TestCase
{
    public function testCompiledCreationRouteIncludesQuantityAndServerQuote(): void
    {
        // Runtime quantity, total-price and stale-response behavior is covered
        // by web/pc/tests/generation-quote-behavior.test.mjs against the page's
        // actual computed values and shared quote composable.
        $page = \Tests\Support\CompiledAssets::pcPage('ai/create');
        self::assertStringContainsString('quantity:', $page);
        self::assertStringContainsString('user_charge_points', $page);
    }

}
