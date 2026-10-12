<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CanvasImageQuantityPricingContractTest extends TestCase
{
    public function testCanvasImagePriceDisplayUsesCurrentQuantity(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/pages/app/aigc_canvas/project/[id].vue');
        self::assertStringContainsString('estimatedCost: formatCostLabel(unitPrice * multiplier * quantity)', $source);
        self::assertStringContainsString('quantity: metadata.quantity || metadata.count', $source);
        self::assertStringContainsString('estimatedCost:', \Tests\Support\CompiledAssets::pcPage('app/aigc_canvas/project/[id]'));
    }

}
