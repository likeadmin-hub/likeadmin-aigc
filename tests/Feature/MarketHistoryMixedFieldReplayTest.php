<?php
namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService as Runtime;
use PHPUnit\Framework\TestCase;

/** Synthetic declared fields only; no catalogue mutation, provider calls or task writes. */
class MarketHistoryMixedFieldReplayTest extends TestCase
{
    private function snapshot(): array
    {
        $properties=['prompt'=>['type'=>'string'],'duration'=>['type'=>'integer','minimum'=>1,'maximum'=>8],
            'generate_audio'=>['type'=>'boolean','default'=>true],'seed'=>['type'=>'integer','default'=>19]];
        $fields=[];
        foreach(['image','video','audio']as$kind)foreach(['source','style']as$use){
            $name=$use.'_'.$kind.'_urls';$properties[$name]=['type'=>'array','items'=>['type'=>'string'],'minItems'=>1,'maxItems'=>1];
            $fields[]=['field'=>$name,'kind'=>$kind,'role'=>'reference','required'=>true,'minimum'=>1,'maximum'=>1];
        }
        return ['resource_type'=>'model','model_code'=>'synthetic-independent-fields','channel_code'=>'fixture','locked_params'=>[],
            'market_metadata'=>['params_schema'=>['type'=>'object','properties'=>$properties,'required'=>array_column($fields,'field')],
                'material_capabilities'=>['modes'=>[['code'=>'mixed','view_key'=>'mixed','method'=>'omni_reference','fields'=>$fields]]]]];
    }
    private function request(): array
    {
        $assets=[];foreach(['image','video','audio']as$kind)foreach(['source','style']as$use)$assets[]=[
            'type'=>$kind,'url'=>'https://fixtures.invalid/same.'.$kind,'role'=>'reference_'.$kind,
            'field'=>$use.'_'.$kind.'_urls','reference_slot_id'=>$use.':'.$kind];
        return ['prompt'=>'Synthetic independent mixed uses','duration'=>4,'generation_method'=>'omni_reference','material_mode'=>'mixed',
            'provider_params'=>['generate_audio'=>false,'seed'=>0],'reference_assets'=>$assets];
    }
    public function testJsonReopenedSnapshotPreservesEachIndependentFieldAndFalseZero(): void
    {
        $s=$this->snapshot();$r=$this->request();$original=$r;
        $a=Runtime::prepareRequest($s,$r);
        $b=Runtime::prepareRequest(json_decode(json_encode($s),true),json_decode(json_encode($r),true));
        self::assertSame($a->nativeInput,$b->nativeInput);self::assertSame($a->meterContext,$b->meterContext);
        foreach(['image','video','audio']as$kind){
            self::assertSame(['https://fixtures.invalid/same.'.$kind],$b->nativeInput['source_'.$kind.'_urls']);
            self::assertSame($b->nativeInput['source_'.$kind.'_urls'],$b->nativeInput['style_'.$kind.'_urls']);
        }
        self::assertFalse($b->nativeInput['generate_audio']);self::assertSame(0,$b->nativeInput['seed']);self::assertSame($original,$r);
    }
    public function testRemovingOneIndependentUseCannotBeFilledFromTheOtherField(): void
    {
        $r=$this->request();array_pop($r['reference_assets']);
        $this->expectException(\InvalidArgumentException::class);Runtime::prepareRequest($this->snapshot(),$r);
    }
    public function testEachFieldEnforcesItsOwnMaximumEvenWhenAnotherFieldHasRoom(): void
    {
        $r=$this->request();$asset=$r['reference_assets'][2];$asset['url']='https://fixtures.invalid/extra.video';$asset['reference_slot_id']='extra';$r['reference_assets'][]=$asset;
        $this->expectException(\InvalidArgumentException::class);Runtime::prepareRequest($this->snapshot(),$r);
    }
}
