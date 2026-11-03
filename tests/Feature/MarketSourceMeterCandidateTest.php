<?php
namespace Tests\Feature;

use app\common\service\power\{MarketGenerationMeter as Meter,MarketGenerationPixelMeter as Pixel,MarketImageModelRuntimeService as Images,MarketGenerationNativeContract as Native,MarketGenerationReviewedContract as Reviewed,MarketGenerationAdapterRegistry as Registry};
use app\common\model\power\{PowerMarketProduct,PowerMarketSku};
use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use think\facade\Db;
use PHPUnit\Framework\TestCase;

/** Saved source requests plus synthetic bounds and an explicit rollback-only admission fixture. No transport or storage. */
class MarketSourceMeterCandidateTest extends TestCase
{
    private static function source(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/fixtures/market-meter-source-candidates-20261101.json'),true,512,JSON_THROW_ON_ERROR);
    }

    private static function officialRows(): array
    {
        return array_values(array_filter(self::source()['rows'],static fn($row)=>$row['resource_type']==='model'));
    }

    /** The small bound is deliberately unrelated to source size estimates; it is not a real Qwen meter. */
    private static function syntheticMeter(): array
    {
        return Meter::contract(['usage_unit'=>'megapixel','source_payload'=>['meter_contract'=>[
            'version'=>3,'basis'=>'megapixel','rounding'=>'ceil','maximum_pixels_per_image'=>1024,
            'maximum_images'=>4,'result_path'=>'data.result.results','result_item_url'=>'url']]]);
    }

    private static function snapshot(array $row,int $reserved=1): array
    {
        return ['model_code'=>$row['model_code'],'channel_code'=>$row['channel_code'],'resource_type'=>'model','model_type'=>'image',
            'usage_unit'=>'megapixel','usage_unit_size'=>1,'billing_meter'=>self::syntheticMeter(),'reserved_image_quantity'=>$reserved,
            'market_metadata'=>self::savedMetadata($row),'locked_params'=>$row['saved_sku_binding']['locked_params']];
    }

    private static function savedMetadata(array $row): array
    {
        $binding=$row['saved_binding'];$metadata=$binding['metadata'];
        self::assertSame($binding['declaration_fingerprint'],Reviewed::declarationFingerprint($metadata),'Captured declaration must retain its exact saved fingerprint');
        // First verify the original capture, then remove only duplicated wrappers. Identity and materials stay intact.
        unset($metadata['upstream_metadata']['params_schema'],$metadata['upstream_metadata']['default_params']);
        self::assertSame($binding['declaration_fingerprint'],Reviewed::declarationFingerprint($metadata),'Compaction must not alter the binding');
        return $metadata;
    }

    private static function request(array $row,string $size='square',?string $count=null): array
    {
        $provider=['image_size'=>$size];
        if($row['material_mode']==='image_to_image')$provider['image_url']='https://fixtures.invalid/owned-reference.png';
        if($row['request_count_field']!==null&&$count!==null)$provider[$row['request_count_field']]=$count;
        $request=['prompt'=>'Synthetic source meter candidate','provider_params'=>$provider];
        if($row['saved_binding']['selected_native_mode']!=='')$request['generation_type']=$row['saved_binding']['selected_native_mode'];
        return $request;
    }

    private static function market(array $snapshot): array
    {
        $declaration=['version'=>3,'basis'=>'megapixel','rounding'=>'ceil','maximum_pixels_per_image'=>1024,
            'maximum_images'=>4,'result_path'=>'data.result.results','result_item_url'=>'url'];
        return ['product'=>['id'=>998801,'resource_type'=>'model','upstream_model_code'=>$snapshot['model_code'],
                'upstream_channel_code'=>$snapshot['channel_code'],'source_payload'=>['market_metadata'=>$snapshot['market_metadata']]],
            'sku'=>['id'=>998802,'sku_key'=>'offline-source-meter','locked_params'=>$snapshot['locked_params'],'usage_unit'=>'megapixel','usage_unit_size'=>1,
                'upstream_price'=>1,'sale_points'=>1,'source_payload'=>['meter_contract'=>$declaration]],'tenant_price'=>1,'reference_limit'=>1];
    }

