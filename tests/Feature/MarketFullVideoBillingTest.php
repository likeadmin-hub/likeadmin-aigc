<?php
namespace Tests\Feature;

use app\common\service\power\{MarketFullVideoBilling as Fees,MarketGenerationApplicationProtocol as Protocol,MarketGenerationReviewedContract as Digest};
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Explicit synthetic local fee decisions; no active product, configuration, wallet, provider or DB access. */
class MarketFullVideoBillingTest extends TestCase
{
    private static function snapshot(): array
    {
        $data=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/market-application-source-contracts-20261101.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($data['entries']as$entry)if($entry['product']['upstream_app_code']==='full_video')return Protocol::bindNewSnapshot([
            'resource_type'=>'app_api','app_code'=>'full_video','api_code'=>'submit','sku_id'=>$entry['skus'][0]['id'],
            'locked_params'=>$entry['skus'][0]['locked_params'],'usage_unit'=>'input_second','market_metadata'=>$entry['metadata']]);
        throw new \LogicException('Missing captured Full Video declaration');
    }

    private static function sign(array $terms): array
    {
        unset($terms['terms_digest']);$terms['terms_digest']=Digest::digest($terms);return $terms;
    }

    private static function terms(): array
    {
        $snapshot=self::snapshot();$metadata=$snapshot['market_metadata'];
        $source=['reference_image_free_count'=>5,'reference_image_overage_cost_points'=>20,'reference_image_overage_sale_points'=>20];
        return self::sign(['version'=>1,'status'=>'reviewed','binding'=>['app_code'=>'full_video','api_code'=>'submit',
            'upstream_app_id'=>24,'upstream_api_id'=>73,'schema_digest'=>Digest::digest(['value'=>$metadata['params_schema']]),
            'defaults_digest'=>Digest::digest($metadata['default_params']),'source_revision'=>'b5887e1c153111edd4515beb0635e6b66d9978b6','config_digest'=>Digest::digest($source)],
            'source_config'=>$source,'meter'=>['basis'=>'request_duration','free_reference_images'=>5,'maximum_reference_images'=>9,'slot_type'=>'image_url','slot_role'=>'reference_image'],
            'layers'=>['api_to_aigc'=>['api_points_per_image'=>'20.000000','conversion'=>['numerator'=>1,'denominator'=>1]],
                'platform_to_tenant'=>['points_per_image'=>'23.123456'],'tenant_to_user'=>['points_per_image'=>'27.654321']],
            'rounding'=>'half_up_6']);
    }

    private static function native(int $images=6): array
    {
        $content=[['type'=>'text','text'=>' 一 '],['type'=>'text','text'=>' 二 ']];
        for($i=0;$i<$images;$i++)$content[]=['type'=>'image_url','role'=>'reference_image','image_url'=>['url'=>'https://fixtures.invalid/same.png']];
        return ['duration'=>4,'resolution'=>'480P','aigc_watermark'=>false,'content'=>$content];
    }

    private static function rates(): array
    {
        return ['api_unit_points'=>'1.111111','tenant_unit_points'=>'2.222222','user_unit_points'=>'3.333333','usage_unit_size'=>'1'];
    }

    public function testThreeExplicitLayersFreezeSeparateAmountsAndCountRepeatedNativeSlots(): void
    {
        $terms=self::terms();$native=self::native();$before=$native;
        $fees=Fees::fromTrustedMarketConfiguration(self::snapshot(),$terms);$frozen=$fees->freeze(self::snapshot(),$native,self::rates());
        self::assertSame(['request_duration'=>4,'reference_images'=>6,'excess_reference_images'=>1],$frozen['context']);
        self::assertSame(['api'=>'24.444444','tenant'=>'32.012344','user'=>'40.987653'],$frozen['amounts']);
        Fees::assertPrepared($frozen,$native);self::assertSame($before,$native);
        self::assertSame($frozen['amounts'],Fees::settledAmounts($frozen,['duration'=>900,'usage'=>['total_tokens'=>999999999],'full_video_fee_contract'=>self::terms()]));
        self::assertSame(['api'=>'0.000000','tenant'=>'0.000000','user'=>'0.000000'],Fees::settledAmounts($frozen,[],true));
    }

