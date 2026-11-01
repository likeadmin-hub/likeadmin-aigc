<?php
namespace Tests\Feature;

use app\common\service\app\UpstreamPricingService as Upstream;
use app\common\service\power\PowerMarketService as Market;
use PHPUnit\Framework\TestCase;
use Tests\Support\MarketDocumentHttpFixture as Wire;

/**
 * Real client/resolver/merge methods with synthetic GETs; no runtime initialization or DB/network.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MarketDeveloperDocumentLookupTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__).'/Support/MarketDocumentHttpFixture.php';
        $source='app\\common\\service\\update\\UpdateSourceClient';
        self::assertFalse(class_exists($source,false),'Real source configuration must never load in this test');
        class_alias(\Tests\Support\MarketDocumentSourceFixture::class,$source);
        Wire::$responses=[];Wire::$requests=[];Wire::$violations=[];
    }

    protected function tearDown(): void
    {
        // Production deliberately catches metadata failures. A swallowed unexpected test request must still fail.
        self::assertSame([],Wire::$violations);
    }

    private static function model(int $id=99,string $code='fixture-model'): array
    {
        return ['model_id'=>$id,'model_code'=>$code,'channel_code'=>'fixture-channel'];
    }

    private static function modelDetail(int $id=99,string $code='fixture-model'): array
    {
        return ['doc'=>['ai_model_id'=>$id,'ai_app_api_id'=>null,'slug'=>'shared-model-doc','content'=>'Synthetic model documentation'],
            'api_pricing'=>null,'model_pricing'=>['bind_type'=>'model','model_id'=>$id,'model_code'=>$code,
                'params_schema'=>['prompt'=>['type'=>'string','required'=>'1']],'default_params'=>['duration'=>5]]];
    }

    private static function apiDetail(int $id=37,string $app='fixture_app',string $api='create'): array
    {
        return ['doc'=>['ai_app_api_id'=>$id,'ai_model_id'=>null,'slug'=>'shared-api-doc','content'=>'Synthetic API documentation'],
            'model_pricing'=>null,'api_pricing'=>['id'=>$id,'app_code'=>$app,'code'=>$api,
                'params_schema'=>['prompt'=>['type'=>'string']],'default_params'=>['duration'=>6]]];
    }

    private static function lookupModel(array $model,?string &$strategy=null): array
    {
        return (new \ReflectionMethod(Market::class,'developerDocDetailForModel'))->invokeArgs(null,[$model,&$strategy]);
    }

    private static function lookupApi(string $app='fixture_app',string $api='create',$id=37,?string &$strategy=null): array
    {
        return (new \ReflectionMethod(Market::class,'developerDocDetailForAppApi'))->invokeArgs(null,[$app,$api,$id,&$strategy]);
    }

    public function testIdClientsSendOnlyTheExactReadOnlySelector(): void
    {
        Wire::$responses=['model_id:99'=>self::modelDetail(),'app_api_id:37'=>self::apiDetail()];
        self::assertSame(self::modelDetail(),Upstream::queryDeveloperDocDetailByModelId('99'));
        self::assertSame(self::apiDetail(),Upstream::queryDeveloperDocDetailByAppApiId(37));
        self::assertSame(['model_id:99','app_api_id:37'],Wire::$requests);
    }

    /** @dataProvider invalidIds */
    public function testIdClientsRejectCoercibleOrEmptyIdsBeforeReadingConfiguration($id): void
    {
        foreach (['queryDeveloperDocDetailByModelId','queryDeveloperDocDetailByAppApiId'] as $method) {
            try { Upstream::$method($id);self::fail('Invalid document ID was accepted'); }
            catch (\InvalidArgumentException $expected) { self::assertSame([],Wire::$requests); }
        }
    }

    public static function invalidIds(): array
    {
        return array_map(static fn($id)=>[$id],[0,-1,'','0','01','99suffix','99.0',99.5,true,false,null,[], '999999999999999999999999']);
    }

    public function testModelIdSuccessSkipsTheTreeAndMergesOnlyTheBoundDeclaration(): void
    {
        $detail=self::modelDetail();Wire::$responses=['model_id:99'=>$detail];
        self::assertSame($detail,self::lookupModel(self::model(),$strategy));
        self::assertSame('model_id',$strategy);
        $merged=(new \ReflectionMethod(Market::class,'mergeDeveloperDocModelDetail'))->invoke(null,self::model());
        self::assertSame($detail['model_pricing']['params_schema'],$merged['params_schema']);
        self::assertSame($detail['model_pricing']['default_params'],$merged['default_params']);
        self::assertSame($detail['doc']['content'],$merged['developer_doc_content']);
        self::assertArrayNotHasKey('generation_contract',$merged);
        self::assertSame(['model_id:99'],Wire::$requests);
    }

    public function testLocalProductIdCannotSupplyAMissingModelIdentity(): void
    {
        $legacy=['id'=>99,'model_code'=>'fixture-model','channel_code'=>'fixture-channel'];
        self::assertSame([],self::lookupModel($legacy,$strategy));
        self::assertSame('missing_model_document_binding',$strategy);
        self::assertSame([],Wire::$requests);
    }

    public function testModelIdFailureKeepsTheExistingVerifiedTreeSlugFallback(): void
    {
        Wire::$responses=['model_id:99'=>new \RuntimeException('Synthetic metadata unavailable'),
            'tree'=>[['ai_model_id'=>99,'slug'=>'legacy-model-doc']], 'slug:legacy-model-doc'=>self::modelDetail()];
        self::assertSame(self::modelDetail(),self::lookupModel(self::model(),$strategy));
        self::assertSame('document_tree_after_model_id',$strategy);
        self::assertSame(['model_id:99','tree','slug:legacy-model-doc'],Wire::$requests);
    }

    /** @dataProvider mismatchedModels */
    public function testWrongModelDocumentsNeverEnterTheMergeOrCache(array $detail): void
    {
        Wire::$responses=['model_id:99'=>$detail,'tree'=>[['ai_model_id'=>99,'slug'=>'shared-model-doc']],
            'slug:shared-model-doc'=>$detail];
        self::assertSame([],self::lookupModel(self::model(),$strategy));
        self::assertSame('model_identity_mismatch',$strategy);
        $model=self::model();
        self::assertSame($model,(new \ReflectionMethod(Market::class,'mergeDeveloperDocModelDetail'))->invoke(null,$model));
        self::assertSame(['model_id:99','tree','slug:shared-model-doc'],Wire::$requests);
    }

    public static function mismatchedModels(): array
    {
        $detail=self::modelDetail();$wrongDoc=$detail;$wrongDoc['doc']['ai_model_id']=100;
        $wrongType=$detail;$wrongType['model_pricing']['bind_type']='app_api';
        $mixed=$detail;$mixed['api_pricing']=self::apiDetail()['api_pricing'];
        $emptyId=$detail;$emptyId['model_pricing']['model_id']=null;
        $wrongChannel=$detail;$wrongChannel['model_pricing']['channel_code']='another-channel';
        $wrongPricingId=$detail;$wrongPricingId['model_pricing']['pricing_v2']=['resource_type'=>'model','resource_id'=>100];
        $wrongPricingType=$detail;$wrongPricingType['model_pricing']['pricing_v2']=['resource_type'=>'app_api','resource_id'=>99];
        return [[self::modelDetail(100)],[self::modelDetail(99,'another-model')],[$wrongDoc],[$wrongType],[$mixed],[$emptyId],
            [$wrongChannel],[$wrongPricingId],[$wrongPricingType],[self::apiDetail()]];
    }

    public function testModelCacheSeparatesIdsCodesAndChannelsEvenWhenSlugsCollide(): void
    {
        Wire::$responses=['model_id:99'=>self::modelDetail(),
            'model_id:100'=>new \RuntimeException('Synthetic metadata unavailable'),
            'tree'=>[['ai_model_id'=>99,'slug'=>'shared-model-doc'],['ai_model_id'=>100,'slug'=>'shared-model-doc']],
            'slug:shared-model-doc'=>self::modelDetail()];
        self::assertSame(self::modelDetail(),self::lookupModel(self::model()));
        self::assertSame([],self::lookupModel(self::model(100)));
        self::assertSame([],self::lookupModel(self::model(99,'another-model')));
        $otherChannel=self::model();$otherChannel['channel_code']='another-channel';
        self::assertSame(self::modelDetail(),self::lookupModel($otherChannel));
        self::assertSame(['model_id:99','model_id:100','tree','slug:shared-model-doc',
            'model_id:99','slug:shared-model-doc','model_id:99'],Wire::$requests);
    }

    public function testAConflictingModelIdentityCannotReuseAnOtherwiseMatchingCacheEntry(): void
    {
        Wire::$responses=['model_id:99'=>self::modelDetail()];
        self::assertSame(self::modelDetail(),self::lookupModel(self::model()));
        $conflict=self::model();$conflict['ai_model_id']=99;$conflict['model_id']=100;
        self::assertSame([],self::lookupModel($conflict));
        self::assertSame(['model_id:99'],Wire::$requests);
    }

    public function testConflictingOrCrossTypeInputCannotPoisonTheLaterValidModelCache(): void
    {
        Wire::$responses=['model_id:99'=>self::modelDetail()];
        $conflict=self::model();$conflict['ai_model_id']=99;$conflict['model_id']=100;
        $crossType=self::model();$crossType['resource_type']='app_api';
        foreach ([$conflict,$crossType] as $invalid) {
            self::assertSame([],self::lookupModel($invalid,$strategy));
            self::assertSame('conflicting_model_document_binding',$strategy);
        }
        self::assertSame([],Wire::$requests);
        self::assertSame(self::modelDetail(),self::lookupModel(self::model(),$strategy));
        self::assertSame('model_id',$strategy);
        $merged=(new \ReflectionMethod(Market::class,'mergeDeveloperDocModelDetail'))->invoke(null,self::model());
        self::assertSame(self::modelDetail()['model_pricing']['params_schema'],$merged['params_schema']);
        self::assertSame(['model_id:99'],Wire::$requests);
    }

    public function testApplicationIdSuccessSkipsTheTreeAndMergesItsOwnApiFields(): void
    {
        Wire::$responses=['app_api_id:37'=>self::apiDetail()];
        self::assertSame(self::apiDetail(),self::lookupApi('fixture_app','create',37,$strategy));
        self::assertSame('app_api_id',$strategy);
        $api=['id'=>37,'code'=>'create'];
        $merged=(new \ReflectionMethod(Market::class,'mergeDeveloperDocAppApiDetail'))->invoke(null,['code'=>'fixture_app'],$api);
        self::assertSame(self::apiDetail()['api_pricing']['params_schema'],$merged['params_schema']);
        self::assertSame(self::apiDetail()['doc']['content'],$merged['developer_doc_content']);
        self::assertSame(['app_api_id:37'],Wire::$requests);
    }

    public function testApplicationWithoutAnIdKeepsTheLegacySlugAndCodeBinding(): void
    {
        $detail=self::apiDetail();unset($detail['api_pricing']['id'],$detail['doc']['ai_app_api_id']);
        Wire::$responses=['slug:fixture-app-create'=>$detail];
        self::assertSame($detail,self::lookupApi('fixture_app','create',0,$strategy));
        self::assertSame('api_slug',$strategy);
        $merged=(new \ReflectionMethod(Market::class,'mergeDeveloperDocAppApiDetail'))->invoke(null,['code'=>'fixture_app'],['code'=>'create']);
        self::assertSame($detail['api_pricing']['params_schema'],$merged['params_schema']);
        self::assertSame(['slug:fixture-app-create'],Wire::$requests);
    }

    public function testApplicationIdFailureCanUseItsBoundTreeSlug(): void
    {
        Wire::$responses=['app_api_id:37'=>[], 'tree'=>[['ai_app_api_id'=>37,'slug'=>'legacy-api-doc']],
            'slug:legacy-api-doc'=>self::apiDetail()];
        self::assertSame(self::apiDetail(),self::lookupApi('fixture_app','create',37,$strategy));
        self::assertSame('document_tree_after_app_api_id',$strategy);
        self::assertSame(['app_api_id:37','tree','slug:legacy-api-doc'],Wire::$requests);
    }

    /** @dataProvider mismatchedApplications */
    public function testWrongApplicationDocumentsCannotBeMergedOrBorrowAModelId(array $detail): void
    {
        Wire::$responses=['app_api_id:37'=>$detail,'tree'=>[], 'slug:fixture-app-create'=>$detail];
        self::assertSame([],self::lookupApi('fixture_app','create',37,$strategy));
        self::assertSame('application_identity_mismatch',$strategy);
        $api=['id'=>37,'code'=>'create'];
        self::assertSame($api,(new \ReflectionMethod(Market::class,'mergeDeveloperDocAppApiDetail'))->invoke(null,['code'=>'fixture_app'],$api));
        self::assertSame(['app_api_id:37','tree','slug:fixture-app-create'],Wire::$requests);
    }

    public static function mismatchedApplications(): array
    {
        $detail=self::apiDetail();$wrongDoc=$detail;$wrongDoc['doc']['ai_app_api_id']=38;
        $wrongType=$detail;$wrongType['api_pricing']['bind_type']='model';
        $mixed=$detail;$mixed['model_pricing']=self::modelDetail()['model_pricing'];
        $emptyId=$detail;$emptyId['api_pricing']['id']=null;
        $conflict=$detail;$conflict['api_pricing']['api_id']=38;
        $wrongPricingType=$detail;$wrongPricingType['api_pricing']['pricing_v2']=['resource_type'=>'model','resource_id'=>37];
        $wrongPricingId=$detail;$wrongPricingId['api_pricing']['pricing_v2']=['resource_type'=>'app_api','resource_id'=>38];
        return [[self::apiDetail(38)],[self::apiDetail(37,'other_app')],[self::apiDetail(37,'fixture_app','query')],
            [$wrongDoc],[$wrongType],[$mixed],[$emptyId],[$conflict],[$wrongPricingType],[$wrongPricingId],[self::modelDetail()]];
    }

    public function testApplicationCacheSeparatesCodesIdsAndModelResourceType(): void
    {
        Wire::$responses=['app_api_id:37'=>self::apiDetail(),'app_api_id:38'=>self::apiDetail(),
            'model_id:37'=>self::modelDetail(37),'tree'=>[], 'slug:fixture-app-create'=>self::apiDetail(),
            'slug:other-app-create'=>self::apiDetail()];
        self::assertSame(self::apiDetail(),self::lookupApi());
        self::assertSame([],self::lookupApi('fixture_app','create',38));
        self::assertSame([],self::lookupApi('other_app','create',37));
        self::assertSame(self::modelDetail(37),self::lookupModel(self::model(37)));
        self::assertSame(['app_api_id:37','app_api_id:38','tree','slug:fixture-app-create',
            'app_api_id:37','slug:other-app-create','model_id:37'],Wire::$requests);
    }

    public function testSlugCacheCannotCollideForDifferentApplicationCodeSpellings(): void
    {
        Wire::$responses=['slug:fixture-app-create'=>self::apiDetail()];
        self::assertSame(self::apiDetail(),self::lookupApi('fixture_app','create',0));
        self::assertSame([],self::lookupApi('fixture-app','create',0));
        self::assertSame(['slug:fixture-app-create','slug:fixture-app-create'],Wire::$requests);
    }

    public function testMalformedOrConflictingApiIdsDoNotStartALegacyLookup(): void
    {
        self::assertSame([],self::lookupApi('fixture_app','create','37suffix',$strategy));
        self::assertSame('invalid_app_api_id',$strategy);
        foreach ([['id'=>0],['id'=>true],['id'=>37,'api_id'=>38]] as $bad) {
            $api=$bad+['code'=>'create'];
            self::assertSame($api,(new \ReflectionMethod(Market::class,'mergeDeveloperDocAppApiDetail'))->invoke(null,['code'=>'fixture_app'],$api));
        }
        self::assertSame([],Wire::$requests);
    }
}
