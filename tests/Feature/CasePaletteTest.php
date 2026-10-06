<?php

use app\common\service\case_gallery\CasePaletteService;
use PHPUnit\Framework\TestCase;

final class CasePaletteTest extends TestCase
{
    public function testSamplesOnlyBottomStripAndDoesNotBakeInDarkening(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD required');
        $image = imagecreatetruecolor(100, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 255));
        imagefilledrectangle($image, 0, 95, 99, 99, imagecolorallocate($image, 200, 100, 50));
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        self::assertSame('rgb(200, 100, 50)', CasePaletteService::sample($bytes));
        self::assertSame('', CasePaletteService::sample('<svg></svg>'));
        self::assertSame('', CasePaletteService::sample('not an image'));
    }

    public function testMissingTenantOrCaseNeverFetchesAnImage(): void
    {
        self::assertSame('', CasePaletteService::color(0, 12));
        self::assertSame('', CasePaletteService::color(1, 0));
    }
}