    public function testEightSourceCandidatesRemainUnactivatedAndExactlyIdentified(): void
    {
        $source=self::source();self::assertSame('b5887e1c153111edd4515beb0635e6b66d9978b6',$source['source_commit']);
        self::assertSame([380,383,210,211,212,213,214,215],array_column($source['rows'],'sku_id'));
        foreach($source['rows']as$row){
            self::assertFalse($row['activation_authorized']);self::assertArrayNotHasKey('meter_contract',$row);
            self::assertSame($row['sku_id'],$row['saved_sku_binding']['id']);self::assertSame($row['product_id'],$row['saved_sku_binding']['product_id']);
            self::assertNull($row['saved_sku_binding']['saved_meter_contract']);
            if($row['resource_type']==='model'){
                self::assertSame('official-33-'.substr(hash('sha256',$row['source_model']),0,18),$row['model_code']);
                self::assertSame('saved_declaration_verified_unactivated',$row['local_metadata_binding']);
                self::assertSame($row['model_code'],$row['saved_binding']['product']['upstream_model_code']);
                self::assertSame($row['channel_code'],$row['saved_binding']['product']['upstream_channel_code']);
                self::assertSame($row['saved_binding']['original_saved_declaration_fingerprint'],$row['saved_binding']['declaration_fingerprint']);
                self::assertNull($row['maximum_billable_pixels_per_image']);self::assertNull($row['maximum_billable_images']);
                $legacy=Meter::contract(['usage_unit'=>'business_unit','meter_summary'=>'百万像素，总点数向上取整']);
                try{Meter::context($legacy,['image_size'=>'square_hd']);self::fail('A source size estimate cannot activate pixel pricing');}
                catch(\InvalidArgumentException $error){self::assertStringContainsString('缺少可核验的像素报价',$error->getMessage());}
            }else{
                self::assertSame('seedance',$row['app_code']);self::assertSame('create',$row['api_code']);self::assertNull($row['maximum_billable_tokens']);
                self::assertSame($row['resolution'],$row['saved_sku_binding']['locked_params']['resolution']);
                self::assertSame($row['material_mode'],$row['saved_sku_binding']['locked_params']['_pricing_variant']);
                self::assertSame('token',$row['saved_sku_binding']['usage_unit']);self::assertSame('1000000.000000',$row['saved_sku_binding']['usage_unit_size']);
                try{Meter::tokenContract(['usage_unit'=>'token','usage_unit_size'=>1000000,'source_payload'=>$row]);self::fail('A source estimate cannot become a SKU hard bound');}
                catch(\InvalidArgumentException $error){self::assertStringContainsString('Token 费用上限',$error->getMessage());}
            }
        }
    }

    public function testTheSixSizeLabelsRetainTheirFiniteSourceRequestMapping(): void
    {
        $map=self::source()['official_image_size_request_mapping'];self::assertCount(6,$map);
        self::assertSame([262144,1048576,786432,589824,786432,589824],array_map(static fn($size)=>$size[0]*$size[1],array_values($map)));
        foreach(self::officialRows()as$row)self::assertSame(max(array_map(static fn($size)=>$size[0]*$size[1],$map)),$row['maximum_request_pixels_per_image']);
    }

    /** @dataProvider sourceRequests */
    public function testFiniteSourceFieldsPrepareWithTheirNativeCountTypes(array $row,string $size,?string $count): void
    {
        $snapshot=self::snapshot($row,4);$request=self::request($row,$size,$count);$wire=$request;
        self::assertSame($row['saved_binding']['native_mode_codes'],array_keys(Native::modes($snapshot['market_metadata'])));
        self::assertSame($row['saved_binding']['effective_protocol'],Registry::modelProtocol('image',$snapshot));
        $schema=Native::schema($snapshot['market_metadata'],$row['saved_binding']['selected_native_mode']);
        self::assertSame(array_keys(self::source()['official_image_size_request_mapping']),$schema['properties']['image_size']['enum']);
        $native=Images::prepareRequest($snapshot,$request)->nativeInput;
        self::assertSame($size,$native['image_size']);self::assertSame($wire,$request);
        if($row['request_count_field']!==null){self::assertSame('string',$schema['properties'][$row['request_count_field']]['type']);self::assertSame($count,$native[$row['request_count_field']]);}
        else{self::assertArrayNotHasKey('n',$native);self::assertArrayNotHasKey('num_images',$native);}
        foreach(['maximum_billable_tokens','maximum_pixels_per_image','reserved_image_quantity']as$key)self::assertArrayNotHasKey($key,$native);
    }

    public static function sourceRequests(): array
    {
        $out=[];foreach(self::officialRows()as$row)foreach(array_keys(self::source()['official_image_size_request_mapping'])as$size)
            foreach($row['request_count_values']?:[null]as$count)$out[]=[$row,$size,$count];return $out;
    }

    public function testBusinessNDoesNotReplaceAnEncodedNumImagesOrInventAnUndeclaredCount(): void
    {
        foreach(self::officialRows()as$row){
            $request=self::request($row,'square',$row['request_count_field']!==null?'1':null);$request['n']=4;
            $native=Images::prepareRequest(self::snapshot($row),$request)->nativeInput;self::assertArrayNotHasKey('n',$native);
            if($row['request_count_field']!==null)self::assertSame('1',$native['num_images']);
            else self::assertArrayNotHasKey('num_images',$native);
        }
    }

