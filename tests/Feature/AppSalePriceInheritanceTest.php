<?php
namespace Tests\Feature;

use app\common\service\app\AppSalePriceService;
use app\common\service\power\MarketImageModelRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AppSalePriceInheritanceTest extends TestCase
{
    private static function call(string $class, string $method, ...$args): mixed
    {
        $method = new ReflectionMethod($class, $method);
        $method->setAccessible(true);
        return $method->invoke(null, ...$args);
    }

    private static function service(string $app): string
    {
        return 'app\\common\\service\\app\\aigc_' . $app . '\\Aigc'
            . str_replace(' ', '', ucwords(str_replace('_', ' ', $app))) . 'Service';
    }

    public function testEmptyZeroAndInvalidPricesAreDifferent(): void
    {
        foreach ([null, '', '  '] as $value) self::assertNull(AppSalePriceService::normalize($value));
        self::assertSame(0.0, AppSalePriceService::normalize('0'));
        self::assertNull(AppSalePriceService::fromRow(['unit_price' => null, 'tenant_unit_price' => 8]));
        self::assertSame([], AppSalePriceService::imageOverride(null));
        self::assertSame(['user_unit_price' => 0.0], AppSalePriceService::imageOverride(0));
        $this->expectException(\InvalidArgumentException::class);
        AppSalePriceService::normalize(-1);
    }

    public static function appCases(): array
    {
        $cases = [];
        foreach (explode(' ', 'product_image local_redraw one_click_cleanup product_suite product_multi_angle fashion_lookbook fitting photo_restore model_wear style_transfer background_removal image_translate outpaint') as $app) {
            foreach ([null, 0, 8.5] as $price) $cases[$app . ':' . var_export($price, true)] = [$app, $price];
        }
        return $cases;
    }

    /** @dataProvider appCases */
    public function testEstimateUsesAppOrMarketPriceWithoutChangingCost(string $app, ?float $price): void
    {
        $prepared = [
            'unit_price' => $price, 'width' => 1024, 'height' => 1024, 'size_key' => '1:1',
            'source_images' => ['a', 'b'], 'modules' => ['a', 'b'], 'module_codes' => ['a', 'b'],
            'views' => ['a', 'b'], 'view_codes' => ['a', 'b'], 'clothes_images' => ['a', 'b'],
            'options' => [], 'option_codes' => [], 'price_package' => ['code' => 'test', 'name' => 'test'],
            'image_payload' => ['quantity' => 2], 'mode' => 'single',
        ];
        $quote = ['platform_unit_cost' => 2.0, 'tenant_unit_price' => 5.0, 'settlement_mode' => 'reserved'];
        $method = $app === 'fitting' ? 'buildFittingEstimate' : 'buildEstimate';
        $estimate = self::call(self::service($app), $method, $prepared, $quote);
        $quantity = in_array($app, ['one_click_cleanup', 'product_suite', 'product_multi_angle', 'fashion_lookbook', 'fitting'], true) ? 2 : 1;
        self::assertSame($quantity, $estimate['quantity']);
        self::assertSame(($price ?? 5.0) * $quantity, (float)$estimate['user_charge_points']);
        self::assertSame(2.0 * $quantity, (float)$estimate['tenant_cost_points']);
        // A later market change affects unset prices only; old estimate remains unchanged.
        $quote['tenant_unit_price'] = 7.0;
        $later = self::call(self::service($app), $method, $prepared, $quote);
        self::assertSame(($price ?? 7.0) * $quantity, (float)$later['user_charge_points']);
        self::assertSame(($price ?? 5.0) * $quantity, (float)$estimate['user_charge_points']);
    }

    public function testOperationAndModeDefaultsPreserveFreePrices(): void
    {
        foreach (['hairstyle' => ['normalizeOperationPrices', 'hair_style'], 'fitting' => ['normalizeModePrices', 'single']] as $app => [$method, $key]) {
            self::assertNull(self::call(self::service($app), $method, [])[$key]);
            self::assertSame(0.0, self::call(self::service($app), $method, [$key => 0])[$key]);
            self::assertSame(8.0, self::call(self::service($app), $method, [$key => 8])[$key]);
        }
    }

    public function testPackageNormalizationPreservesInheritanceAndFree(): void
    {
        foreach (explode(' ', 'photo_restore model_wear style_transfer background_removal image_translate outpaint') as $app) {
            foreach ([null, 0, 8.5] as $price) {
                $rows = self::call(self::service($app), $app === 'style_transfer' ? 'normalizePricePackages' : 'normalizePriceConfig', [[
                    'code' => 'test', 'channel' => 'market_image_model:1', 'quality' => '1k', 'ratio' => '1:1', 'unit_price' => $price,
                ]]);
                self::assertSame($price === null ? null : (float)$price, $rows[0]['unit_price'], $app);
            }
        }
    }

    public function testMarketQuoteAndSnapshotKeepZeroWithoutOverridingCost(): void
    {
        $quote = ['quantity' => 2, 'tenant_cost_points' => 4.0, 'user_unit_points' => 5.0, 'user_charge_points' => 10.0];
        self::assertSame($quote, self::call(MarketImageModelRuntimeService::class, 'applyBillingOverride', $quote, AppSalePriceService::imageOverride(null)));
        $free = self::call(MarketImageModelRuntimeService::class, 'applyBillingOverride', $quote, AppSalePriceService::imageOverride(0));
        self::assertSame(0.0, (float)$free['user_charge_points']);
        self::assertSame(4.0, $free['tenant_cost_points']);
    }
}
