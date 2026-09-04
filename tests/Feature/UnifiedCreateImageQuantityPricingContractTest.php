<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class UnifiedCreateImageQuantityPricingContractTest extends TestCase
{
    public function testUnifiedCreateImagePriceDisplayUsesCurrentQuantity(): void
    {
        $root = dirname(__DIR__, 2);
        $bundle = (string) file_get_contents($root . '/public/_nuxt/create.a5c396bf.js');
        $entry = (string) file_get_contents($root . '/public/_nuxt/entry.c46691d5.js');

        self::assertStringContainsString(
            'N.value==="image"?`${l("预计消耗")} ${ne(jt(Number(Yo.value)*Xt.value))}`',
            $bundle
        );
        self::assertStringContainsString(
            'Xt=f(()=>{const e=Number.parseInt(s.value.count,10);return Number.isFinite(e)&&e>0?Math.min(e,4):1})',
            $bundle
        );
        self::assertStringContainsString('V=Xt.value', $bundle);
        self::assertStringContainsString('quantity:1', $bundle);
        self::assertStringContainsString(
            'import("./create.a5c396bf.js?v=20260824-image-quantity-price")',
            $entry
        );
    }
}
