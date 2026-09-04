<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CanvasImageQuantityPricingContractTest extends TestCase
{
    public function testCanvasImagePriceDisplayUsesCurrentQuantity(): void
    {
        $bundle = file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/_id_.2fb60286.js');

        self::assertIsString($bundle);
        self::assertStringContainsString('if(v.node.type==="image"&&!q.value)', $bundle);
        self::assertStringContainsString('m=Math.max(1,Number(o.quantity||o.count||1)),A=M*m', $bundle);
        self::assertStringContainsString('estimatedCost:xt(Fl*Il),costLabel:xt(Fl*Il)', $bundle);
        self::assertStringContainsString('quantity:t.quantity||t.count', $bundle);
    }
}
