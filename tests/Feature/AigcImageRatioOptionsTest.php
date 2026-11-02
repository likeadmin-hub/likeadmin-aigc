<?php

namespace Tests\Feature;

use app\common\service\app\aigc_image\AigcImageChannelService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AigcImageRatioOptionsTest extends TestCase
{
    private function normalize(array $channels): array
    {
        return (new ReflectionMethod(AigcImageChannelService::class, 'normalizeUserChannels'))->invoke(null, $channels);
    }

    public function testDuplicateSkuRatiosAreOneChoiceWithoutChangingFirstSpecOrRuntimeSpecs(): void
    {
        $first = ['value' => '1:1', 'market_sku_id' => 11, 'tenant_unit_price' => 10];
        $second = ['value' => '1:1', 'market_sku_id' => 12, 'tenant_unit_price' => 20];
        $wide = ['value' => '16:9', 'market_sku_id' => 11];
        $input = [['code' => 'market_image_model:265', 'qualities' => [
            ['value' => '2k', 'ratios' => [$first, $wide, $second, $wide]],
            ['value' => '4k', 'ratios' => [$second]],
        ], 'specs' => [$first, $second, $wide]]];

        $output = $this->normalize($input);

        self::assertSame([$first, $wide], $output[0]['qualities'][0]['ratios']);
        self::assertSame([$second], $output[0]['qualities'][1]['ratios']);
        self::assertSame($input[0]['specs'], $output[0]['specs']);
        self::assertSame(4, count($input[0]['qualities'][0]['ratios']));
        self::assertSame($output, $this->normalize($output));
    }

    public function testProtocolChoicesRemainDistinctAndIndependentAcrossChannels(): void
    {
        $ratios = array_map(static fn($value) => ['value' => $value, 'label' => 'same label'], ['auto', 'original', 'default', '', '1:1', 'auto']);
        $channels = [];
        foreach (['legacy_image', 'market_image_model:9'] as $code) {
            $channels[] = ['code' => $code, 'qualities' => [['value' => '', 'ratios' => $ratios]], 'specs' => []];
        }
        foreach ($this->normalize($channels) as $channel) {
            self::assertSame(['auto', 'original', 'default', '', '1:1'], array_column($channel['qualities'][0]['ratios'], 'value'));
        }
    }

    public function testLegacyChannelHidingAndEmptyConfigurationsStillWork(): void
    {
        self::assertSame([], $this->normalize([]));
        $channels = $this->normalize([
            ['code' => 'gpt_image_2', 'qualities' => [], 'specs' => []],
            ['code' => 'gpt_image_2_pro', 'qualities' => [], 'specs' => []],
        ]);
        self::assertSame(['gpt_image_2'], array_column($channels, 'code'));
    }
}
