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
    public function testExplicitAvailableTextChoiceOverridesDefaultAndPreservesMedia(): void {
        $result=$this->resolve(701,['model_selections'=>['script_plan'=>['id'=>'old-text'],'image'=>'image-b']]);
        self::assertSame('old-text',$result['script_plan']['id']);
        self::assertSame('image-b',$result['image']['id']);
        self::assertSame('text-a',$this->resolve(701)['script_plan']['id']);
        self::assertSame('text-b',$this->resolve(702)['script_plan']['id']);
    }
    public function testCrossTenantExplicitSelectionIsRejectedInsteadOfDefaulted(): void {
        $this->expectExceptionMessage('Unavailable'); $this->resolve(701,['model_id'=>'text-b']);
    }
    public function testRetiredExplicitSelectionIsRejectedInsteadOfDefaulted(): void {
        $this->expectExceptionMessage('Unavailable'); $this->resolve(701,['model_selections'=>['script_plan'=>['id'=>'retired']]]);
    }
    public function testUnavailableMediaSelectionIsRejectedInsteadOfDefaulted(): void {
        $this->expectExceptionMessage('所选模型未上架或已不可用'); $this->resolve(701,['model_selections'=>['image'=>'other-tenant-image']]);
    }
    public function testWorkerKeepsFrozenSelectionWhenTenantDefaultChanges(): void {
        $input=['model_selections'=>['script_plan'=>['id'=>'old-text']]];
        Config::$configs[701]['default_text_model_id']='retired';
        self::assertSame('old-text',$this->resolve(701,$input,true)['script_plan']['id']);
        self::assertSame('old-text',$this->resolve(701,$input)['script_plan']['id']);
        self::assertSame('retired',$this->resolve(701,['model_selections'=>['script_plan'=>['id'=>'retired']]],true)['script_plan']['id']);
    }
    public function testWorkerKeepsRetiredFrozenMediaInsteadOfChangingItsModel(): void {
        $result=$this->resolve(701,['model_selections'=>['image'=>['id'=>'retired-image'],'video'=>['id'=>'retired-video']]],true);
        self::assertSame('retired-image',$result['image']['id']); self::assertSame('retired-video',$result['video']['id']);
    }
    public function testRetiredDefaultDoesNotLockOutOtherSaleableModels(): void {
        Config::$configs[701]['default_text_model_id']='retired';
        self::assertSame('text-a',$this->resolve(701)['script_plan']['id']);
        self::assertSame('old-text',$this->resolve(701,['model_id'=>'old-text'])['script_plan']['id']);
    }
    public function testLegacyUnconfiguredTenantRetainsAvailableDefault(): void {
        Config::$configs[701]=[]; self::assertSame('text-a',$this->resolve(701,['model_id'=>'script-planner-default'])['script_plan']['id']);
    }
    public function testDirectCanvasNodeUsesAuthorizedSelectionAndCannotEnableFallback(): void {
        $method=new \ReflectionMethod(Canvas::class,'generationPayload'); $method->setAccessible(true);
        $payload=$method->invoke(null,'text',['prompt'=>'test','model_id'=>'old-text','model_code'=>'forged','channel'=>'forged'],701,1,1);
        self::assertSame('old-text',$payload['model_id']); self::assertSame('old-text',$payload['model_selection']['id']);
        self::assertArrayNotHasKey('channel',$payload); self::assertTrue($payload['_disable_model_fallback']);
    }
    public function testDirectCanvasRejectsOtherTenantModel(): void {
        $method=new \ReflectionMethod(Canvas::class,'generationPayload'); $method->setAccessible(true);
        $this->expectExceptionMessage('Unavailable'); $method->invoke(null,'text',['prompt'=>'test','model_id'=>'text-b'],701,1,1);
    }
    public function testReplayIdentityCanBeBuiltWithoutReadingChangedTenantConfiguration(): void {
        $method=new \ReflectionMethod(Canvas::class,'generationPayload'); $method->setAccessible(true);
        $params=['prompt'=>'test','model_id'=>'legacy-model'];
        $before=$method->invoke(null,'text',$params,701,1,1,false,false);
        Config::$configs[701]['default_text_model_id']='retired';
        $after=$method->invoke(null,'text',$params,701,1,1,false,false);
        self::assertSame($before,$after); self::assertSame([],\app\common\service\power\MarketTextModelRuntimeService::$calls);
    }
    public function testAgentResolvesExplicitTextPreferenceAndPreservesMediaAndMode(): void {
        $groups=[['key'=>'script_plan','default'=>'text-a','options'=>[['id'=>'text-a'],['id'=>'old-text']]],
            ['key'=>'image','default'=>'image-a','options'=>[['id'=>'image-a'],['id'=>'image-b']]]];
        $result=Settings::fromCatalog($groups,['reasoning_model'=>'old-text','image_model'=>'image-b','generation_mode'=>'auto']);
        self::assertSame('old-text',$result['reasoning_model']['id']); self::assertSame('image-b',$result['image_model']['id']); self::assertSame('auto',$result['generation_mode']);
    }
    public function testAgentRejectsUnlistedTextPreference(): void {
        $this->expectExceptionMessage('REASONING_MODEL_UNAVAILABLE');
        Settings::fromCatalog([['key'=>'script_plan','default'=>'text-a','options'=>[['id'=>'text-a']]]],['reasoning_model'=>'other-tenant']);
    }
    public function testHistoricalModelProjectionAcceptsScalarIdsAndNeverExposesRuntimeData(): void {
        $method=new \ReflectionMethod(Service::class,'userModelSelectionsSnapshot'); $method->setAccessible(true);
        $result=$method->invoke(null,['script_plan'=>'legacy-id','image'=>42,'video'=>['id'=>'video-id','name'=>'Tenant label','channel_source'=>'official','provider_url'=>'secret','tenant_unit_price'=>99,'credentials'=>['key'=>'secret']], 'other'=>['id'=>'untrusted']]);
        self::assertSame(['id'=>'legacy-id','channel_source'=>'third_party'],$result['script_plan']);
        self::assertSame('42',$result['image']['id']);
        self::assertSame(['id'=>'video-id','name'=>'Tenant label','channel_source'=>'official'],$result['video']);
        self::assertArrayNotHasKey('other',$result);
        self::assertSame([],$method->invoke(null,['script_plan'=>['id'=>['nested']],'image'=>false,'video'=>['value'=>new \stdClass()]]));
    }
    public function testUserModelDirectoryPreservesChoicesAndOfficialOrder(): void {
        $method=new \ReflectionMethod(Service::class,'userScriptModelGroups'); $method->setAccessible(true);
        $groups=$method->invoke(null,[['key'=>'script_plan','default'=>'text-a','options'=>[
            ['id'=>'text-a','name'=>'Relay','channel_source'=>'third_party'],['id'=>'old-text','name'=>'Official','channel_source'=>'official'],
        ]]]);
        self::assertSame(['old-text','text-a'],array_column($groups[0]['options'],'id')); self::assertSame('text-a',$groups[0]['default']);
    }
}
