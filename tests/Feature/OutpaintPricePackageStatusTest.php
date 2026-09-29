<?php

namespace Tests\Feature;

use app\common\service\app\aigc_outpaint\AigcOutpaintService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OutpaintPricePackageStatusTest extends TestCase
{
    private function call(string $method, ...$args): mixed
    {
        $reflection = new ReflectionMethod(AigcOutpaintService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, ...$args);
    }

    private function options(): array
    {
        return ['channels' => [['code' => 'fixture_image', 'name' => 'Fixture Image', 'qualities' => [
            ['value' => '1k', 'label' => '1K', 'ratios' => [
                ['value' => 'auto', 'ratio' => 'auto', 'label' => 'auto', 'platform_unit_cost' => 30],
                ['value' => '1:1', 'ratio' => '1:1', 'label' => '1:1', 'platform_unit_cost' => 30],
            ]],
        ]]]];
    }

    private function packages(): array
    {
        $items = [];
        foreach ([1, 1, 0, 1, 1] as $index => $status) {
            $items[] = [
                'code' => 'fixture_' . $index, 'name' => '规格' . $index,
                'channel' => 'fixture_image', 'quality' => '1k', 'quality_label' => '1K',
                'ratio' => 'auto', 'ratio_label' => 'auto',
                'unit_price' => 30 + $index, 'status' => $status, 'sort' => 100 - $index,
            ];
        }
        return $items;
    }

    public function testReadingSameRatioPackagesPreservesEnabledAndDisabledStatuses(): void
    {
        $packages = $this->packages();
        [$ensured, $changed] = $this->call('ensurePricePackages', $packages, $this->options());
        self::assertSame($packages, $ensured);
        self::assertFalse($changed, 'Reading supported specifications must not rewrite saved statuses.');

        $derived = $this->call('buildPricePackages', $this->options(), $ensured);
        self::assertSame(array_column($packages, 'code'), array_column($derived, 'code'));
        self::assertSame([1, 1, 0, 1, 1], array_column($derived, 'status'));
        self::assertSame([30.0, 31.0, 32.0, 33.0, 34.0], array_column($derived, 'unit_price'));
    }

    public function testRepeatedReadsPreserveAllEnabledSameRatioPackages(): void
    {
        $packages = $this->packages();
        $packages[2]['status'] = 1;
        for ($read = 0; $read < 3; $read++) {
            [$packages, $changed] = $this->call('ensurePricePackages', $packages, $this->options());
            self::assertFalse($changed);
            self::assertSame([1, 1, 1, 1, 1], array_column($packages, 'status'));
            self::assertCount(5, $this->call('buildPricePackages', $this->options(), $packages));
        }
    }

    public function testSpecCodeSelectsTheCorrectSameRatioPrice(): void
    {
        foreach (['spec_code', 'price_package_code'] as $codeKey) {
            $selected = $this->call('resolvePricePackage', $this->packages(),
                [$codeKey => 'fixture_4', 'ratio_code' => 'auto'], ['option_config' => $this->options()]);
            self::assertSame('fixture_4', $selected['code']);
            self::assertSame(34.0, $selected['unit_price']);
        }
        $fallback = $this->call('resolvePricePackage', $this->packages(),
            ['ratio_code' => 'auto'], ['option_config' => $this->options()]);
        self::assertSame('fixture_0', $fallback['code']);
        $disabled = $this->call('resolvePricePackage', $this->packages(),
            ['spec_code' => 'fixture_2'], ['option_config' => $this->options()]);
        self::assertNotSame('fixture_2', $disabled['code']);
    }

    public function testDefaultsStillUseDistinctSupportedRatios(): void
    {
        [$defaults, $changed] = $this->call('ensurePricePackages', [], $this->options());
        self::assertTrue($changed);
        self::assertSame(['auto', '1:1'], array_column($defaults, 'ratio'));
        self::assertSame([1, 1], array_column($defaults, 'status'));
    }
}
