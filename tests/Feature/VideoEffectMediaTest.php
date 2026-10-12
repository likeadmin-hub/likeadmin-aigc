<?php
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_video\VideoEffectService as Effects;
use app\common\service\app\aigc_video\VideoEffectMediaService as Media;

final class VideoEffectMediaTest extends TestCase
{
    public function testAbsentConfigurationEnablesDefaultsButExplicitDecisionsSurvive(): void
    {
        self::assertCount(90, Effects::publicCatalog(Effects::configuredCatalog([])));
        self::assertSame([], Effects::configuredCatalog(['video_effects'=>[]]));
        $saved = array_slice(Effects::defaults(), 0, 2);
        $saved[0]['enabled'] = false;
        $saved[1]['name'] = 'Tenant custom name';
        self::assertEquals($saved, Effects::configuredCatalog(['video_effects'=>$saved]));
    }

    public function testOnlyDefaultPreviewUrlsAreReplacedWithoutChangingTenantContent(): void
    {
        $defaults = array_slice(Effects::defaults(), 0, 1);
        $id = $defaults[0]['id'];
        $file = ['uri'=>'uploads/video-effects/platform-defaults/demo.webp','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>''];
        $registry = [$id=>['poster'=>$file,'video'=>$file]];
        $url = static fn($file)=>'/'.$file['uri'];
        $result = Media::resolve($defaults, $defaults, $registry, $url);
        self::assertSame('/'.$file['uri'], $result[0]['poster']);
        self::assertSame($defaults[0]['instruction'], $result[0]['instruction']);
        $custom = $defaults;
        $custom[0]['poster'] = 'https://tenant.example/custom.webp';
        $custom[0]['video'] = '';
        $custom[0]['enabled'] = false;
        self::assertSame($custom, Media::resolve($custom, $defaults, $registry, $url));
        self::assertSame($defaults, Media::resolve($defaults, $defaults, [], $url));
        $registry[$id]['poster']['storage_scope'] = 'tenant';
        self::assertSame($defaults[0]['poster'], Media::resolve($defaults, $defaults, $registry, $url)[0]['poster']);
    }

    public function testSavedManagedCloudPreviewGetsAFreshSignedUrl(): void
    {
        $defaults = array_slice(Effects::defaults(), 0, 1);
        $saved = $defaults;
        $saved[0]['poster'] = 'https://storage.example/previews/demo.webp?expired-signature=old';
        $registry = [$saved[0]['id']=>['poster'=>['uri'=>'previews/demo.webp','storage_scope'=>'platform','storage_engine'=>'qcloud','storage_domain'=>'https://storage.example']]];
        $result = Media::resolve($saved, $defaults, $registry, static fn($file)=>'https://storage.example/'.$file['uri'].'?signature=new');
        self::assertStringEndsWith('?signature=new', $result[0]['poster']);
        $saved[0]['poster'] = 'https://tenant.example/previews/demo.webp';
        self::assertSame($saved, Media::resolve($saved, $defaults, $registry, static fn()=>'/unexpected'));
    }
}