    public function testFramesDoNotBecomeReferenceImageSurcharges(): void
    {
        $native=self::native(0);
        foreach(['first_frame','last_frame']as$role)$native['content'][]=['type'=>'image_url','role'=>$role,'image_url'=>['url'=>'https://fixtures.invalid/frame.png']];
        $frozen=Fees::fromTrustedMarketConfiguration(self::snapshot(),self::terms())->freeze(self::snapshot(),$native,self::rates());
        self::assertSame(0,$frozen['context']['reference_images']);self::assertSame('8.888888',$frozen['amounts']['tenant']);
    }

    public function testConversionAndRoundingAreExplicitAndExactAtHalfAMicroPoint(): void
    {
        $terms=self::terms();$terms['layers']['api_to_aigc']['conversion']=['numerator'=>1,'denominator'=>8];$terms=self::sign($terms);
        $rates=self::rates();$rates['api_unit_points']='0.000001';$rates['tenant_unit_points']='0';$rates['user_unit_points']='0';
        $frozen=Fees::fromTrustedMarketConfiguration(self::snapshot(),$terms)->freeze(self::snapshot(),self::native(0),$rates);
        self::assertSame('0.000001',$frozen['amounts']['api']);self::assertSame('0.000000',$frozen['amounts']['tenant']);
    }

    public static function invalidTerms(): array
    {
        return array_map(static fn($case)=>[$case],['inactive','missing_user','missing_tenant','conversion','api_price','digest','identity','maximum','precision','negative']);
    }

    /** @dataProvider invalidTerms */
    public function testMissingDecisionsOrUnverifiedTermsNeverEnableBilling(string $case): void
    {
        $terms=self::terms();
        if($case==='inactive')$terms['status']='inactive';
        elseif($case==='missing_user')$terms['layers']['tenant_to_user']['points_per_image']=null;
        elseif($case==='missing_tenant')$terms['layers']['platform_to_tenant']['points_per_image']=null;
        elseif($case==='conversion')$terms['layers']['api_to_aigc']['conversion']['denominator']=null;
        elseif($case==='api_price')$terms['layers']['api_to_aigc']['api_points_per_image']='21';
        elseif($case==='identity')$terms['binding']['upstream_api_id']=34;
        elseif($case==='maximum')$terms['meter']['maximum_reference_images']=10;
        elseif($case==='precision')$terms['layers']['tenant_to_user']['points_per_image']='1.0000001';
        elseif($case==='negative')$terms['layers']['tenant_to_user']['points_per_image']='-1';
        $terms=self::sign($terms);if($case==='digest')$terms['terms_digest']=str_repeat('0',64);
        $this->expectException(InvalidArgumentException::class);Fees::fromTrustedMarketConfiguration(self::snapshot(),$terms);
    }

    public static function invalidNative(): array
    {
        return array_map(static fn($case)=>[$case],['injected','wrapped','fractional','missing_duration','ten_images','mixed_frames','audio_only','wrong_role','wrong_type','empty_text','remote_watermark','remote_text']);
    }

    /** @dataProvider invalidNative */
    public function testOnlyTheActualBoundNativeShapeCanBeMetered(string $case): void
    {
        $native=self::native();
        if($case==='injected')$native['full_video_fee_contract']=self::terms();
        elseif($case==='wrapped')$native['parameters']=['duration'=>15];
        elseif($case==='fractional')$native['duration']=4.5;
        elseif($case==='missing_duration')unset($native['duration']);
        elseif($case==='ten_images')$native=self::native(10);
        elseif($case==='mixed_frames')$native['content'][]=['type'=>'image_url','role'=>'first_frame','image_url'=>['url'=>'https://fixtures.invalid/f.png']];
        elseif($case==='audio_only')$native['content']=[['type'=>'text','text'=>'Only audio'],['type'=>'audio_url','role'=>'reference_audio','audio_url'=>['url'=>'https://fixtures.invalid/a.wav']]];
        elseif($case==='wrong_role')$native['content'][2]['role']='unknown';
        elseif($case==='wrong_type')$native['content'][2]['type']=[];
        elseif($case==='empty_text')$native['content'][0]['text']=' ';
        elseif($case==='remote_watermark'){$native['resolution']='1080P';$native['aigc_watermark']=true;}
        elseif($case==='remote_text'){$native['resolution']='2K';$native['content'][0]['text']=str_repeat('字',5000);$native['content'][1]['text']='再一字';}
        $fees=Fees::fromTrustedMarketConfiguration(self::snapshot(),self::terms());
        $this->expectException(InvalidArgumentException::class);$fees->freeze(self::snapshot(),$native,self::rates());
    }