    public function testUnlistedSizeAndCountCannotPassTheSavedNativeDeclaration(): void
    {
        $row=self::officialRows()[0];$snapshot=self::snapshot($row,4);$rejected=0;
        foreach([self::request($row,'auto','1'),self::request($row,'4K','1'),self::request($row,'square','5')]as$request){
            try{Images::prepareRequest($snapshot,$request);self::fail('Unlisted source size/count must reject before transport');}
            catch(\InvalidArgumentException $error){self::assertStringContainsString('参数选项不支持',$error->getMessage());$rejected++;}
        }
        self::assertSame(3,$rejected);
    }

    public function testSavedGroupModesDoNotExpandThePixelSkusSizeOrMaterialScope(): void
    {
        $row=self::officialRows()[1];$metadata=self::savedMetadata($row);
        self::assertSame('qwen-image',$metadata['upstream_metadata']['model_code']);
        self::assertSame(['generation_type'=>'text_to_image'],$row['saved_sku_binding']['locked_params']);
        self::assertArrayNotHasKey('image_size',Native::schema($metadata,'image_to_image')['properties']);
        $request=self::request($row);$request['generation_type']='image_to_image';$request['provider_params']['image_url']='https://fixtures.invalid/owned-reference.png';
        $this->expectExceptionMessage('当前 SKU 不支持所选生成方式');Images::prepareRequest(self::snapshot($row),$request);
    }

    public function testAChangedSavedDeclarationCannotReuseTheExistingFiniteNativeBinding(): void
    {
        foreach(self::officialRows()as$row){
            $metadata=self::savedMetadata($row);self::assertNotSame([],Native::modes($metadata));
            $changed=$metadata;$changed['params_schema']['image_size']['enum'][]='auto';
            self::assertNotSame($row['saved_binding']['declaration_fingerprint'],Reviewed::declarationFingerprint($changed));
            self::assertSame([],Native::modes($changed));
            self::assertSame($row['saved_binding']['native_mode_codes'],array_keys(Native::modes($metadata)));
        }
    }

    public function testAdmissionPreparationRejectsNativeCountAboveTheActualReservation(): void
    {
        $row=self::officialRows()[0];$snapshot=self::snapshot($row);$request=self::request($row,'square','4');
        $prepare=new \ReflectionMethod(Images::class,'meterMarket');
        try{$prepare->invoke(null,self::market($snapshot),$request,false,0,1);self::fail('Admission must reject before reserve/transport');}
        catch(\InvalidArgumentException $error){self::assertStringContainsString('超过冻结预占数量',$error->getMessage());}
        $prepared=$prepare->invoke(null,self::market($snapshot),$request,false,0,4);
        self::assertSame('4',Images::prepareRequest(self::snapshot($row,4),$request)->nativeInput['num_images']);
        self::assertSame('megapixel',$prepared['billing_meter']['basis']);
        $this->expectExceptionMessage('超过冻结预占数量');Images::prepareRequest($snapshot,$request);
    }

    /** @dataProvider invalidEncodedCounts */
    public function testEncodedCountCannotUseCoercionOrConflictingAliases(array $native): void
    {
        $this->expectException(\InvalidArgumentException::class);Pixel::assertRequestQuantity(self::syntheticMeter(),$native,4);
    }

    public static function invalidEncodedCounts(): array
    {
        return array_merge([[['n'=>1,'num_images'=>'2']]],array_map(static fn($value)=>[['num_images'=>$value]],[0,-1,1.5,true,null,'01','1e2','4suffix',[],5]));
    }

    public function testStructuredProtocolsReadParametersNInsteadOfATopLevelAlias(): void
    {
        foreach(['image_messages_v1','qwen_image_v1']as$protocol){
            Pixel::assertRequestQuantity(self::syntheticMeter(),['n'=>4,'parameters'=>['n'=>1]],1,$protocol);
            try{Pixel::assertRequestQuantity(self::syntheticMeter(),['n'=>1,'parameters'=>['n'=>4]],1,$protocol);self::fail('Nested encoded count cannot hide behind top-level n');}
            catch(\InvalidArgumentException $error){self::assertStringContainsString('超过冻结预占数量',$error->getMessage());}
        }
        $snapshot=['model_code'=>'offline-structured-count','channel_code'=>'fixture','generation_protocol'=>'image_messages_v1',
            'billing_meter'=>self::syntheticMeter(),'reserved_image_quantity'=>1,'market_metadata'=>['params_schema'=>[
                'input'=>['type'=>'object'],'parameters'=>['type'=>'object','properties'=>['n'=>['type'=>'integer','minimum'=>1,'maximum'=>4]]]]]];
        $request=['prompt'=>'Synthetic structured count','quantity'=>4,'n'=>1];
        $legacy=$snapshot;unset($legacy['billing_meter'],$legacy['reserved_image_quantity']);
        self::assertSame(4,Images::prepareRequest($legacy,$request)->nativeInput['parameters']['n']);
        $this->expectExceptionMessage('超过冻结预占数量');Images::prepareRequest($snapshot,$request);
    }

