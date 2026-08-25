<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class AigcMusicPricingDisplayContractTest extends TestCase
{
    public function testMusicPageRequestsTheAuthoritativeEstimateAfterLoadingConfig(): void
    {
        $source = $this->asset('public/_nuxt/_id_.57b448ac.js');

        self::assertStringContainsString('ht=async()=>{var C,O;Ve.value=await bg().catch(()=>({})),ne.value=Number', $source);
        self::assertStringContainsString('await He({sync:!0}),await B(),p()', $source);
        self::assertStringContainsString('R.user_charge_points', $source);
        self::assertStringContainsString('Q.tenant_unit_price', $source);
        self::assertStringContainsString('billing_formula', $source);
        self::assertStringContainsString('xrPrice.value', $source);
        self::assertStringContainsString('Math.ceil(Number(ne.value||za.duration||Q.unit_seconds||30)', $source);
        self::assertStringContainsString('duration:Number(ne.value||30)', $source);
        self::assertStringNotContainsString('duration:Number(ne.value||120)', $source);
        self::assertStringNotContainsString('R.unit_price)??10', $source);
    }

    public function testMusicConfigDoesNotKeepAnOldPriceInTheClientCache(): void
    {
        $api = $this->asset('public/_nuxt/asset-gallery-modal.e6854e80.js');
        $page = $this->asset('public/_nuxt/_id_.57b448ac.js');
        $entry = $this->asset('public/_nuxt/entry.c46691d5.js');
        $musicHtml = $this->asset('public/pc/ai/tools/aigc_music/index.html');

        self::assertStringContainsString('function Xa(){return $request.get({url:"/app.aigc_music.config/detail"})}', $api);
        self::assertStringNotContainsString('ba("app:aigc_music:config"', $api);
        self::assertStringContainsString('./asset-gallery-modal.e6854e80.js?v=20260824-musicpricefix', $page);
        self::assertStringContainsString('import("./_id_.57b448ac.js?v=20260824-musicpricefix")', $entry);
        self::assertStringContainsString('/_nuxt/entry.c46691d5.js', $musicHtml);
        self::assertStringNotContainsString('entry.c46691d5.js?v=', $musicHtml);
    }

    public function testMusicBillingUsesConfiguredTenantPriceAndIndependentTenantCost(): void
    {
        $channel = $this->asset('app/common/service/app/aigc_music/AigcMusicChannelService.php');
        $service = $this->asset('app/common/service/app/aigc_music/AigcMusicService.php');

        self::assertStringContainsString("'tenant_cost_points' => self::formatPoints((float)\$selection['spec']['platform_unit_cost'] * \$quantity)", $channel);
        self::assertStringContainsString("'user_charge_points' => self::formatPoints((float)\$selection['spec']['tenant_unit_price'] * \$quantity)", $channel);
        self::assertStringContainsString("'charge_source' => 'aigc_music_channel_spec.tenant_unit_price'", $channel);
        self::assertStringContainsString("'billing_formula' => sprintf(", $channel);
        self::assertStringContainsString('PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$task[\'tenant_cost_points\'], (float)$task[\'user_charge_points\']', $service);
    }

    private function asset(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
