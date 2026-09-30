<?php
namespace Tests\Feature;

use app\common\service\OfficialSiteService;
use PHPUnit\Framework\TestCase;

class OfficialSiteConfigTest extends TestCase
{
    private function normalize(array $input): array
    {
        $method = new \ReflectionMethod(OfficialSiteService::class, 'normalize');
        $method->setAccessible(true);
        return $method->invoke(null, $input);
    }

    public function testFreshTenantGetsActualCreationCapabilities(): void
    {
        $config = $this->normalize([]);
        self::assertSame(3, $config['template_version']);
        self::assertSame('#2563eb', $config['basic']['accent_color']);
        $products = array_column($config['modules'], null, 'key')['products'];
        self::assertSame(['human', 'drama', 'canvas'], array_column($products['cards'], 'kind'));
        self::assertSame(['/ai/avatar', '/ai/short-drama', '/app/aigc_canvas'], array_column($products['cards'], 'link'));
    }

    public function testUntouchedLegacyTemplateUpgradesWithoutChangingVisibilityOrBrand(): void
    {
        $method = new \ReflectionMethod(OfficialSiteService::class, 'legacyDefaults');
        $method->setAccessible(true);
        $legacy = $method->invoke(null);
        $legacy['basic']['name'] = 'My Tenant';
        $legacy['modules'][0]['enabled'] = 0;
        $legacy['modules'][0]['sort'] = 8;
        $result = $this->normalize($legacy);
        $hero = array_column($result['modules'], null, 'key')['hero'];
        self::assertSame("让想象力起飞，\n开启 AI 创作新方式", $hero['title']);
        self::assertSame(0, $hero['enabled']);
        self::assertSame(8, $hero['sort']);
        self::assertSame('My Tenant', $result['basic']['name']);
    }

    public function testTenantAuthoredLegacyContentSurvivesUpgrade(): void
    {
        $result = $this->normalize(['modules' => [['key' => 'products', 'title' => '自有产品', 'cards' => [['title' => '我的功能', 'description' => '自定义介绍', 'media' => 'uploads/custom.png', 'link' => '/ai']]]]]);
        $products = array_column($result['modules'], null, 'key')['products'];
        self::assertSame('自有产品', $products['title']);
        self::assertSame('uploads/custom.png', $products['cards'][0]['media']);
        self::assertSame('custom', $products['cards'][0]['kind']);
    }

    public function testUnsafeLinksAndUnknownPublicFieldsAreRemoved(): void
    {
        $result = $this->normalize(['basic' => ['accent_color' => 'red;display:none', 'secret' => 'no'], 'modules' => [['key' => 'hero', 'button_link' => '//evil.test', 'internal_note' => 'private'], ['key' => 'products', 'cards' => [['link' => 'javascript:alert(1)', 'internal_note' => 'private', 'kind' => 'arbitrary', 'status' => 'live']]]]]);
        self::assertSame('#2563eb', $result['basic']['accent_color']);
        self::assertArrayNotHasKey('secret', $result['basic']);
        $modules = array_column($result['modules'], null, 'key');
        self::assertSame('', $modules['hero']['button_link']);
        self::assertArrayNotHasKey('internal_note', $modules['hero']);
        self::assertSame('', $modules['products']['cards'][0]['link']);
        self::assertSame('custom', $modules['products']['cards'][0]['kind']);
        self::assertArrayNotHasKey('internal_note', $modules['products']['cards'][0]);
    }

    public function testMediaPosterAndAnchorLinksSurviveRepeatedNormalization(): void
    {
        $input = ['template_version' => 2, 'modules' => [['key' => 'hero', 'media' => 'uploads/demo.mp4', 'media_type' => 'video', 'poster' => 'uploads/poster.webp', 'button_link' => '/?tenant_id=2#products'], ['key' => 'products', 'cards' => [['title' => 'Video', 'kind' => 'drama', 'media_type' => 'video', 'media' => 'uploads/story.mp4', 'poster' => 'uploads/story.webp', 'status' => 'live', 'link' => '/ai/short-drama']]]]];
        $once = $this->normalize($input);
        self::assertSame($once, $this->normalize($once));
        $modules = array_column($once['modules'], null, 'key');
        self::assertSame('/?tenant_id=2#products', $modules['hero']['button_link']);
        self::assertSame('uploads/story.webp', $modules['products']['cards'][0]['poster']);
    }

    public function testEmptyCardListStaysEmptyAndPlannedCardsCannotNavigate(): void
    {
        $result = $this->normalize(['template_version' => 2, 'modules' => [['key' => 'faq', 'cards' => []], ['key' => 'products', 'cards' => [['status' => 'planned', 'link' => '/ai']]]]]);
        $modules = array_column($result['modules'], null, 'key');
        self::assertSame([], $modules['faq']['cards']);
        self::assertSame('', $modules['products']['cards'][0]['link']);
    }
    public function testDraftUpgradeAddsToolsAndPreservesTenantEdits(): void
    {
        $method = new \ReflectionMethod(OfficialSiteService::class, 'draftDefaults');
        $method->setAccessible(true);
        $draft = $method->invoke(null);
        $draft['template_version'] = 2;
        $draft['basic']['name'] = '自己的品牌';
        $draft['modules'][1]['title'] = '租户自己改过的能力介绍';
        $result = $this->normalize($draft);
        $modules = array_column($result['modules'], null, 'key');
        self::assertSame('自己的品牌', $result['basic']['name']);
        self::assertSame('租户自己改过的能力介绍', $modules['products']['title']);
        self::assertSame(0, $modules['faq']['enabled']);
        self::assertSame(8, count($modules['tools']['cards']));
        self::assertSame(2, count($modules['audiences']['cards']));
        self::assertSame($result, $this->normalize($result));
    }

    public function testAnimationPreferencesRemainNumericOnRoundTrip(): void
    {
        $result = $this->normalize(['basic' => ['particles_enabled' => 0, 'motion_enabled' => 1]]);
        self::assertSame(0, $result['basic']['particles_enabled']);
        self::assertSame(1, $result['basic']['motion_enabled']);
        self::assertSame($result, $this->normalize($result));
    }

    public function testAllOfficialMediaOverridesSurviveNormalization(): void
    {
        $result = $this->normalize(['template_version' => 3, 'modules' => [
            ['key' => 'hero', 'background_media' => 'uploads/hero.mp4', 'background_media_type' => 'video', 'background_poster' => 'uploads/hero.webp', 'media' => 'uploads/intro.mp4', 'media_type' => 'video', 'poster' => 'uploads/intro.webp'],
            ['key' => 'products', 'cards' => [['kind' => 'canvas', 'canvas_image' => 'uploads/node.webp']]],
            ['key' => 'audiences', 'cards' => [['media' => 'uploads/invite.mp4', 'media_type' => 'video', 'poster' => 'uploads/invite.webp']]],
            ['key' => 'cta', 'media' => 'uploads/end.webp'],
        ]]);
        $modules = array_column($result['modules'], null, 'key');
        self::assertSame('uploads/hero.mp4', $modules['hero']['background_media']);
        self::assertSame('video', $modules['hero']['background_media_type']);
        self::assertSame('uploads/hero.webp', $modules['hero']['background_poster']);
        self::assertSame('uploads/intro.mp4', $modules['hero']['media']);
        self::assertSame('uploads/node.webp', $modules['products']['cards'][0]['canvas_image']);
        self::assertSame('uploads/invite.webp', $modules['audiences']['cards'][0]['poster']);
        self::assertSame('uploads/end.webp', $modules['cta']['media']);
        self::assertSame($result, $this->normalize($result));
    }

}
