<?php
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_image\ImagePromptEnhanceService;

final class ImagePromptEnhanceTest extends TestCase
{
    public function testKeepsTypedReferenceIdentity(): void
    {
        $text = '中景横移跟拍 @图片1，保持 @视频2 的运动方向，参考 @音频1。';
        self::assertSame($text, ImagePromptEnhanceService::validateResult('@图片1 @视频2 @音频1', $text));
    }
    /** @dataProvider invalidResults */
    public function testRejectsLostOrInventedReferences(string $result): void
    {
        $this->expectException(Exception::class);
        ImagePromptEnhanceService::validateResult('拍摄 @图片1', $result);
    }
    public static function invalidResults(): array
    {
        return [[''], ['中景拍摄人物'], ['拍摄 @图片2'], ['拍摄 @图片1 与 @视频1']];
    }
    public function testPhotographyInstructionsRespectStillImageAndReferences(): void
    {
        $text = ImagePromptEnhanceService::instructions();
        foreach (['专业摄影师', '单张静态图像', '构图', '主光方向', '不能改名', '相同的语言'] as $term) {
            self::assertStringContainsString($term, $text);
        }
    }
}
