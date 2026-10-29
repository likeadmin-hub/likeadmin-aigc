<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationContractReview as Review;
use app\common\service\power\MarketGenerationReviewedContract as Reviewed;
use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketGenerationNativeContract as Native;
use app\common\service\power\MarketGenerationAdapterRegistry as Registry;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;

class MarketGenerationReviewedContractTest extends TestCase
{
    private const ARTIFACT='Synthetic reviewed validator fixture; no provider calls';
    private const IMAGE='https://fixtures.invalid/reference.png';
    private string $catalogue;
    private $previous;

    protected function setUp(): void
    {
        $this->previous=getenv('MARKET_REVIEWED_GENERATION_CONTRACTS_PATH');
        $this->catalogue=tempnam(sys_get_temp_dir(),'reviewed-contract-');
        putenv('MARKET_REVIEWED_GENERATION_CONTRACTS_PATH='.$this->catalogue);
        $this->write(['version'=>2,'contracts'=>[]]);
    }
    protected function tearDown(): void
    {
        unlink($this->catalogue);
        putenv($this->previous===false ? 'MARKET_REVIEWED_GENERATION_CONTRACTS_PATH' : 'MARKET_REVIEWED_GENERATION_CONTRACTS_PATH='.$this->previous);
    }
    private function write(array $catalogue): void {file_put_contents($this->catalogue,json_encode($catalogue,JSON_THROW_ON_ERROR));}

