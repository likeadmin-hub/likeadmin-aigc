<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSettings as Settings;
use app\common\model\app\aigc_short_drama\AigcShortDramaConfig as Config;

/** @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ShortDramaTenantTextModelTest extends TestCase {
    protected function setUp(): void { require __DIR__.'/../fixtures/short_drama_tenant_text_model.php'; }
    private function resolve(int $tenant,array $input=[],bool $stored=false): array {
        $method=new \ReflectionMethod(Service::class,'resolveSelectedModels'); $method->setAccessible(true);
        return $method->invoke(null,$tenant,$input,['model_groups'=>[
            ['key'=>'image','default'=>'image-a','options'=>[['id'=>'image-a'],['id'=>'image-b']]],
        ]],$stored);
    }
    public function testTenantConfigOverridesForgedTextSelectionsAndPreservesMedia(): void {
        $input=['model_id'=>'text-b','model_selections'=>['script_plan'=>['id'=>'old-text','market_sku_id'=>999],'image'=>'image-b']];
        self::assertSame('text-a',$this->resolve(701,$input)['script_plan']['id']);
        self::assertSame('text-b',$this->resolve(702,$input)['script_plan']['id']);
        self::assertSame('image-b',$this->resolve(701,$input)['image']['id']);
    }
    public function testWorkerKeepsFrozenTaskAndNewRevisionUsesCurrentConfig(): void {
        $input=['model_selections'=>['script_plan'=>['id'=>'old-text']]];
        self::assertSame('old-text',$this->resolve(701,$input,true)['script_plan']['id']);
        self::assertSame('text-a',$this->resolve(701,$input)['script_plan']['id']);
        Config::$configs[701]['default_text_model_id']='retired';
        self::assertSame('old-text',$this->resolve(701,$input,true)['script_plan']['id']);
    }
    public function testUnavailableConfiguredModelFailsInsteadOfSilentlyChoosingAnother(): void {
        Config::$configs[701]['default_text_model_id']='retired';
        $this->expectExceptionMessage('短剧文本模型已下架或不可用');
        $this->resolve(701);
    }
    public function testLegacyUnconfiguredTenantRetainsAvailableDefault(): void {
        Config::$configs[701]=[];
        self::assertSame('text-a',$this->resolve(701)['script_plan']['id']);
    }
    public function testDirectCanvasNodeCannotOverrideTenantModelOrEnableFallback(): void {
        $method=new \ReflectionMethod(Canvas::class,'generationPayload'); $method->setAccessible(true);
        $payload=$method->invoke(null,'text',['prompt'=>'test','model_id'=>'text-b','model_code'=>'forged','channel'=>'forged'],701,1,1);
        self::assertSame('text-a',$payload['model_id']);
        self::assertSame('text-a',$payload['model_selection']['id']);
        self::assertArrayNotHasKey('channel',$payload);
        self::assertTrue($payload['_disable_model_fallback']);
    }
    public function testAgentIgnoresLegacyTextPreferenceButPreservesMediaAndMode(): void {
        $groups=[['key'=>'script_plan','default'=>'text-a','options'=>[['id'=>'text-a'],['id'=>'old-text']]],
            ['key'=>'image','default'=>'image-a','options'=>[['id'=>'image-a'],['id'=>'image-b']]]];
        foreach (['old-text','other-tenant',null,['id'=>'injected']] as $legacy) {
            $result=Settings::fromCatalog($groups,['reasoning_model'=>$legacy,'image_model'=>'image-b','generation_mode'=>'auto']);
            self::assertSame('text-a',$result['reasoning_model']['id']);
            self::assertSame('image-b',$result['image_model']['id']);
            self::assertSame('auto',$result['generation_mode']);
        }
    }
}
