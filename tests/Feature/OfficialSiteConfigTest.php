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

    public function testTranslationsAreTenantCopyOnlyAndSurviveNormalization(): void
    {
        $base = $this->normalize([]);
        $base['basic']['title'] = '自定义官网';
        $base['translations'] = [
            'en' => ['自定义官网'=>'My Creative Site', '开始创作'=>'Start Now', '/ai'=>'/evil', 'theme'=>'dark'],
            'zh-TW' => ['自定义官网'=>'自訂官網'],
            'fr' => ['自定义官网'=>'Mon site'],
        ];
        $result = $this->normalize($base);
        self::assertSame(['自定义官网'=>'My Creative Site', '开始创作'=>'Start Now'], $result['translations']['en']);
        self::assertSame(['自定义官网'=>'自訂官網'], $result['translations']['zh-TW']);
        self::assertArrayNotHasKey('fr', $result['translations']);
        self::assertSame($base['basic'], $result['basic']);
        self::assertSame($base['navigation'], $result['navigation']);
        self::assertSame($base['modules'], $result['modules']);
        self::assertSame($result, $this->normalize($result));
        $result['basic']['title'] = '更新后的官网';
        self::assertArrayNotHasKey('自定义官网', $this->normalize($result)['translations']['en']);
    }

    public function testTranslationInputRejectsInvalidValuesWithoutChangingDefaults(): void
    {
        $base = $this->normalize([]);
        $input = $base;
        $input['translations'] = ['en'=>['开始创作'=>['link'=>'/evil']], 'zh-TW'=>'invalid'];
        self::assertSame($base, $this->normalize($input));
        $input['translations'] = ['en'=>['开始创作'=>str_repeat('a',3001)]];
        self::assertSame($base, $this->normalize($input));
        $input['translations'] = ['en'=>['开始创作'=>'<b>Start</b>']];
        self::assertSame('Start', $this->normalize($input)['translations']['en']['开始创作']);
    }

    public function testOemUpgradePreservesHomepageAndReplacesAffiliateOnlyOnce(): void
    {
        $old = $this->normalize([]);
        $old['template_version'] = 7;
        $old['navigation'][5] = ['key'=>'affiliate','label'=>'联盟计划','link'=>'/user/distribution','enabled'=>1,'sort'=>55];
        $old['modules'] = array_values(array_filter($old['modules'], static fn($m) => strpos($m['key'], 'oem_') !== 0));
        $old['modules'][0]['title'] = '租户自定义公告';
        $result = $this->normalize($old);
        $map = array_column($result['modules'], null, 'key');
        foreach ($old['modules'] as $module) self::assertSame($module, $map[$module['key']]);
        $nav = array_column($result['navigation'], null, 'key');
        self::assertArrayNotHasKey('affiliate', $nav);
        self::assertSame('/official/oem', $nav['oem']['link']);
        self::assertSame('OEM贴牌', $nav['oem']['label']);
        self::assertCount(7, $map['oem_features']['cards']);
        self::assertSame($result, $this->normalize($result));
        $result['navigation'][5]['label'] = '我的品牌方案';
        $result['navigation'][5]['link'] = 'https://example.com/brand';
        self::assertSame($result, $this->normalize($result));
    }

    public function testOemMediaConfigurationRoundTripsAndEmptyCardsStayEmpty(): void
    {
        $input = ['template_version'=>8,'modules'=>[
            ['key'=>'oem_hero','icon_url'=>'/pc/oem-enterprise/suite-mark.svg','badge'=>'uploads/badge.png'],
            ['key'=>'oem_features','cards'=>[['title'=>'自有创意','background_media'=>'uploads/bg.mp4','background_media_type'=>'video','preview_layout'=>'marketing','preview_media'=>'uploads/preview.mp4','preview_media_type'=>'video','preview_poster'=>'uploads/poster.webp']]],
            ['key'=>'oem_clients','cards'=>[]],
        ]];
        $result=$this->normalize($input);
        $map=array_column($result['modules'],null,'key');
        self::assertSame('uploads/badge.png',$map['oem_hero']['badge']);
        self::assertSame('', $map['oem_hero']['icon_url']);
        self::assertSame('uploads/bg.mp4',$map['oem_features']['cards'][0]['background_media']);
        self::assertSame('marketing',$map['oem_features']['cards'][0]['preview_layout']);
        self::assertSame([], $map['oem_clients']['cards']);
        self::assertSame($result,$this->normalize($result));
        foreach (['/pc/oem-enterprise/suite-mark.svg','/oem-enterprise/suite-mark.svg','oem-enterprise/suite-mark.svg','https://cdn.example/pc/oem-enterprise/suite-mark.svg?v=1'] as $path) {
            self::assertSame('',$this->call('fileUrl',$path));
            self::assertSame('',$this->call('storageFileUrl',$path));
        }
        self::assertSame('', $this->call('bundledAsset','https://custom.example/oem-enterprise/suite-mark.svg'));
    }

    public function testV9RetiresOnlyBundledOemMediaAndPreservesTenantAssets(): void
    {
        $old = $this->normalize([]);
        $old['template_version'] = 8;
        foreach ($old['modules'] as &$module) {
            if ($module['key'] === 'hero') $module['media'] = 'uploads/home.mp4';
            if ($module['key'] === 'oem_hero') {
                $module['media'] = '/pc/oem-enterprise/hero.mp4';
                $module['poster'] = '/oem-enterprise/hero-thumbnail.webp';
            }
            if ($module['key'] === 'oem_features') $module['cards'][0] = [
                'media'=>'oem-enterprise/benefit-1.webp',
                'background_media'=>'uploads/custom-background.webp',
                'preview_media'=>'https://custom.example/oem-enterprise/hero.mp4',
                'preview_poster'=>'uploads/custom-poster.webp',
                'icon_url'=>'/pc/oem-enterprise/mcp-pill.png',
            ];
        }
        unset($module);
        $result = $this->normalize($old);
        $map = array_column($result['modules'], null, 'key');
        foreach ($old['modules'] as $module) if (strpos($module['key'], 'oem_') !== 0) self::assertSame($module, $map[$module['key']]);
        self::assertSame('', $map['oem_hero']['media']);
        self::assertSame('', $map['oem_hero']['poster']);
        self::assertSame('', $map['oem_hero']['icon_url']);
        $card = $map['oem_features']['cards'][0];
        self::assertSame('', $card['media']);
        foreach (['background_media','preview_media','preview_poster','icon_url'] as $field) self::assertSame(array_column($old['modules'], null, 'key')['oem_features']['cards'][0][$field], $card[$field]);
        self::assertSame($result, $this->normalize($result));
        foreach ($this->normalize([])['modules'] as $module) {
            if (strpos($module['key'], 'oem_') !== 0) continue;
            foreach (array_merge([$module], $module['cards']) as $item) foreach (['media','poster','background_media','background_poster','preview_media','preview_poster'] as $field) self::assertEmpty($item[$field] ?? '');
        }
    }

    public function testV7ReplacesOnlyRetiredCasesAndIsIdempotent(): void
    {
        $old = $this->normalize([]);
        $old['template_version'] = 6;
        $old['basic']['name'] = '租户品牌';
        foreach ($old['modules'] as &$module) {
            if ($module['key'] === 'hero') $module['media'] = 'uploads/keep.mp4';
            if ($module['key'] === 'cases') $module = [
                'key'=>'cases', 'enabled'=>0, 'sort'=>777, 'title'=>'旧创作方式',
                'cards'=>[['title'=>'旧工作流', 'icon'=>'video', 'link'=>'/ai', 'status'=>'live']],
            ];
        }
        unset($module);
        $result = $this->normalize($old);
        self::assertSame($old['basic'], $result['basic']);
        self::assertSame($old['navigation'], $result['navigation']);
        $modules = array_column($result['modules'], null, 'key');
        foreach ($old['modules'] as $module) {
            if ($module['key'] !== 'cases') self::assertSame($module, $modules[$module['key']]);
        }
        $reviews = $modules['cases'];
        self::assertSame(0, $reviews['enabled']);
        self::assertSame(777, $reviews['sort']);
        self::assertSame('创作者使用场景', $reviews['title']);
        self::assertCount(6, $reviews['cards']);
        self::assertSame('创作者示例 1', $reviews['cards'][0]['title']);
        foreach (['icon', 'icon_url', 'link', 'status', 'media', 'button_text'] as $field) self::assertArrayNotHasKey($field, $reviews['cards'][0]);
        self::assertSame($result, $this->normalize($result));
    }

    public function testReferenceReviewsBecomeLikeadminPresetsWithoutChangingCustomReviews(): void
    {
        $custom = ['title'=>'我的作者', 'description'=>'自己的评价', 'source'=>'社区', 'rating'=>4];
        $input = ['template_version'=>8, 'modules'=>[['key'=>'cases', 'enabled'=>0, 'sort'=>777,
            'description'=>'独立创作者和品牌团队每天都在 Likeadmin 上产出作品。',
            'cards'=>[
                ['title'=>'Scorpy', 'description'=>'原参考文案', 'source'=>'Trustpilot', 'enabled'=>0],
                ['title'=>'Mark', 'description'=>'我用 OpenArt 制作视频', 'source'=>''],
                $custom,
            ]]]];
        $result = $this->normalize($input);
        $cases = array_column($result['modules'], null, 'key')['cases'];
        self::assertSame(0, $cases['enabled']);
        self::assertSame(777, $cases['sort']);
        self::assertSame(0, $cases['cards'][0]['enabled']);
        self::assertSame('Likeadmin · 预设示例', $cases['cards'][0]['source']);
        self::assertSame('创作者示例 2', $cases['cards'][1]['title']);
        foreach ($custom as $key=>$value) self::assertSame($value, $cases['cards'][2][$key]);
        self::assertStringNotContainsString('OpenArt', json_encode($cases));
        self::assertStringNotContainsString('Trustpilot', json_encode($cases));
        self::assertSame($result, $this->normalize($result));
    }

    public function testV7TestimonialsAreEditableBoundedAndCanBeEmpty(): void
    {
        $input = ['template_version'=>7, 'modules'=>[['key'=>'cases', 'title'=>'自有评价', 'highlight_text'=>'评价',
            'autoplay_seconds'=>180, 'cards'=>array_fill(0, 30, ['title'=>'<b>作者</b>', 'description'=>'自己的评价',
                'source'=>'社区', 'rating'=>9, 'avatar'=>'uploads/avatar.webp', 'link'=>'/old'])]]];
        $result = $this->normalize($input);
        $cases = array_column($result['modules'], null, 'key')['cases'];
        self::assertSame('自有评价', $cases['title']);
        self::assertSame('评价', $cases['highlight_text']);
        self::assertSame(120, $cases['autoplay_seconds']);
        self::assertCount(24, $cases['cards']);
        self::assertSame('<b>作者</b>', $cases['cards'][0]['title']); // Rendered as escaped text, never HTML.
        self::assertSame(5, $cases['cards'][0]['rating']);
        self::assertSame('社区', $cases['cards'][0]['source']);
        self::assertSame('uploads/avatar.webp', $cases['cards'][0]['avatar']);
        self::assertArrayNotHasKey('link', $cases['cards'][0]);
        self::assertSame($result, $this->normalize($result));
        $input['modules'][0]['cards'] = [];
        $input['modules'][0]['autoplay_seconds'] = 0;
        $cases = array_column($this->normalize($input)['modules'], null, 'key')['cases'];
        self::assertSame([], $cases['cards']);
        self::assertSame(10, $cases['autoplay_seconds']);
    }

    public function testV10AddsOemBenefitsWithoutChangingExistingTenantModules(): void
    {
        $old = $this->normalize([]);
        $old['template_version'] = 9;
        $old['modules'] = array_values(array_filter($old['modules'], static fn($m) => $m['key'] !== 'oem_benefits'));
        foreach ($old['modules'] as &$module) {
            if ($module['key'] === 'oem_hero') $module['media'] = 'uploads/tenant-custom.mp4';
            if ($module['key'] === 'oem_clients') $module['enabled'] = 0;
        }
        unset($module);
        $result = $this->normalize($old);
        $modules = array_column($result['modules'], null, 'key');
        foreach ($old['modules'] as $module) self::assertSame($module, $modules[$module['key']]);
        $benefits = $modules['oem_benefits'];
        self::assertCount(4, $benefits['cards']);
        self::assertSame('专属品牌', $benefits['cards'][0]['tab_label']);
        self::assertArrayNotHasKey('button_link', $benefits);
        self::assertArrayNotHasKey('button_text', $benefits);
        self::assertSame($result, $this->normalize($result));
    }

    public function testOemBenefitsEditingRoundTripsAndRemainsScoped(): void
    {
        $result = $this->normalize(['template_version'=>10, 'modules'=>[
            ['key'=>'oem_benefits','enabled'=>0,'title'=>'自有品牌介绍','steps_title'=>'我的开通流程','footnote'=>'自定义权益说明',
                'button_link'=>'javascript:alert(1)','cards'=>[
                    ['title'=>'我的权益','tab_label'=>'专属服务','media'=>'uploads/custom.webp','icon'=>'book','icon_url'=>'uploads/brand.svg'],
                    ['title'=>'我的旧步骤','display_group'=>'step'],
                    ['title'=>'隐藏权益','enabled'=>0],
                ]],
            ['key'=>'oem_clients','steps_title'=>'不应保留','footnote'=>'不应保留','cards'=>[['title'=>'案例','display_group'=>'step']]],
        ]]);
        $modules = array_column($result['modules'], null, 'key');
        $block = $modules['oem_benefits'];
        self::assertSame(0, $block['enabled']);
        self::assertArrayNotHasKey('steps_title', $block);
        self::assertSame('自定义权益说明', $block['footnote']);
        self::assertArrayNotHasKey('button_link', $block);
        self::assertSame('专属服务', $block['cards'][0]['tab_label']);
        self::assertSame('uploads/custom.webp', $block['cards'][0]['media']);
        self::assertCount(2, $block['cards']);
        self::assertSame('uploads/brand.svg', $block['cards'][0]['icon_url']);
        self::assertSame(0, $block['cards'][1]['enabled']);
        self::assertArrayNotHasKey('footnote', $modules['oem_clients']);
        self::assertArrayNotHasKey('display_group', $modules['oem_clients']['cards'][0]);
        self::assertSame($result, $this->normalize($result));
        $block['cards'] = [];
        self::assertSame([], array_column($this->normalize(['template_version'=>10,'modules'=>[$block]])['modules'], null, 'key')['oem_benefits']['cards']);
    }

    public function testV11RepairsOemLabelsByContentAndKeepsCustomConfiguration(): void
    {
        $cards = [
            ['title'=>'按业务节奏选择与续期','tab_label'=>'无限画布','icon'=>'canvas','media'=>'uploads/renew.mp4','enabled'=>0,'sort'=>250],
            ['title'=>'用自己的后台经营','tab_label'=>'数字人','icon'=>'avatar','icon_url'=>'uploads/custom.svg','description'=>'自有介绍'],
            ['title'=>'用自己的后台经营','tab_label'=>'客户运营','icon'=>'book'],
            ['title'=>'自有数字人业务','tab_label'=>'数字人','icon'=>'avatar'],
        ];
        $result = $this->normalize(['template_version'=>10,'modules'=>[['key'=>'oem_benefits','cards'=>$cards]]]);
        $actual = array_column($result['modules'],null,'key')['oem_benefits']['cards'];
        self::assertSame('灵活续期', $actual[0]['tab_label']);
        self::assertSame('renew', $actual[0]['icon']);
        self::assertSame('uploads/renew.mp4', $actual[0]['media']);
        self::assertSame(0, $actual[0]['enabled']);
        self::assertSame(250, $actual[0]['sort']);
        self::assertSame('独立经营', $actual[1]['tab_label']);
        self::assertSame('manage', $actual[1]['icon']);
        self::assertSame('uploads/custom.svg', $actual[1]['icon_url']);
        self::assertSame('自有介绍', $actual[1]['description']);
        self::assertSame('客户运营', $actual[2]['tab_label']);
        self::assertSame('book', $actual[2]['icon']);
        self::assertSame('数字人', $actual[3]['tab_label']);
        self::assertSame($result, $this->normalize($result));
        $current = $this->normalize(['template_version'=>11,'modules'=>[
            ['key'=>'oem_benefits','cards'=>[['title'=>'自有权益','icon'=>'avatar']]],
            ['key'=>'products','cards'=>[['title'=>'数字人服务','icon'=>'avatar']]],
        ]]);
        $modules = array_column($current['modules'],null,'key');
        self::assertSame('自有权益', $modules['oem_benefits']['cards'][0]['tab_label']);
        self::assertSame('数字人', $modules['products']['cards'][0]['tab_label']);
    }

    public function testV12RemovesOnlyOemBenefitButtonConfiguration(): void
    {
        $old = $this->normalize([]);
        $old['template_version'] = 11;
        foreach ($old['modules'] as &$module) {
            if ($module['key'] === 'oem_benefits') {
                $module['button_text'] = '选择我的贴牌方案';
                $module['button_link'] = '/official/oem#oem-packages';
            }
        }
        unset($module);
        $result = $this->normalize($old);
        $modules = array_column($result['modules'], null, 'key');
        foreach ($old['modules'] as $module) {
            if ($module['key'] === 'oem_benefits') unset($module['button_text'], $module['button_link']);
            self::assertSame($module, $modules[$module['key']]);
        }
        $fields = \app\common\service\OfficialSiteFields::schema()['modules'];
        self::assertNotContains('button_text', $fields['oem_benefits']);
        self::assertNotContains('button_link', $fields['oem_benefits']);
        self::assertContains('button_text', $fields['oem_intro']);
        self::assertContains('button_text', $fields['oem_packages']);
        self::assertSame($result, $this->normalize($result));
    }

    public function testNewArchitectureUsesActualToolsAndRequestedEntries(): void
    {
        $config = $this->normalize([]);
        self::assertSame(14, $config['template_version']);
        self::assertSame(['应用工具','模型','API','价格','企业服务','OEM贴牌','帮助'], array_column($config['navigation'], 'label'));
        self::assertSame(['工作室','图片','视频'], array_column($config['navigation'][0]['groups'], 'title'));
        self::assertSame(['dropdown','dropdown','dropdown','link','link','link','link'], array_column($config['navigation'], 'mode'));
        $modules = array_column($config['modules'], null, 'key');
        self::assertSame(['AI 短剧','AI 视频','AI 绘图','数字人','无限画布','AI 音乐'], array_column($modules['products']['cards'], 'title'));
        self::assertSame([], $modules['models']['cards']);
        self::assertSame('light', $config['basic']['theme']);
    }
    public function testApiMenuMigrationAndConfiguredDestinationsRoundTrip(): void
    {
        $config = $this->normalize(['template_version' => 13, 'navigation' => [
            ['key' => 'open', 'label' => '开放平台', 'mode' => 'link', 'link' => '/official/open', 'enabled' => 0, 'sort' => 81],
        ]]);
        $entry = array_column($config['navigation'], null, 'key')['open'];
        self::assertSame('API', $entry['label']);
        self::assertSame('dropdown', $entry['mode']);
        self::assertSame(0, $entry['enabled']);
        self::assertSame(81, $entry['sort']);
        self::assertSame(['平台首页', '文档中心'], array_column($entry['groups'][0]['items'], 'label'));
        self::assertSame(['planned', 'planned'], array_column($entry['groups'][0]['items'], 'status'));
        self::assertSame(['', ''], array_column($entry['groups'][0]['items'], 'link'));
        foreach ($config['navigation'] as &$navigation) {
            if ($navigation['key'] !== 'open') continue;
            $navigation['groups'][0]['items'][1]['link'] = 'https://example.com/docs';
            $navigation['groups'][0]['items'][1]['status'] = 'live';
        }
        unset($navigation);
        self::assertSame($config, $this->normalize($config));
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
        self::assertArrayNotHasKey('link', $hero['cards'][0]);
        self::assertArrayNotHasKey('private_note', $hero['cards'][0]);
        self::assertArrayNotHasKey('autoplay_seconds', $hero);
        $scenes = array_column($this->normalize(['template_version'=>6,'modules'=>[['key'=>'scenes','autoplay_seconds'=>900]]])['modules'],null,'key')['scenes'];
        self::assertSame(30, $scenes['autoplay_seconds']);
    }
    public function testCustomNavigationSupportsSecureExternalDestinationsAndIcons(): void
    {
        $result = $this->normalize(['template_version'=>4,'navigation'=>[
            ['key'=>'open','label'=>'开发者','link'=>'https://example.com/developer'],
            ['key'=>'tools','groups'=>[['title'=>'自定义','items'=>[['label'=>'测试','status'=>'live','link'=>'/ai','icon_url'=>'uploads/custom.svg','description'=>'自己的工具']]]]],
        ]]);
        $nav = array_column($result['navigation'],null,'key');
        self::assertSame('https://example.com/developer', $nav['open']['groups'][0]['items'][0]['link']);
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
        $legacy = json_decode(file_get_contents(__DIR__ . '/../fixtures/official-site-v4-remnants.json'), true);
        $config = $this->normalize($legacy);
        $modules = array_column($config['modules'], null, 'key');
        $fresh = array_column($this->normalize([])['modules'], null, 'key');
        foreach (['hero', 'products', 'pricing', 'join'] as $key) {
            foreach (['title', 'description', 'button_text', 'button_link', 'cards'] as $field) {
                self::assertSame($fresh[$key][$field] ?? null, $modules[$key][$field] ?? null, "$key.$field");
            }
        }
        self::assertSame($config, $this->normalize($config));
    }

    public function testRemnantRepairPreservesCustomMediaCopyBrandAndRetiredCardOverrides(): void
    {
        $config = json_decode(file_get_contents(__DIR__ . '/../fixtures/official-site-v4-remnants.json'), true);
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

    public function testV6RemovesUnusedFieldsWithoutLosingLiveContent(): void
    {
        $input = ['template_version'=>5,'basic'=>['particles_enabled'=>1,'nav_products'=>'旧导航','join_text'=>'合作入口'], 'modules'=>[
            ['key'=>'hero','background_media'=>'uploads/unused.mp4','media'=>'uploads/fallback.mp4','media_type'=>'video',
                'cards'=>[['title'=>'素材描述','media'=>'uploads/hero.png','icon_url'=>'uploads/unused-icon.png','link'=>'/ai','kind'=>'canvas','canvas_image'=>'uploads/unused.png']],
                'slides'=>[['media'=>'uploads/film.mp4','poster'=>'uploads/poster.png','mobile_media'=>'uploads/mobile.mp4','mobile_poster'=>'uploads/mobile.png']]],
            ['key'=>'partners','description'=>'无效介绍','cards'=>[['title'=>'品牌','description'=>'无效介绍','icon'=>'image','icon_url'=>'uploads/brand.svg','link'=>'/ai','button_text'=>'无效按钮']]],
            ['key'=>'scenes','button_text'=>'无效按钮','cards'=>[['title'=>'场景','description'=>'介绍','icon'=>'image','icon_url'=>'uploads/unused.svg','eyebrow'=>'无效标签','button_text'=>'进入','link'=>'/ai','media'=>'uploads/scene.mp4','media_type'=>'video']]],
            ['key'=>'models','cards'=>[['model_id'=>'market_image_model:42','title'=>'模型','icon'=>'audio','link'=>'/wrong','eyebrow'=>'无效标签','icon_url'=>'uploads/model.svg']]],
            ['key'=>'faq','cards'=>[['title'=>'问题','description'=>'回答','media'=>'uploads/unused.png','link'=>'/ai']]],
            ['key'=>'footer','description'=>'无效介绍','cards'=>[['title'=>'工具','description'=>'无效介绍','icon'=>'grid','button_text'=>'无效按钮','link'=>'/ai','status'=>'live']]],
            ['key'=>'pricing','eyebrow'=>'价格小标题','button_text'=>'无效按钮','button_link'=>'/wrong'],
            ['key'=>'join','eyebrow'=>'合作小标题','button_text'=>'无效按钮','button_link'=>'/wrong'],
        ]];
        $config=$this->normalize($input);
        $m=array_column($config['modules'],null,'key');
        self::assertArrayNotHasKey('particles_enabled',$config['basic']);
        self::assertArrayNotHasKey('nav_products',$config['basic']);
        self::assertSame('合作入口',$config['basic']['join_text']);
        self::assertArrayNotHasKey('background_media',$m['hero']);
        self::assertSame('uploads/fallback.mp4',$m['hero']['media']);
        self::assertSame('uploads/mobile.png',$m['hero']['slides'][0]['mobile_poster']);
        self::assertSame(['title','enabled','media','poster','media_type','sort'],array_keys($m['hero']['cards'][0]));
        self::assertSame('uploads/brand.svg',$m['partners']['cards'][0]['icon_url']);
        self::assertArrayNotHasKey('description',$m['partners']);
        self::assertArrayNotHasKey('button_text',$m['partners']['cards'][0]);
        self::assertArrayNotHasKey('icon_url',$m['scenes']['cards'][0]);
        self::assertSame('进入',$m['scenes']['cards'][0]['button_text']);
        self::assertSame('uploads/scene.mp4',$m['scenes']['cards'][0]['media']);
        self::assertArrayNotHasKey('link',$m['models']['cards'][0]);
        self::assertSame('uploads/model.svg',$m['models']['cards'][0]['icon_url']);
        self::assertSame(['title','enabled','description','sort'],array_keys($m['faq']['cards'][0]));
        self::assertArrayNotHasKey('description',$m['footer']['cards'][0]);
        self::assertSame('/ai',$m['footer']['cards'][0]['link']);
        self::assertSame('价格小标题',$m['pricing']['eyebrow']);
        self::assertSame('合作小标题',$m['join']['eyebrow']);
        self::assertArrayNotHasKey('button_link',$m['pricing']);
        self::assertArrayNotHasKey('button_text',$m['join']);
        self::assertSame($config,$this->normalize($config));
    }

    public function testModelNavigationDropsUnusedGroupsButKeepsRealDropdownControls(): void
    {
        $config=$this->normalize(['template_version'=>5,'navigation'=>[
            ['key'=>'models','title'=>'模型介绍','button_text'=>'所有模型','button_link'=>'/ai','groups'=>[['title'=>'旧模型','items'=>[]]]],
            ['key'=>'tools','title'=>'没有展示的标题','groups'=>[['title'=>'工具组','items'=>[['label'=>'入口','icon'=>'image','status'=>'live','link'=>'/ai']]]]],
        ]]);
        $nav=array_column($config['navigation'],null,'key');
        self::assertSame([],$nav['models']['groups']);
        self::assertSame('模型介绍',$nav['models']['title']);
        self::assertSame('/ai',$nav['models']['button_link']);
        self::assertArrayNotHasKey('title',$nav['tools']);
        self::assertSame('image',$nav['tools']['groups'][0]['items'][0]['icon']);
        self::assertSame($config,$this->normalize($config));
    }

}