    private function bundle(string $type='image',string $channel='fixture-channel'): array
    {
        $schema=['type'=>'object','properties'=>['prompt'=>['type'=>'string','minLength'=>1],
            'image_urls'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>2],
            'aspect_ratio'=>['type'=>'string','enum'=>['1:1']],
            'resolution'=>['type'=>'string','enum'=>['1k','2k']]],'required'=>['prompt']];
        if($type==='video')$schema['properties']['duration']=['type'=>'integer','minimum'=>4,'maximum'=>8];
        $text=$type==='image'?'text_to_image':'text_to_video';$reference=$type==='image'?'image_to_image':'reference_to_video';
        $raw=$schema['properties'];unset($raw['image_urls']['items'],$raw['image_urls']['maxItems']);
        return ['version'=>1,'source'=>['kind'=>'gateway_validator','reference'=>'fixture/validator.json','revision'=>'synthetic-v1',
            'reviewed_at'=>'2026-10-29T22:00:00+08:00','artifact_sha256'=>hash('sha256',self::ARTIFACT)],
            'entries'=>[['generation_type'=>$type,'model_code'=>'fixture-'.$type,'channel_code'=>$channel,'public_model_code'=>'fixture-'.$type,
                'generation_protocol'=>$type==='image'?'flat_image_v1':'flat_video_v1','revision'=>'synthetic-v1',
                'binding_declaration'=>['params_schema'=>$raw,'material_capabilities'=>[]],
                'declaration'=>['params_schema'=>$schema,'material_capabilities'=>['modes'=>[
                    ['code'=>$text,'view_key'=>'text','method'=>$text,'fields'=>[]],
                    ['code'=>$reference,'view_key'=>'reference','method'=>$type==='image'?'image_to_image':'image_reference',
                        'fields'=>[['field'=>'image_urls','kind'=>'image','role'=>'reference','required'=>true,'maximum'=>2]]]]]],
                'modes'=>[$text=>['schema'=>$schema,'rules'=>[]],$reference=>['schema'=>$schema,'rules'=>[]]]]]];
    }
    private function product(array $bundle): array
    {
        $e=$bundle['entries'][0];return ['resource_type'=>'model','upstream_model_code'=>$e['model_code'],
            'upstream_channel_code'=>$e['channel_code'],'market_metadata'=>$e['binding_declaration']+[
                'upstream_metadata'=>['model_code'=>$e['public_model_code'],'channel_code'=>$e['channel_code']]]];
    }
    private function frozen(array $bundle): array
    {
        $e=$bundle['entries'][0];$p=$this->product($bundle);
        return ['resource_type'=>'model','model_code'=>$e['model_code'],'channel_code'=>$e['channel_code'],'locked_params'=>[],
            'market_metadata'=>Reviewed::attachProduct($p,$p['market_metadata'],$e['generation_type'])];
    }

    public function testCompilerBindsRawDeclarationWithoutOverwritingItWithTheSupplement(): void
    {
        $b=$this->bundle();$catalogue=Review::reviewedCatalogue($b,self::ARTIFACT);$this->write($catalogue);
        $s=$this->frozen($b);$raw=$b['entries'][0]['binding_declaration']['params_schema'];
        self::assertSame($raw,$s['market_metadata']['params_schema']);
        self::assertArrayNotHasKey('items',$raw['image_urls']);
        self::assertNotEmpty(Native::modes($s['market_metadata']));
        self::assertSame('string',Native::schema($s['market_metadata'],'image_to_image')['properties']['image_urls']['items']['type']);
        self::assertSame('flat_image_v1',Registry::modelProtocol('image',$s));
        self::assertSame('synthetic-v1',Reviewed::selected($s['market_metadata'])['revision']);
        self::assertSame('unverified',Reviewed::selected($s['market_metadata'])['sku_pricing']);
        self::assertSame('unverified',Reviewed::selected($s['market_metadata'])['live_generation']);
    }

    public function testSameModelInTwoChannelsKeepsIndependentContracts(): void
    {
        $a=$this->bundle();$b=$this->bundle('image','other-channel');
        $b['entries'][0]['revision']='synthetic-other';
        $catalogue=Review::reviewedCatalogue($a,self::ARTIFACT);
        $catalogue['contracts'][]=Review::reviewedCatalogue($b,self::ARTIFACT)['contracts'][0];$this->write($catalogue);
        self::assertSame('synthetic-v1',Reviewed::selected($this->frozen($a)['market_metadata'])['revision']);
        self::assertSame('synthetic-other',Reviewed::selected($this->frozen($b)['market_metadata'])['revision']);
        $p=$this->product($a);$p['upstream_channel_code']='unregistered';
        self::assertArrayNotHasKey(Reviewed::PIN,Reviewed::attachProduct($p,$p['market_metadata'],'image'));
        self::assertArrayNotHasKey(Reviewed::PIN,Reviewed::attachProduct($this->product($a),$p['market_metadata'],'video'));
    }

    public function testFrozenRevisionSurvivesCatalogueReplacementAndAnOldSnapshotDoesNotAdoptIt(): void
    {
        $b=$this->bundle();$this->write(Review::reviewedCatalogue($b,self::ARTIFACT));$s=$this->frozen($b);
        $b['entries'][0]['revision']='synthetic-v2';$this->write(Review::reviewedCatalogue($b,self::ARTIFACT));
        self::assertSame('synthetic-v1',Reviewed::selected($s['market_metadata'])['revision']);
        self::assertSame('synthetic-v2',Reviewed::selected($this->frozen($b)['market_metadata'])['revision']);
        $this->write(['version'=>2,'contracts'=>[]]);
        self::assertSame('flat_image_v1',Registry::modelProtocol('image',$s));
        $old=$s;unset($old['market_metadata'][Reviewed::PIN]);
        self::assertSame($old['market_metadata'],Reviewed::attachProduct($old,$old['market_metadata'],'image'));
    }

    public function testMaterialContractDoesNotSwitchTheExistingFlatImageEncoder(): void
    {
        $b=$this->bundle();$this->write(Review::reviewedCatalogue($b,self::ARTIFACT));$s=$this->frozen($b);
        $request=['prompt'=>'fixture prompt','ratio'=>'1:1','quality'=>'1k','material_mode'=>'reference','reference_images'=>[self::IMAGE]];
        $first=Images::prepareRequest($s,$request);$second=Images::prepareRequest($s,$request);
        self::assertSame($first->nativeInput,$second->nativeInput);
        self::assertSame([self::IMAGE],$first->nativeInput['image_urls']);
        self::assertSame('fixture prompt',$first->nativeInput['prompt']);
        self::assertSame('fixture-image',$first->nativeInput['model']);
        self::assertSame('fixture-channel',$first->nativeInput['channel']);
    }

    /** @dataProvider rejectedImages */
    public function testReviewedPreparationPreservesMaterialSkuAndAdvancedParameterChecks(string $case): void
    {
        $b=$this->bundle();$this->write(Review::reviewedCatalogue($b,self::ARTIFACT));$s=$this->frozen($b);
        $r=['prompt'=>'fixture','ratio'=>'1:1','quality'=>'1k','material_mode'=>'reference','reference_images'=>[self::IMAGE]];
        switch($case){
            case 'over_limit':$r['reference_images']=array_fill(0,3,self::IMAGE);break;
            case 'missing_reference':$r['reference_images']=[];break;
            case 'wrong_mode':$r['material_mode']='text';break;
            case 'sku_mode':$s['locked_params']['generation_type']='text_to_image';break;
            case 'unknown_control':$r['provider_params']=['private_option'=>'do not silently discard'];break;
            case 'local_disable':$s['market_metadata']['_local_capability_overrides']=['supports_reference_images'=>false];break;
        }
        $this->expectException(\InvalidArgumentException::class);Images::prepareRequest($s,$r);
    }
    public static function rejectedImages(): array {return array_map(static fn($s)=>[$s],['over_limit','missing_reference','wrong_mode','sku_mode','unknown_control','local_disable']);}

    /** @dataProvider invalidBindings */
    public function testInvalidBindingsCannotFallBackToALegacyContract(string $kind): void
    {
        $b=$this->bundle();$catalogue=Review::reviewedCatalogue($b,self::ARTIFACT);$this->write($catalogue);$s=$this->frozen($b);
        switch($kind){
            case 'channel':$s['channel_code']='other';break;
            case 'type':$s['resource_type']='app_api';break;
            case 'raw':$s['market_metadata']['params_schema']['prompt']['type']='number';break;
            case 'digest':$s['market_metadata'][Reviewed::PIN]['revision']='changed';break;
            case 'public_alias':$s['market_metadata']['upstream_metadata']['model_code']='different';break;
            case 'metadata_channel':$s['market_metadata']['upstream_metadata']['channel_code']='different';break;
            case 'duplicate':$catalogue['contracts'][]=$catalogue['contracts'][0];$this->write($catalogue);$s=$this->frozen($b);break;
            case 'new_declaration':$p=$this->product($b);$p['market_metadata']['params_schema']['prompt']['type']='number';$s['market_metadata']=Reviewed::attachProduct($p,$p['market_metadata'],'image');break;
        }
        $this->expectException(\InvalidArgumentException::class);Registry::modelProtocol('image',$s);
    }
    public static function invalidBindings(): array {return array_map(static fn($s)=>[$s],['channel','type','raw','digest','public_alias','metadata_channel','duplicate','new_declaration']);}

    public function testNestedQwenEncoderIsNotReplacedByTheMaterialContract(): void
    {
        $b=$this->bundle();$e=&$b['entries'][0];
        $e['model_code']=$e['public_model_code']='qwen-image-3.0';$e['generation_protocol']='qwen_image_v1';
        $schema=['type'=>'object','properties'=>[
            'input'=>['type'=>'object','properties'=>['messages'=>['type'=>'array','minItems'=>1,'items'=>['type'=>'object',
                'properties'=>['role'=>['type'=>'string','const'=>'user'],'content'=>['type'=>'array','minItems'=>1,'items'=>[
                    'type'=>'object','properties'=>['text'=>['type'=>'string']],'required'=>['text']]]],'required'=>['role','content']]]],'required'=>['messages']],
            'parameters'=>['type'=>'object','properties'=>['n'=>['type'=>'integer','const'=>1],'size'=>['type'=>'string']]]],
            'required'=>['input']];
        $e['binding_declaration']=['params_schema'=>['input'=>['type'=>'object'],'parameters'=>['type'=>'object']],'material_capabilities'=>[]];
        $e['declaration']=['params_schema'=>$schema,'material_capabilities'=>['modes'=>[['code'=>'text_to_image','view_key'=>'text','method'=>'text_to_image','fields'=>[]]]]];
        $e['modes']=['text_to_image'=>['schema'=>$schema,'rules'=>[]]];unset($e);
        $this->write(Review::reviewedCatalogue($b,self::ARTIFACT));$s=$this->frozen($b);
        $r=['prompt'=>'fixture prompt','ratio'=>'1:1','quality'=>'1k','material_mode'=>'text'];
        $p=Images::prepareRequest($s,$r)->nativeInput;
        self::assertSame('qwen_image_v1',Registry::modelProtocol('image',$s));
        self::assertSame('fixture prompt',$p['input']['messages'][0]['content'][0]['text']);
        self::assertArrayNotHasKey('prompt',$p);
        $p['input']['messages'][0]['role']='invalid';
        $this->expectException(\InvalidArgumentException::class);Reviewed::validatePayload($s,$r,$p);
    }

    public function testUnsupportedMaterialPathCannotCompileBySkippingRoleValidation(): void
    {
        $b=$this->bundle();$b['entries'][0]['declaration']['material_capabilities']['modes'][1]['fields'][0]['field']='input.images';
        $this->expectException(\InvalidArgumentException::class);Review::reviewedCatalogue($b,self::ARTIFACT);
    }

    public function testMissingVersionCannotCompileAnActiveFormat(): void
    {
        $b=$this->bundle();unset($b['entries'][0]['revision']);
        $this->expectException(\InvalidArgumentException::class);Review::reviewedCatalogue($b,self::ARTIFACT);
    }

    public function testVideoKeepsItsFlatProtocolAndBusinessFields(): void
    {
        $b=$this->bundle('video');$this->write(Review::reviewedCatalogue($b,self::ARTIFACT));$s=$this->frozen($b);
        $r=['prompt'=>'fixture','ratio'=>'1:1','duration'=>4,'quality'=>'1k','material_mode'=>'text'];
        $prepared=Videos::prepareRequest($s,$r);
        self::assertSame('flat_video_v1',Registry::modelProtocol('video',$s));
        self::assertSame('fixture',$prepared->nativeInput['prompt']);self::assertSame(4,$prepared->nativeInput['duration']);
    }

    public function testBothActualSnapshotBuildersFreezeTheReviewedRevisionAndPrices(): void
    {
        foreach (['image'=>Images::class,'video'=>Videos::class] as $type=>$runtime) {
            $b=$this->bundle($type);$this->write(Review::reviewedCatalogue($b,self::ARTIFACT));
            $product=$this->product($b);$product['id']=990001;$product['source_payload']=['market_metadata'=>$product['market_metadata']];
            $product['upstream_app_code']='';$product['upstream_api_code']='';
            $market=['product'=>$product,'sku'=>['id'=>990002,'sku_key'=>'offline','locked_params'=>[],
                'usage_unit'=>'per_call','usage_unit_size'=>1,'upstream_price'=>1,'sale_points'=>2],'tenant_price'=>3,'reference_limit'=>2];
            $method=new \ReflectionMethod($runtime,'snapshot');
            $snapshot=$type==='image'?$method->invoke(null,$market):$method->invoke(null,$market,1);
            self::assertSame('synthetic-v1',Reviewed::assertSnapshot($snapshot,$type)['revision']);
            self::assertSame(2.0,$snapshot['platform_price']);self::assertSame(3.0,$snapshot['tenant_price']);
            self::assertSame($b['entries'][0]['binding_declaration']['params_schema'],$snapshot['market_metadata']['params_schema']);
        }
    }

    public function testDeferredPromptIsAllowedOnlyWhenTheBusinessEstimateOmittedIt(): void
    {
        $b=$this->bundle();$this->write(Review::reviewedCatalogue($b,self::ARTIFACT));$s=$this->frozen($b);
        $r=['ratio'=>'1:1','quality'=>'1k','material_mode'=>'text'];
        self::assertNotNull(Images::prepareRequest($s,$r,0,true));
        $r['prompt']='';$this->expectException(\InvalidArgumentException::class);Images::prepareRequest($s,$r,0,true);
    }
}
