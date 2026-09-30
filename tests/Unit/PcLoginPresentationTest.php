<?php
use app\common\service\PcLoginPresentationService;
use PHPUnit\Framework\TestCase;

final class PcLoginPresentationTest extends TestCase
{
    public function testSlidesPreserveOrderAndResolveStoredAssets(): void
    {
        $rows = PcLoginPresentationService::normalizeSlides([
            ['image' => '/first.png', 'title' => '第一张', 'background' => '#DFECFF', 'background_dark' => '#142030'],
            ['image' => '/second.png', 'title' => '第二张'],
        ], static fn($path) => 'https://assets.test' . $path);
        self::assertSame(['第一张', '第二张'], array_column($rows, 'title'));
        self::assertSame('https://assets.test/first.png', $rows[0]['image']);
        self::assertSame('#DFECFF', $rows[0]['background']);
        self::assertSame('#142030', $rows[0]['background_dark']);
        self::assertSame('#efebf7', $rows[1]['background']);
    }

    public function testMalformedAndLegacyEmptyValuesDoNotBreakPublicConfig(): void
    {
        foreach ([null, '', '[]', 1] as $value) self::assertSame([], PcLoginPresentationService::normalizeSlides($value));
        self::assertSame([], PcLoginPresentationService::normalizeSlides([null, [], ['image' => 'javascript:alert(1)'], ['image' => 'data:image/png;base64,abc']]));
        $rows = PcLoginPresentationService::normalizeSlides([['image' => '/image.png', 'title' => [], 'background' => 'url(invalid)', 'background_dark' => null]]);
        self::assertSame('', $rows[0]['title']);
        self::assertSame('#efebf7', $rows[0]['background']);
        self::assertSame('#24202e', $rows[0]['background_dark']);
        self::assertCount(8, PcLoginPresentationService::normalizeSlides(array_fill(0, 12, ['image' => '/image.png'])));
    }
}
