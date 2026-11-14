<?php
namespace Tests\Feature;

use app\common\service\app\aigc_store_visit\{StoreVisitPolicy as P,StoreVisitVoiceReference as Voice,StoreVisitStarterCatalog as Starters,StoreVisitCatalog,StoreVisitWorker};
use app\common\service\app\aigc_short_drama\BuiltinVisualStyleCovers;
use app\common\service\app\StudioBuiltinTemplates;
use PHPUnit\Framework\TestCase;

final class StoreVisitVoiceAndStarterTest extends TestCase
{
    private function body(): array
    {
        return P::body(['images'=>[['uri'=>'uploads/store.png','role'=>'store','reference_slot_id'=>'store','mention_name'=>'图片1'],['uri'=>'uploads/person.png','role'=>'person','reference_slot_id'=>'person','mention_name'=>'图片2']], 'reference_audio'=>['uri'=>'uploads/voice.wav','url'=>'https://example.test/signed/one','reference_slot_id'=>'store_visit_voice','mention_name'=>'音频1'], 'voice_character'=>'探店男主', 'video_selection'=>['duration'=>15,'ratio'=>'9:16'], 'script'=>'人物走入门店，与镜头对话。']);
    }
    public function testVoiceAndSpeakerInvalidateScriptsWhileSignedUrlsAndOldStyleDoNot(): void
    {
        $b=$this->body();$key=P::inputKey($b);
        $b['reference_audio']['url']='https://example.test/signed/two';$b['style_id']='legacy';$b['custom_style']='卡通';self::assertSame($key,P::inputKey($b));
        $b['voice_character']='其他人物';self::assertNotSame($key,P::inputKey($b));
        $b=$this->body();$b['reference_audio']['uri']='uploads/second.wav';self::assertNotSame($key,P::inputKey($b));
        $normalized=P::body($b+['style_snapshot'=>['prompt'=>'卡通']]);self::assertSame(P::NO_STYLE,$normalized['style_id']);self::assertSame('',$normalized['style_snapshot']['prompt']);
        self::assertSame('探店人物',P::body(['voice_character'=>''])['voice_character']);
    }
    public function testPromptAssignsVoiceToOneNamedSpeaker(): void
    {
        $b=$this->body();$b['script_input_key']=P::inputKey($b);$prompt=P::prompt($b);
        foreach(['@音频1','说话人物【探店男主】','跨镜头保持一致','不复制参考音频原台词','@图片1 是门店参考图','@图片2 是人物参考图']as$text)self::assertStringContainsString($text,$prompt);
    }
    public function testSilentGenerationCannotDiscardReferenceVoice(): void
    {
        $b=$this->body();$b['audio']=false;$b['script_input_key']=P::inputKey($b);
        $this->expectExceptionMessage('不能关闭声音');P::prompt($b);
    }
    public function testAudioSurvivesWorkerPayloadWithoutEnteringImageList(): void
    {
        $b=$this->body();$params=Voice::parameters(Voice::assets($b,['generation_method'=>'omni_reference']));
        self::assertSame(['uploads/store.png','uploads/person.png'],$params['reference_images']);self::assertSame(['uploads/voice.wav'],$params['reference_audios']);
        self::assertSame(['image','image','audio'],array_column($params['reference_assets'],'type'));self::assertSame('音频1',$params['selected_mentions'][2]['name']);
        $method=new \ReflectionMethod(StoreVisitWorker::class,'mediaRequest');$method->setAccessible(true);
        $payload=$method->invoke(null,'video',$params+['prompt'=>'@音频1','selection'=>['model_id'=>'native'],'private_metadata'=>'discard']);
        self::assertSame($params['reference_audios'],$payload['reference_audios']);self::assertSame($params['reference_assets'],$payload['reference_assets']);self::assertArrayNotHasKey('private_metadata',$payload);
    }
    public function testAudioCapabilityUsesDeclaredNativeFields(): void
    {
        $model=['material_modes'=>[['method'=>'omni_reference','fields'=>[['kind'=>'image','minimum'=>1,'maximum'=>6],['kind'=>'audio','minimum'=>1,'maximum'=>1,'required'=>true]]]]];
        self::assertTrue(StoreVisitCatalog::supportsReferenceAudio($model));self::assertSame(6,StoreVisitCatalog::imageLimit($model));self::assertFalse(StoreVisitCatalog::supportsReferenceAudio([]));
    }
    public function testTenScriptStartersHaveLocalCoversAndNoForeignInputMedia(): void
    {
        $rows=Starters::all();self::assertCount(10,$rows);self::assertCount(10,array_unique(array_column($rows,'id')));
        foreach($rows as$row){self::assertLessThan(0,$row['id']);self::assertSame($row,Starters::find($row['id']));self::assertNotEmpty($row['script']);self::assertStringStartsWith('resource/image/apps/aigc_store_visit/inspirations/',$row['cover']);self::assertFileExists(dirname(__DIR__,2).'/public/'.$row['cover']);self::assertArrayNotHasKey('images',$row);self::assertArrayNotHasKey('reference_audio',$row);}
        self::assertNull(Starters::find(1));
    }
    public function testPersonalVoiceCannotLeakIntoPortablePublishedTemplates(): void
    {
        $this->expectExceptionMessage('移除个人参考音色');StudioBuiltinTemplates::portableBody(P::APP,$this->body());
    }
    public function testEverySeededStyleHasAnInstalledCoverAndCustomCoversSurvive(): void
    {
        $map=json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_short_drama/data/style-covers.json'),true);self::assertCount(94,$map);
        foreach($map as$name=>$uri){self::assertSame($uri,BuiltinVisualStyleCovers::uri('static/aigc_short_drama/style_library/old.webp',$name));self::assertFileExists(dirname(__DIR__,2).'/public/'.ltrim($uri,'/'));self::assertSame('uploads/tenant-cover.webp',BuiltinVisualStyleCovers::uri('uploads/tenant-cover.webp',$name));}
    }
}
