<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class UnifiedCreateImageQuantityPricingContractTest extends TestCase
{
    public function testUnifiedCreateImagePriceDisplayUsesCurrentQuantity(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/pages/ai/create.vue');
        self::assertStringContainsString('Number.parseInt(optionState.value.count, 10)', $source);
        self::assertStringContainsString('Number(selectedUnitPrice.value) * selectedQuantity.value', $source);
        self::assertStringContainsString('quantity:', \Tests\Support\CompiledAssets::pcPage('ai/create'));
        self::assertStringContainsString('selectedQuantity.value', $source);
    }

}
