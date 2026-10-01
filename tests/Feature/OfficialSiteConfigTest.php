<?php
namespace Tests\Feature;
use app\common\service\OfficialSiteService;
use app\common\service\OfficialSiteModelCatalog;
use PHPUnit\Framework\TestCase;

class OfficialSiteConfigTest extends TestCase
{
    private function call(string $method, ...$args)
    {
        $reflection = new \ReflectionMethod(OfficialSiteService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, ...$args);
    }
    private function normalize(array $input): array { return $this->call('normalize', $input); }

    public function testNewArchitectureUsesActualToolsAndSixRequestedEntries(): void
    {
        $config = $this->normalize([]);
        self::assertSame(5, $config['template_version']);
        self::assertSame(['应用工具','模型','开放平台','价格','企业服务','帮助'], array_column($config['navigation'], 'label'));
        self::assertSame(['工作室','图片','视频'], array_column($config['navigation'][0]['groups'], 'title'));
        self::assertSame(['dropdown','dropdown','link','link','link','link'], array_column($config['navigation'], 'mode'));
        $modules = array_column($config['modules'], null, 'key');
        self::assertSame(['AI 短剧','AI 视频','AI 绘图','数字人','无限画布','AI 音乐'], array_column($modules['products']['cards'], 'title'));
        self::assertSame([], $modules['models']['cards']);
        self::assertSame('light', $config['basic']['theme']);
    }
    public function testPreviousDefaultUpgradesButCustomCopyAndMediaSurvive(): void
    {
        $old = $this->call('v3Defaults');
        $old['template_version'] = 3;
        $old['basic']['name'] = '自有品牌';
        $old['modules'][0]['media'] = 'uploads/custom.mp4';
        $old['modules'][0]['media_type'] = 'video';
        $old['modules'][0]['title'] = '自己的首屏文案';
        $result = $this->normalize($old);
        $modules = array_column($result['modules'], null, 'key');
        self::assertSame('自己的首屏文案', $modules['hero']['title']);
        self::assertSame('uploads/custom.mp4', $modules['hero']['media']);
        self::assertCount(6, $modules['products']['cards']);
        self::assertSame('自有品牌', $result['basic']['name']);
        self::assertSame($result, $this->normalize($result));
    }
    public function testMediaAndHiddenModelOverridesRoundTrip(): void
    {
        $input = ['template_version'=>4, 'basic'=>['placeholder'=>'uploads/placeholder.webp','motion_enabled'=>0,'start_link'=>'https://example.com/start'], 'modules'=>[
            ['key'=>'hero','media'=>'uploads/demo.mp4','media_type'=>'video','poster'=>'uploads/demo.webp','cards'=>[['title'=>'自定义','media'=>'uploads/card.webp']]],
            ['key'=>'models','cards'=>[['model_id'=>'market_image_model:42','enabled'=>0,'icon_url'=>'uploads/icon.svg','media'=>'uploads/model.mp4','media_type'=>'video']]],
        ]];
        $once = $this->normalize($input);
        self::assertSame($once, $this->normalize($once));
        self::assertSame('uploads/placeholder.webp', $once['basic']['placeholder']);
        $modules = array_column($once['modules'], null, 'key');
        self::assertSame('uploads/demo.webp', $modules['hero']['poster']);
        self::assertSame(0, $modules['models']['cards'][0]['enabled']);
        self::assertSame('market_image_model:42', $modules['models']['cards'][0]['model_id']);
    }
    public function testLinksRejectScriptProtocolRelativeCredentialsAndBackslash(): void
    {
        foreach (['javascript:alert(1)','//evil.test','http://evil.test','https://user:pass@example.com','https://example.com\\@evil.test',"https://example.com/\nfoo"] as $path) self::assertSame('', $this->call('safeLink', $path), $path);
        foreach (['/ai/create?type=image&channel=market_image_model%3A42','/?tenant_id=1#products','https://example.com/docs?q=1#intro'] as $path) self::assertSame($path, $this->call('safeLink', $path));
    }
    public function testPublicModelProjectionOmitsUnavailableAndSensitiveRuntimeFields(): void
    {
        $options = [
            ['value'=>'market_image_model:42','name'=>'真实模型','available'=>true,'enabled'=>true,'description'=>'<b>简介</b>','platform_unit_cost'=>'9.00','tenant_unit_price'=>'12','skus'=>[['secret'=>'never']],'api_key'=>'private'],
            ['value'=>'market_image_model:43','name'=>'不可用','available'=>false,'enabled'=>true],
        ];
        $models = OfficialSiteModelCatalog::present($options, 'image');
        self::assertCount(1, $models);
        self::assertSame(['id','type','title','description','icon_url','link'], array_keys($models[0]));
        self::assertSame('简介', $models[0]['description']);
        self::assertSame('/ai/create?type=image&channel=market_image_model%3A42', $models[0]['link']);
    }
    public function testUnsafeUnknownFieldsAreRemovedAndCardLimitsEnforced(): void
    {
        $result = $this->normalize(['template_version'=>4,'basic'=>['secret'=>'no','accent_color'=>'red;display:none'],'modules'=>[['key'=>'hero','button_link'=>'javascript:alert(1)','autoplay_seconds'=>900,'cards'=>array_fill(0,40,['title'=>'x','private_note'=>'secret','status'=>'planned','link'=>'/ai'])]]]);
        self::assertArrayNotHasKey('secret', $result['basic']);
        self::assertSame('#2563eb', $result['basic']['accent_color']);
        $hero = array_column($result['modules'],null,'key')['hero'];
        self::assertSame('', $hero['button_link']);
        self::assertCount(24, $hero['cards']);
        self::assertSame('', $hero['cards'][0]['link']);
        self::assertArrayNotHasKey('private_note', $hero['cards'][0]);
        self::assertSame(30, $hero['autoplay_seconds']);
    }
    public function testCustomNavigationSupportsSecureExternalDestinationsAndIcons(): void
    {
        $result = $this->normalize(['template_version'=>4,'navigation'=>[
            ['key'=>'open','label'=>'开发者','link'=>'https://example.com/developer'],
            ['key'=>'tools','groups'=>[['title'=>'自定义','items'=>[['label'=>'测试','status'=>'live','link'=>'/ai','icon_url'=>'uploads/custom.svg','description'=>'自己的工具']]]]],
        ]]);
        $nav = array_column($result['navigation'],null,'key');
        self::assertSame('https://example.com/developer', $nav['open']['link']);
        self::assertSame('uploads/custom.svg', $nav['tools']['groups'][0]['items'][0]['icon_url']);
        self::assertSame($result, $this->normalize($result));
    }
    public function testOldCustomProductMediaMigratesIntoSixCapabilitiesAndNewOrder(): void
    {
        $old = $this->call('v3Defaults');
        $old['template_version'] = 3;
        foreach ($old['modules'] as &$module) {
            if ($module['key'] === 'products') {
                $module['cards'][0]['title'] = '自己的数字人介绍';
                $module['cards'][0]['media'] = 'uploads/avatar.mp4';
                $module['cards'][0]['media_type'] = 'video';
            }
            if ($module['key'] === 'scenes') $module['sort'] = 80;
        }
        unset($module);
        $result = $this->normalize($old);
        $modules = array_column($result['modules'], null, 'key');
        self::assertSame(960, $modules['scenes']['sort']);
        self::assertCount(6, $modules['products']['cards']);
        $cards = array_column($modules['products']['cards'], null, 'link');
        self::assertSame('uploads/avatar.mp4', $cards['/ai/avatar']['media']);
        self::assertSame('自己的数字人介绍', $cards['/ai/avatar']['title']);
        self::assertArrayHasKey('/ai/create?type=video', $cards);
        self::assertSame($result, $this->normalize($result));
    }