    public function testFrozenCountDurationTermsAndAmountsCannotBeChangedDuringReplay(): void
    {
        $fees=Fees::fromTrustedMarketConfiguration(self::snapshot(),self::terms());$native=self::native();$frozen=$fees->freeze(self::snapshot(),$native,self::rates());$rejected=0;
        foreach(['duration','count','terms','amount']as$case){
            try{
                if($case==='duration'){$copy=$native;$copy['duration']=5;Fees::assertPrepared($frozen,$copy);}
                elseif($case==='count'){Fees::assertPrepared($frozen,self::native(7));}
                else{$copy=$frozen;if($case==='terms')$copy['terms']['layers']['tenant_to_user']['points_per_image']='0';else$copy['amounts']['user']='0.000000';Fees::settledAmounts($copy);}
            }catch(InvalidArgumentException$error){$rejected++;}
        }
        self::assertSame(4,$rejected);
    }

    public function testOverflowCannotBecomeAQuoteAndLegacySnapshotsAreNeverImplicitlyUpgraded(): void
    {
        $fees=Fees::fromTrustedMarketConfiguration(self::snapshot(),self::terms());$rates=self::rates();$rates['user_unit_points']='9999999999';$rejected=0;
        try{$fees->freeze(self::snapshot(),self::native(),$rates);}catch(InvalidArgumentException$error){$rejected++;}
        $legacy=self::snapshot();unset($legacy['application_contract']);
        try{Fees::fromTrustedMarketConfiguration($legacy,self::terms());}catch(InvalidArgumentException$error){$rejected++;}
        self::assertSame(2,$rejected);self::assertArrayNotHasKey('application_contract',$legacy);
    }

    public static function rehashedDamage(): array
    {
        return array_map(static fn($case)=>[$case],['negative_rate','precision','missing_rate','one_second','negative_refs','fraction_refs','excess','marker_version','marker_profile','marker_digest','terms_identity']);
    }

    /** @dataProvider rehashedDamage */
    public function testRecomputingADigestDoesNotMakeAnInvalidFrozenRecordBillable(string $case): void
    {
        $frozen=Fees::fromTrustedMarketConfiguration(self::snapshot(),self::terms())->freeze(self::snapshot(),self::native(),self::rates());
        if($case==='negative_rate')$frozen['rates']['user_unit_points']='-1';
        elseif($case==='precision')$frozen['rates']['user_unit_points']='0.0000001';
        elseif($case==='missing_rate')unset($frozen['rates']['api_unit_points']);
        elseif($case==='one_second')$frozen['context']['request_duration']=1;
        elseif($case==='negative_refs')$frozen['context']['reference_images']=-1;
        elseif($case==='fraction_refs')$frozen['context']['reference_images']=6.5;
        elseif($case==='excess')$frozen['context']['excess_reference_images']=0;
        elseif($case==='marker_version')$frozen['application_contract']['version']=2;
        elseif($case==='marker_profile')$frozen['application_contract']['profile']='another_app';
        elseif($case==='marker_digest')$frozen['application_contract']['binding_digest']='invalid';
        else{$frozen['terms']['binding']['upstream_api_id']=34;$frozen['terms']=self::sign($frozen['terms']);}
        unset($frozen['freeze_digest']);$frozen['freeze_digest']=Digest::digest($frozen);
        $this->expectException(InvalidArgumentException::class);Fees::settledAmounts($frozen);
    }
}
