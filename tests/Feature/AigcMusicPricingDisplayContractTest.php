<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class AigcMusicPricingDisplayContractTest extends TestCase
{
    public function testMusicPageRequestsTheAuthoritativeEstimateAfterLoadingConfig(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/apps/aigc_music/AigcMusicCreator.vue');
        $pricing = \Tests\Support\CompiledAssets::source('pc/apps/aigc_music/music-price.mjs');
        self::assertStringContainsString('await updateEstimate()', $source);
        self::assertStringContainsString('musicDisplayPoints(estimateInfo.value, appConfig.value, duration.value)', $source);
        self::assertStringContainsString('Number(duration.value || 30)', $source);
        self::assertStringContainsString('user_charge_points', $pricing);
        self::assertStringContainsString('tenant_unit_price', $pricing);
        self::assertStringContainsString('Math.ceil(seconds / unitSeconds)', $pricing);
        self::assertStringNotContainsString('appConfig.value?.unit_price ?? 10', $source);
        self::assertStringContainsString('user_charge_points', \Tests\Support\CompiledAssets::pcPage('ai/tools/[id]'));
    }

    public function testMusicConfigDoesNotKeepAnOldPriceInTheClientCache(): void
    {
        $api = \Tests\Support\CompiledAssets::source('pc/apps/aigc_music/api.ts');
        self::assertStringContainsString('return $request.get({ url: \'/app.aigc_music.config/detail\' })', $api);
        self::assertStringNotContainsString("getCachedAppData('app:aigc_music:config'", $api);
        self::assertStringContainsString('/app.aigc_music.config/detail', \Tests\Support\CompiledAssets::containing('pc', '/app.aigc_music.config/detail'));
        self::assertNotEmpty(\Tests\Support\CompiledAssets::pcPage('ai/tools/[id]'));
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
