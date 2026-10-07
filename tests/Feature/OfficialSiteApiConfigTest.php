<?php
namespace Tests\Feature;

use app\common\service\OfficialSiteService;
use app\common\service\OfficialSiteFields;
use PHPUnit\Framework\TestCase;

class OfficialSiteApiConfigTest extends TestCase
{
    private function normalize(array $input): array
    {
        $method = new \ReflectionMethod(OfficialSiteService::class, 'normalize');
        $method->setAccessible(true);
        return $method->invoke(null, $input);
    }

    public function testUpgradeAddsApiConfigurationWithoutChangingTenantContent(): void
    {
        $before = $this->normalize([]);
        $before['template_version'] = 14;
        $before['basic']['name'] = 'Tenant Brand';
        $before['modules'] = array_values(array_filter($before['modules'], static fn($m) => strpos($m['key'], 'api_') !== 0));
        $after = $this->normalize($before);
        $this->assertSame('Tenant Brand', $after['basic']['name']);
        $this->assertSame($before['modules'], array_values(array_filter($after['modules'], static fn($m) => strpos($m['key'], 'api_') !== 0)));
        $map = array_column($after['modules'], null, 'key');
        $this->assertCount(10, OfficialSiteFields::schema()['api']);
        $this->assertSame(0, $map['api_clients']['enabled']);
        $this->assertSame([], $map['api_clients']['cards']);
        $this->assertSame($after, $this->normalize($after));
    }

    public function testApiOverridesAndExplicitEmptyValuesSurviveSaveRoundtrip(): void
    {
        $config = $this->normalize([]);
        foreach ($config['modules'] as &$block) {
            if ($block['key'] === 'api_hero') { $block['title'] = ''; $block['media'] = 'https://example.test/custom.webp'; $block['enabled'] = 0; }
            if ($block['key'] === 'api_faq') $block['cards'] = [];
            if ($block['key'] === 'api_generation') $block['cards'][0] = array_merge($block['cards'][0], ['preset'=>'image','title'=>'Tenant model','tab_label'=>'Custom','media'=>'https://example.test/image.webp']);
            if ($block['key'] === 'api_features') $block['cards'][0]['preview_media'] = 'https://example.test/before.webp';
        }
        unset($block);
        $config['translations'] = ['en'=>['Tenant model'=>'Translated model']];
        $result = $this->normalize($config);
        $map = array_column($result['modules'], null, 'key');
        $this->assertSame('', $map['api_hero']['title']);
        $this->assertSame(0, $map['api_hero']['enabled']);
        $this->assertSame([], $map['api_faq']['cards']);
        $this->assertSame('image', $map['api_generation']['cards'][0]['preset']);
        $this->assertSame('https://example.test/before.webp', $map['api_features']['cards'][0]['preview_media']);
        $this->assertSame('Translated model', $result['translations']['en']['Tenant model']);
        $this->assertSame($result, $this->normalize($result));
    }

    public function testApiConfigurationRejectsExecutableLinksAndBillingFields(): void
    {
        $result = $this->normalize(['modules'=>[
            ['key'=>'api_navigation','cards'=>[['title'=>'Bad','link'=>'javascript:alert(1)']]],
            ['key'=>'api_membership','price'=>1,'api_access'=>true],
            ['key'=>'api_generation','cards'=>[['title'=>'Bad','preset'=>'../../secret']]],
        ]]);
        $map = array_column($result['modules'], null, 'key');
        $this->assertSame('', $map['api_navigation']['cards'][0]['link']);
        $this->assertArrayNotHasKey('price', $map['api_membership']);
        $this->assertArrayNotHasKey('api_access', $map['api_membership']);
        $this->assertSame('', $map['api_generation']['cards'][0]['preset']);
    }
}
