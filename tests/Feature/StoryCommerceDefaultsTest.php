<?php
namespace Tests\Feature;
use app\common\service\app\aigc_story_commerce\StoryCommerceDefaults as Defaults;
use PHPUnit\Framework\TestCase;
final class StoryCommerceDefaultsTest extends TestCase
{
    public function testPreferredDefaultsUseAvailableModelIdentitiesRatherThanInstallIds(): void
    {
        self::assertSame('new-qwen-id',Defaults::planning('',[['id'=>'first','model_code'=>'gpt-other'],['id'=>'new-qwen-id','model_code'=>'Qwen3.7-Plus']]));
        foreach(['Wan 3.0 - 高速生成视频','Wan3.0 Fast','Wan 3.0 Turbo']as$label)self::assertSame('new-wan-id',Defaults::video('',[['id'=>'normal','label'=>'Wan 3.0 - 生成视频'],['id'=>'new-wan-id','label'=>$label]]));
    }
    public function testExplicitTenantChoicesRemainExact(): void
    {
        self::assertSame('custom',Defaults::planning('custom',[['id'=>'qwen','model_code'=>'qwen3.7-plus']]));
        self::assertSame('custom',Defaults::video('custom',[['id'=>'wan','label'=>'Wan 3.0 Fast']]));
    }
    public function testAbsentPreferencesNeverInventAnUnavailableModel(): void
    {
        self::assertSame('',Defaults::planning('',[]));self::assertSame('',Defaults::video('',[]));
        self::assertSame('first',Defaults::video('',[['id'=>'first','label'=>'Wan 2.7']]));
        self::assertSame('first',Defaults::planning('',[['id'=>'first','model_code'=>'Qwen3.7-Max']]));
    }
}
