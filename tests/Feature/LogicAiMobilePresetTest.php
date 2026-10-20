<?php
namespace Tests\Feature;

use app\common\service\decorate\MobilePresetService;
use PHPUnit\Framework\TestCase;

class LogicAiMobilePresetTest extends TestCase
{
    public function testFourMenuRootsResolveToDifferentEditablePages(): void
    {
        $preset = MobilePresetService::logicAi();
        self::assertArrayNotHasKey('tenant_id', $preset);
        self::assertArrayNotHasKey('template_id', $preset);
        $codes = array_column($preset['pages'], 'code');
        self::assertSame(['home', 'logic_ai_inspiration', 'logic_ai_works', 'user'], $codes);
        $tabs = $preset['settings']['mobile_tabbar'];
        self::assertSame('/resource/image/decoration/logic-ai/tab-icons.png', $tabs['style']['icon_atlas']);
        foreach ($tabs['list'] as $index => $tab) {
            self::assertSame($codes[$index], $tab['link']['query']['code']);
            self::assertSame($index, $tab['icon_atlas_index']);
            self::assertNotEmpty($preset['pages'][$index]['data']);
        }
    }

    public function testAssetsAreShippedAndReferenceMenusAreReplacedByActualApps(): void
    {
        $preset = MobilePresetService::logicAi();
        $json = json_encode($preset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['千售', '团队频道', '对标账号', '网感包装', '赠送100', 'DeepSeek'] as $unsupported) {
            self::assertStringNotContainsString($unsupported, $json);
        }
        preg_match_all('~"(/resource/[^"?]+)"~', $json, $matches);
        foreach (array_unique($matches[1]) as $asset) {
            self::assertFileExists(dirname(__DIR__, 2) . '/public' . $asset, $asset);
        }
        $home = $preset['pages'][0]['data'];
        self::assertSame('banner', $home[0]['name']);
        self::assertCount(3, $home[0]['content']['data']);
        self::assertLessThan(0, $home[1]['styles']['margin_top']);
        self::assertCount(10, $home[2]['content']['areas']);
    }
}