    public function testClearedCardsAndDisabledModulesStayCleared(): void
    {
        $config = $this->normalize(['template_version'=>4,'modules'=>[['key'=>'hero','enabled'=>0,'cards'=>[]],['key'=>'faq','cards'=>[]]]]);
        $modules = array_column($config['modules'],null,'key');
        self::assertSame(0,$modules['hero']['enabled']);
        self::assertSame([],$modules['hero']['cards']);
        self::assertSame([],$modules['faq']['cards']);
        self::assertSame($config,$this->normalize($config));
    }
    public function testHeroSlidesPreserveIndependentPostersAndMobileMedia(): void
    {
        $config = $this->normalize(['template_version'=>4,'modules'=>[['key'=>'hero',
            'media'=>'uploads/legacy.mp4', 'poster'=>'uploads/legacy.webp',
            'slides'=>[
                ['media'=>'uploads/a.mp4','poster'=>'uploads/a.webp','mobile_media'=>'uploads/portrait.mp4','mobile_poster'=>'uploads/portrait.webp','sort'=>20],
                ['media'=>'uploads/b.mp4','enabled'=>0,'sort'=>30,'private_note'=>'secret'],
            ],
        ]]]);
        $hero = array_column($config['modules'],null,'key')['hero'];
        self::assertCount(2,$hero['slides']);
        self::assertSame(0,$hero['slides'][0]['enabled']);
        self::assertSame('uploads/a.webp',$hero['slides'][1]['poster']);
        self::assertSame('uploads/portrait.mp4',$hero['slides'][1]['mobile_media']);
        self::assertSame('uploads/portrait.webp',$hero['slides'][1]['mobile_poster']);
        self::assertSame('uploads/legacy.mp4',$hero['media']);
        self::assertArrayNotHasKey('private_note',$hero['slides'][0]);
        self::assertSame($config,$this->normalize($config));
        $empty = $this->normalize(['template_version'=>4,'modules'=>[['key'=>'hero','slides'=>[]]]]);
        self::assertSame([],array_column($empty['modules'],null,'key')['hero']['slides']);
    }

