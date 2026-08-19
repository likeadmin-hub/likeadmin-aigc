<?php

namespace Tests\Feature;

use app\common\service\app\aigc_local_redraw\AigcLocalRedrawService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class LocalRedrawMarketContractTest extends TestCase
{
    public function testLegacyMarketModelIdentifierIsCanonicalized(): void
    {
        $aligned = $this->invoke('alignConfigSelection', [
            'channel' => 'market_image_model97',
            'quality' => '1k',
            'ratio' => 'auto',
        ], $this->options());

        self::assertSame('market_image_model:97', $aligned['channel']);
        self::assertSame('1k', $aligned['quality']);
        self::assertSame('auto', $aligned['ratio']);
    }

    public function testUnsupportedSpecFallsBackWithinSelectedModel(): void
    {
        $aligned = $this->invoke('alignConfigSelection', [
            'channel' => 'market_image_model:175',
            'quality' => '4k',
            'ratio' => 'auto',
        ], $this->options());

        self::assertSame('market_image_model:175', $aligned['channel']);
        self::assertSame('1k', $aligned['quality']);
        self::assertSame('1:1', $aligned['ratio']);
    }

    public function testMarketModelIdentifierKeepsItsColon(): void
    {
        self::assertSame(
            'market_image_model:97',
            $this->invoke('normalizeCode', 'market_image_model:97')
        );
    }

    private function options(): array
    {
        return [
            'defaults' => [
                'channel' => 'market_image_model:175',
                'quality' => '1k',
                'ratio' => '1:1',
            ],
            'channels' => [
                [
                    'code' => 'market_image_model:175',
                    'qualities' => [[
                        'value' => '1k',
                        'ratios' => [['value' => '1:1', 'ratio' => '1:1']],
                    ]],
                ],
                [
                    'code' => 'market_image_model:97',
                    'qualities' => [[
                        'value' => '1k',
                        'ratios' => [
                            ['value' => 'auto', 'ratio' => 'auto'],
                            ['value' => '1:1', 'ratio' => '1:1'],
                        ],
                    ]],
                ],
            ],
        ];
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcLocalRedrawService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
