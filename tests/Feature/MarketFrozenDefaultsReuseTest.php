<?php
namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;

/** Frozen declarations only: no DB write, provider, task creation or billing. */
class MarketFrozenDefaultsReuseTest extends TestCase
{
    private function snapshot(bool $audio, int $seed): array
    {
        return ['resource_type'=>'model','model_code'=>'declared-frozen-fixture','channel_code'=>'fixture',
            'locked_params'=>[], 'billing_meter'=>null,
            'market_metadata'=>[
                'params_schema'=>['type'=>'object','properties'=>[
                    'prompt'=>['type'=>'string'], 'duration'=>['type'=>'integer','minimum'=>1,'maximum'=>8],
                    'generate_audio'=>['type'=>'boolean','default'=>$audio], 'seed'=>['type'=>'integer','default'=>$seed],
                    'image_urls'=>['type'=>'array','items'=>['type'=>'string'],'minItems'=>2,'maxItems'=>2],
                ],'required'=>['prompt','duration','image_urls']],
                'material_capabilities'=>['modes'=>[['code'=>'multi_frame','method'=>'multi_frame','fields'=>[
                    ['field'=>'image_urls','kind'=>'image','role'=>'reference','required'=>true,'minimum'=>2,'maximum'=>2],
                ]]]],
            ]];
    }

    private function request(): array
    {
        return ['prompt'=>'Synthetic reused frames','duration'=>4,'generation_method'=>'multi_frame',
            'reference_assets'=>array_map(static fn(string $slot):array=>[
                'type'=>'image','url'=>'https://fixtures.invalid/same.png','uri'=>'https://fixtures.invalid/same.png',
                'role'=>'reference_image','reference_slot_id'=>$slot,
            ],['node:left','node:right'])];
    }

    public function testFrozenDefaultsSurviveCatalogueReplacementAndJsonReopen(): void
    {
        foreach ([false,true] as $oldAudio) {
            $old=$this->snapshot($oldAudio,0);
            $frozen=json_decode(json_encode($old,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
            $request=$this->request();
            $before=MarketVideoRuntimeService::prepareRequest($frozen,$request);
            $current=$this->snapshot(!$oldAudio,19);
            $new=MarketVideoRuntimeService::prepareRequest($current,$request);
            $after=MarketVideoRuntimeService::prepareRequest(json_decode(json_encode($frozen),true),json_decode(json_encode($request),true));
            self::assertSame($oldAudio,$before->nativeInput['generate_audio']);
            self::assertSame(0,$before->nativeInput['seed']);
            self::assertSame(!$oldAudio,$new->nativeInput['generate_audio']);
            self::assertSame(19,$new->nativeInput['seed']);
            self::assertSame($before->nativeInput,$after->nativeInput);
            self::assertSame($before->meterContext,$after->meterContext);
            self::assertCount(2,$after->nativeInput['image_urls']);
            self::assertSame($after->nativeInput['image_urls'][0],$after->nativeInput['image_urls'][1]);
            self::assertArrayNotHasKey('provider_params',$request,'Omitted controls must stay omitted in saved audit input');
            self::assertSame($old,$frozen);
        }
    }

    public function testExplicitFalseAndZeroOverrideDefaultsWithoutMutatingTheSavedRequest(): void
    {
        $request=$this->request()+['provider_params'=>['generate_audio'=>false,'seed'=>0]];
        $original=$request;
        $prepared=MarketVideoRuntimeService::prepareRequest($this->snapshot(true,19),$request);
        self::assertFalse($prepared->nativeInput['generate_audio']);
        self::assertSame(0,$prepared->nativeInput['seed']);
        self::assertSame($original,$request);
        unset($request['reference_assets'][1]);
        $this->expectException(\InvalidArgumentException::class);
        MarketVideoRuntimeService::prepareRequest($this->snapshot(true,19),$request);
    }
}