    public function testLiveV4RemnantsUpgradeToTheSamePresetAsANewTenant(): void
    {
        $legacy = json_decode(file_get_contents(__DIR__ . '/../Fixtures/official-site-v4-remnants.json'), true);
        $config = $this->normalize($legacy);
        $modules = array_column($config['modules'], null, 'key');
        $fresh = array_column($this->normalize([])['modules'], null, 'key');
        foreach (['hero', 'products', 'pricing', 'join'] as $key) {
            foreach (['title', 'description', 'button_text', 'button_link', 'cards'] as $field) {
                self::assertSame($fresh[$key][$field], $modules[$key][$field], "$key.$field");
            }
        }
        self::assertSame($config, $this->normalize($config));
    }

    public function testRemnantRepairPreservesCustomMediaCopyBrandAndRetiredCardOverrides(): void
    {
        $config = json_decode(file_get_contents(__DIR__ . '/../Fixtures/official-site-v4-remnants.json'), true);
        $config['basic'] = ['name'=>'自己的品牌', 'logo'=>'uploads/logo.png', 'accent_color'=>'#aabbcc'];
        foreach ($config['modules'] as &$module) {
            if ($module['key'] === 'hero') {
                $module['title'] = '自己的标题';
                $module['media'] = 'uploads/hero.mp4';
                $module['enabled'] = 0;
                $module['sort'] = 500;
            }
            if ($module['key'] === 'products') {
                $module['cards'][1]['media'] = 'uploads/custom-multimodal.png';
                $module['cards'][7]['description'] = '自己的工作流介绍';
            }
        }
        unset($module);
        $result = $this->normalize($config);
        $modules = array_column($result['modules'], null, 'key');
        self::assertSame('自己的标题', $modules['hero']['title']);
        self::assertSame('uploads/hero.mp4', $modules['hero']['media']);
        self::assertSame(0, $modules['hero']['enabled']);
        self::assertSame(500, $modules['hero']['sort']);
        self::assertCount(8, $modules['products']['cards']);
        self::assertSame('uploads/custom-multimodal.png', $modules['products']['cards'][1]['media']);
        self::assertSame('自己的工作流介绍', $modules['products']['cards'][7]['description']);
        self::assertSame('自己的品牌', $result['basic']['name']);
        self::assertSame('uploads/logo.png', $result['basic']['logo']);
        self::assertSame('#aabbcc', $result['basic']['accent_color']);
        self::assertSame($result, $this->normalize($result));
    }

    public function testAllHistoricalPresetCopyUpgradesAndV5OverridesAreNotReinterpreted(): void
    {
        $defaults = array_column($this->normalize([])['modules'], null, 'key');
        foreach (['legacyDefaults'=>1, 'draftDefaults'=>2, 'v3Defaults'=>3] as $method=>$version) {
            $config = $this->call($method);
            $config['template_version'] = $version;
            $result = $this->normalize($config);
            $modules = array_column($result['modules'], null, 'key');
            foreach (['hero','products','pricing','join'] as $key) {
                self::assertSame($defaults[$key]['title'], $modules[$key]['title'], "$method.$key");
            }
            self::assertCount(6, $modules['products']['cards']);
            self::assertSame($result, $this->normalize($result));
        }
        $custom = $this->normalize(['template_version'=>5, 'modules'=>[['key'=>'hero','title'=>'让 AI 成为增长团队的一部分']]]);
        self::assertSame('让 AI 成为增长团队的一部分', array_column($custom['modules'],null,'key')['hero']['title']);
    }

}
