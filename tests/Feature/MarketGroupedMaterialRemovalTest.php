<?php
namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService as Videos;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use PHPUnit\Framework\TestCase;

/** Pure synthetic frozen declarations: no catalogue, file upload, provider or billing writes. */
class MarketGroupedMaterialRemovalTest extends TestCase
{
    public static function snapshot(): array
    {
        $group=['type'=>'array','minItems'=>1,'maxItems'=>1,'items'=>['type'=>'object','properties'=>[
            'name'=>['type'=>'string','minLength'=>1],'images'=>['type'=>'array','minItems'=>1,'maxItems'=>1,'items'=>['type'=>'string','format'=>'uri']],
            'video_url'=>['type'=>'string','format'=>'uri','minLength'=>1],'audio_url'=>['type'=>'string','format'=>'uri','minLength'=>1],
            'enabled'=>['type'=>'boolean'],'start_time'=>['type'=>'integer','minimum'=>0]],'required'=>['name','images','video_url','audio_url']]];
        return ['resource_type'=>'model','model_code'=>'synthetic-grouped-media','channel_code'=>'fixture','locked_params'=>[],
            'market_metadata'=>['params_schema'=>['type'=>'object','properties'=>['prompt'=>['type'=>'string'],'duration'=>['type'=>'integer'],
                'group_a'=>$group,'group_b'=>$group],'required'=>['prompt','group_a','group_b']],
                'material_capabilities'=>['modes'=>[['code'=>'groups','view_key'=>'groups','method'=>'omni_reference','fields'=>[
                    ['field'=>'group_a','kind'=>'subject','required'=>true,'minimum'=>1,'maximum'=>1],
                    ['field'=>'group_b','kind'=>'subject','required'=>true,'minimum'=>1,'maximum'=>1]]]]]]];
    }
    public static function request(): array
    {
        $group=['name'=>'Synthetic','images'=>['https://fixtures.invalid/same.png'],'video_url'=>'https://fixtures.invalid/clip.mp4',
            'audio_url'=>'https://fixtures.invalid/tone.wav','enabled'=>false,'start_time'=>0];$assets=[];
        foreach(['group_a','group_b']as$field)foreach(['image'=>'same.png','video'=>'clip.mp4','audio'=>'tone.wav']as$type=>$file)$assets[]=[
            'type'=>$type,'url'=>'https://fixtures.invalid/'.$file,'field'=>$field,'role'=>'reference_'.$type,'reference_slot_id'=>$field.':'.$type];
        return ['prompt'=>'Synthetic grouped media','duration'=>4,'generation_method'=>'omni_reference','material_mode'=>'groups',
            'provider_params'=>['group_a'=>[$group],'group_b'=>[$group]],'reference_assets'=>$assets];
    }
    public function testMixedGroupsStayIndependentAcrossJsonReopenAndOneReplacement(): void
    {
        $s=self::snapshot();$r=self::request();$before=$r;$a=Videos::prepareRequest($s,$r);
        $b=Videos::prepareRequest(json_decode(json_encode($s),true),json_decode(json_encode($r),true));
        self::assertSame($a->nativeInput,$b->nativeInput);self::assertSame($a->meterContext,$b->meterContext);self::assertSame($before,$r);
        self::assertSame($r['provider_params']['group_a'],$b->nativeInput['group_a']);self::assertSame($r['provider_params']['group_b'],$b->nativeInput['group_b']);
        $r['provider_params']['group_a'][0]['video_url']='https://fixtures.invalid/new.mp4';$r['reference_assets'][1]['url']='https://fixtures.invalid/new.mp4';
        $p=Videos::prepareRequest($s,$r);self::assertSame('https://fixtures.invalid/new.mp4',$p->nativeInput['group_a'][0]['video_url']);
        self::assertSame($before['provider_params']['group_b'],$p->nativeInput['group_b']);
        self::assertFalse($p->nativeInput['group_a'][0]['enabled']);self::assertSame(0,$p->nativeInput['group_a'][0]['start_time']);
    }
    /** @dataProvider missingInputs */
    public function testRequiredChildrenGroupsAndPerGroupBudgetsRejectAfterRemoval(string $case): void
    {
        $r=self::request();
        if($case==='group'){$r['provider_params']['group_a']=[];$r['reference_assets']=array_slice($r['reference_assets'],3);}
        elseif($case==='image'){$r['provider_params']['group_a'][0]['images']=[];unset($r['reference_assets'][0]);}
        elseif($case==='video'||$case==='audio'){unset($r['provider_params']['group_a'][0][$case.'_url']);unset($r['reference_assets'][$case==='video'?1:2]);}
        elseif($case==='name')unset($r['provider_params']['group_a'][0]['name']);
        else $r['provider_params']['group_a'][]=$r['provider_params']['group_a'][0];
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage(['group'=>'group_a','image'=>'images','video'=>'video_url','audio'=>'audio_url','name'=>'name','over_group_limit'=>'group_a'][$case]);Videos::prepareRequest(self::snapshot(),$r);
    }
    public static function missingInputs(): array {return array_map(static fn($v)=>[$v],['group','image','video','audio','name','over_group_limit']);}
    public function testImageFrozenDefaultsStaySeparateFromNewDefaultsAndExplicitFalseZero(): void
    {
        $s=['resource_type'=>'model','model_code'=>'synthetic-image-history','channel_code'=>'fixture','locked_params'=>[],
            'market_metadata'=>['default_params'=>['seed'=>0,'watermark'=>false],'params_schema'=>['prompt'=>['type'=>'string'],'aspect_ratio'=>['type'=>'string','enum'=>['3:4']],
                'seed'=>['type'=>'integer','default'=>0],'watermark'=>['type'=>'boolean','default'=>false]]]];
        $r=['prompt'=>'Synthetic frozen image','ratio'=>'3:4','quantity'=>1];$before=$r;
        $old=Images::prepareRequest($s,$r);$new=$s;$new['market_metadata']['default_params']=['seed'=>19,'watermark'=>true];$new['market_metadata']['params_schema']['seed']['default']=19;$new['market_metadata']['params_schema']['watermark']['default']=true;
        $changed=Images::prepareRequest($new,$r);$reopen=Images::prepareRequest(json_decode(json_encode($s),true),json_decode(json_encode($r),true));
        self::assertSame($old->nativeInput,$reopen->nativeInput);self::assertSame($old->meterContext,$reopen->meterContext);
        self::assertFalse($old->nativeInput['watermark']);self::assertSame(0,$old->nativeInput['seed']);
        self::assertTrue($changed->nativeInput['watermark']);self::assertSame(19,$changed->nativeInput['seed']);
        $explicit=Images::prepareRequest($new,$r+['provider_params'=>['watermark'=>false,'seed'=>0]]);
        self::assertFalse($explicit->nativeInput['watermark']);self::assertSame(0,$explicit->nativeInput['seed']);self::assertSame($before,$r);
    }
}