    public function testAnUnprovedNestedNativeCountCannotBecomeTheDefaultOne(): void
    {
        $snapshot=['model_code'=>'offline-nested-count','channel_code'=>'fixture','billing_meter'=>self::syntheticMeter(),
            'reserved_image_quantity'=>1,'market_metadata'=>[
                'params_schema'=>['prompt'=>['type'=>'string','required'=>true],
                    'options'=>['type'=>'object','properties'=>['num_images'=>['type'=>'integer','enum'=>[1,2,3,4]]]]],
                'material_capabilities'=>['modes'=>[['code'=>'text_to_image','method'=>'text_to_image','fields'=>[]]]]]];
        $this->expectExceptionMessage('嵌套原生请求尚不能核验图片数量');
        Images::prepareRequest($snapshot,['prompt'=>'Synthetic nested count','provider_params'=>['options'=>['num_images'=>4]]]);
    }

    public function testFrozenQuantityAndLegacyPreparationRemainIndependentOfNewCandidates(): void
    {
        $row=self::officialRows()[0];$snapshot=self::snapshot($row,4);$saved=json_decode(json_encode($snapshot),true);$request=self::request($row,'square','4');
        self::assertSame('4',Images::prepareRequest($saved,$request)->nativeInput['num_images']);self::assertSame($snapshot,$saved);
        $legacy=$snapshot;unset($legacy['billing_meter'],$legacy['reserved_image_quantity']);
        self::assertSame('4',Images::prepareRequest($legacy,$request)->nativeInput['num_images']);
        $newer=$snapshot;$newer['reserved_image_quantity']=1;
        try{Images::prepareRequest($newer,$request);self::fail('A lower frozen quantity must reject');}
        catch(\InvalidArgumentException $error){self::assertStringContainsString('超过冻结预占数量',$error->getMessage());}
        self::assertSame('4',Images::prepareRequest($saved,$request)->nativeInput['num_images']);self::assertSame($snapshot,$saved);
    }

    public function testLocalRollbackQuoteAndReserveRejectBeforeAnyOwnedLedgerIsCreated(): void
    {
        if(getenv('AIGC_VIDEO_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $user=(int)Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');
        if(!$user)self::markTestSkipped('Funded local rollback fixture unavailable');
        $key='source-count-'.bin2hex(random_bytes(8));$businessTable='offline_b79_quantity_guard';Db::startTrans();
        try{
            Db::name('tenant')->where('id',1)->lock(true)->find();Db::name('user')->where('id',$user)->lock(true)->find();
            $balances=static fn()=>[(float)Db::name('tenant')->where('id',1)->value('point_balance'),(float)Db::name('user')->where('id',$user)->value('user_money')];$before=$balances();
            $candidate=self::officialRows()[0];$market=self::market(self::snapshot($candidate));$product=$market['product'];unset($product['id']);
            $product=PowerMarketProduct::create($product+['product_code'=>$key,'name'=>'Offline source count guard','model_type'=>'image','source_code'=>'offline-fixture','upstream_resource_key'=>$key,'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $sku=$market['sku'];unset($sku['id']);$sku['sku_key']=$key;
            $sku=PowerMarketSku::create($sku+['product_id'=>$product['id'],'title'=>'Offline source count guard','sale_status'=>1,'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            \app\common\service\power\MarketPriceRuleService::manual(0,(int)$product['id'],(int)$sku['id'],1);
            \app\common\service\power\MarketPriceRuleService::manual(1,(int)$product['id'],(int)$sku['id'],1);
            $request=self::request($candidate,'square','4')+['model_id'=>'market_image_model:'.$product['id'],'market_sku_id'=>(int)$sku['id']];
            foreach(['quote','reserve']as$operation){
                try{
                    if($operation==='quote')Images::quote(1,$request,1);
                    else Images::reserve(1,$user,'generate',$key,$request,$request,1,'aigc_image',$businessTable);
                    self::fail('Over-reservation native count must fail before creating a ledger');
                }catch(\InvalidArgumentException $error){self::assertStringContainsString('超过冻结预占数量',$error->getMessage());}
                self::assertSame($before,$balances());
                self::assertSame(0,(int)AiConsumptionLog::where('product_id',$product['id'])->count());
                self::assertSame(0,(int)AiAppTask::where('business_table',$businessTable)->count());
            }
        }finally{Db::rollback();}
        self::assertSame(0,(int)PowerMarketProduct::where('product_code',$key)->count());
    }
}
