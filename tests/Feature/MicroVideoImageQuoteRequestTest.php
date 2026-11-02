<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoFrameCatalog;
use app\common\service\power\MarketGenerationSchema;
use PHPUnit\Framework\TestCase;

/** Replaces only the DB/pricing boundary; schema validation and catalogue adapter remain real. */
final class OfflineMicroImageQuoteRuntime
{
    public static function quote(int $tenant, array $request, int $quantity): array
    {
        MarketGenerationSchema::validate(['prompt'=>['type'=>'string','required'=>true],
            'reference_images'=>['type'=>'array','required'=>true,'minItems'=>($request['model_id']??'')==='two-reference-required'?2:1,'maxItems'=>2,'items'=>['enum'=>['https://owned.invalid/role.png','https://owned.invalid/anchor.png']]]], $request);
        return ['settlement_mode'=>'reserved','tenant_cost_points'=>2.,'user_charge_points'=>($request['prompt']??'')==='不同价格'?4.:3.,
            'market_snapshot'=>['billing_meter'=>($request['model_id']??'')==='reference-priced'?['image_denominator'=>2]:['basis'=>'image']]];
    }
}

final class MicroVideoImageQuoteRequestTest extends TestCase
{
    public function testWorkerAndInitialQuoteUseTheSameAuthorizedInputBuilder(): void
    {
        $job=['selection'=>['model_id'=>'fixture','market_sku_id'=>12,'quality'=>'2K','ratio'=>'9:16'],
            'prompt'=>'生成角色定妆照', 'images'=>[]];
        $expected=['model_id'=>'fixture','market_sku_id'=>12,'quality'=>'2K','ratio'=>'9:16',
            'quantity'=>1,'prompt'=>'生成角色定妆照','reference_images'=>[]];
        self::assertSame($expected, \app\common\service\app\aigc_micro_video\MicroVideoWorkbenchJobs::imageRequest($job));
        $worker=new \ReflectionMethod(\app\common\service\app\aigc_micro_video\MicroVideoWorkbenchWorker::class,'imageRequest');
        self::assertSame($expected,$worker->invoke(null,$job));
        $snapshot=['model_code'=>'fixture','channel_code'=>'fixture','locked_params'=>[],
            'market_metadata'=>['params_schema'=>['prompt'=>['type'=>'string','required'=>true]]]];
        self::assertSame('生成角色定妆照', \app\common\service\power\MarketImageModelRuntimeService::prepareRequest($snapshot,$expected)->nativeInput['prompt']);
    }

    public function testFinalQuoteComparisonRetainsThePersistedReceiptNumericSemantics(): void
    {
        $quote=['selection'=>['market_sku_id'=>12],'tenant_cost_points'=>2.,'user_charge_points'=>3.];
        $stored=\app\common\service\app\aigc_micro_video\MicroVideoStore::json(\app\common\service\app\aigc_micro_video\MicroVideoStore::encode($quote));
        self::assertSame(\app\common\service\app\aigc_micro_video\MicroVideoPolicy::fingerprint($quote),\app\common\service\app\aigc_micro_video\MicroVideoPolicy::fingerprint($stored));
        $stored['user_charge_points']=4;
        self::assertNotSame(\app\common\service\app\aigc_micro_video\MicroVideoPolicy::fingerprint($quote),\app\common\service\app\aigc_micro_video\MicroVideoPolicy::fingerprint($stored));
    }

    public function testFrameWorkerUsesTheFullEndpointPromptAndOnlySuppliedActualReferences(): void
    {
        $request=\app\common\service\app\aigc_micro_video\MicroVideoFrames::imageRequest(
            ['model_id'=>'fixed','market_sku_id'=>12], ['boundary_index'=>1,'prompt'=>'当前端点静止画面'],
            [['start_state'=>'手自然下垂','end_state'=>'右手举到胸前']], '保持原始人物身份',
            ['https://owned.invalid/role.png','https://owned.invalid/anchor.png']);
        self::assertSame(['https://owned.invalid/role.png','https://owned.invalid/anchor.png'],$request['reference_images']);
        self::assertStringContainsString('前段结束状态：右手举到胸前',$request['prompt']);
        self::assertStringContainsString('当前端点静止画面',$request['prompt']);
        self::assertSame(1,$request['quantity']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUniformFixedFrameBatchKeepsTheExistingPerFrameQuote(): void
    {
        class_alias(OfflineMicroImageQuoteRuntime::class, 'app\\common\\service\\power\\MarketImageModelRuntimeService');
        $selection=['model_id'=>'fixed','market_sku_id'=>12];
        $quote=\app\common\service\app\aigc_micro_video\MicroVideoFrames::quoteInputs(1,$selection,[
            ['request'=>['prompt'=>'第一帧','reference_images'=>['https://owned.invalid/role.png']],'future_reference'=>false],
            ['request'=>['prompt'=>'第二帧','reference_images'=>['https://owned.invalid/role.png']],'future_reference'=>true]]);
        self::assertSame(['selection'=>$selection,'market_sku_id'=>12,'tenant_cost_points'=>2.,'user_charge_points'=>3.],$quote);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDifferentFramePricesCannotBecomeOneMisleadingBatchUnitPrice(): void
    {
        class_alias(OfflineMicroImageQuoteRuntime::class, 'app\\common\\service\\power\\MarketImageModelRuntimeService');
        $this->expectExceptionMessage('所选首尾帧的价格不同，请逐张估价生成');
        \app\common\service\app\aigc_micro_video\MicroVideoFrames::quoteInputs(1,['model_id'=>'fixed','market_sku_id'=>12],[
            ['request'=>['prompt'=>'第一帧','reference_images'=>['https://owned.invalid/role.png']],'future_reference'=>false],
            ['request'=>['prompt'=>'不同价格','reference_images'=>['https://owned.invalid/role.png']],'future_reference'=>false]]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testMissingFutureAnchorNeverRelaxesTheModelsRequiredReferenceCount(): void
    {
        class_alias(OfflineMicroImageQuoteRuntime::class, 'app\\common\\service\\power\\MarketImageModelRuntimeService');
        $this->expectExceptionMessage('素材或参数数量不正确：参考图片');
        MicroVideoFrameCatalog::quote(1,['model_id'=>'two-reference-required','market_sku_id'=>12],
            ['prompt'=>'实际端点','reference_images'=>['https://owned.invalid/role.png']],true);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testBatchFramesRejectTheSecondMissingPrompt(): void
    {
        class_alias(OfflineMicroImageQuoteRuntime::class, 'app\\common\\service\\power\\MarketImageModelRuntimeService');
        $selection=['model_id'=>'fixed','market_sku_id'=>12];
        $inputs=[['request'=>['prompt'=>'第一帧','reference_images'=>['https://owned.invalid/role.png']], 'future_reference'=>false],
            ['request'=>['prompt'=>'','reference_images'=>['https://owned.invalid/role.png']], 'future_reference'=>true]];
        try { \app\common\service\app\aigc_micro_video\MicroVideoFrames::quoteInputs(1,$selection,$inputs); }
        catch (\InvalidArgumentException $error) { self::assertSame('请填写必填参数：提示词',$error->getMessage());return; }
        catch (\Throwable $error) { self::fail($error->getMessage()); }
        self::fail('Every frame must validate its own prompt before a batch can be quoted');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFutureAnchorCannotBeQuotedWhenReferenceCountAffectsPrice(): void
    {
        class_alias(OfflineMicroImageQuoteRuntime::class, 'app\\common\\service\\power\\MarketImageModelRuntimeService');
        $this->expectExceptionMessage('上一张连续性锚图尚未生成，当前模型按参考图计费，请先逐张生成并重新估价');
        MicroVideoFrameCatalog::quote(1,['model_id'=>'reference-priced','market_sku_id'=>12],
            ['prompt'=>'本帧实际提示词','reference_images'=>['https://owned.invalid/role.png']],true);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFixedPerImagePriceCanQuoteWithExistingReferencesBeforeFutureAnchorExists(): void
    {
        class_alias(OfflineMicroImageQuoteRuntime::class, 'app\\common\\service\\power\\MarketImageModelRuntimeService');
        $selection=['model_id'=>'fixed','market_sku_id'=>12];
        $quote=MicroVideoFrameCatalog::quote(1,$selection,
            ['prompt'=>'本帧实际提示词','reference_images'=>['https://owned.invalid/role.png']],true);
        self::assertSame(3.,$quote['user_charge_points']);
        self::assertSame($selection,$quote['selection']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testExactImageQuoteValidatesActualPromptAndReferencesButKeepsSelectionIdentity(): void
    {
        class_alias(OfflineMicroImageQuoteRuntime::class, 'app\\common\\service\\power\\MarketImageModelRuntimeService');
        $selection=['model_id'=>'fixture','market_sku_id'=>12,'quality'=>'2K','ratio'=>'9:16'];
        try { $quote=MicroVideoFrameCatalog::quote(1,$selection,['prompt'=>'保留人物服饰','reference_images'=>['https://owned.invalid/role.png'],'quantity'=>1]); }
        catch (\InvalidArgumentException $error) { self::fail('Actual image input was lost before quote validation: '.$error->getMessage()); }
        self::assertSame(['selection'=>$selection,'market_sku_id'=>12,'tenant_cost_points'=>2.,'user_charge_points'=>3.],$quote);
        self::assertArrayNotHasKey('prompt',$quote['selection']);
        self::assertArrayNotHasKey('reference_images',$quote['selection']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testExactImageQuoteStillRejectsAnExplicitlyEmptyPrompt(): void
    {
        class_alias(OfflineMicroImageQuoteRuntime::class, 'app\\common\\service\\power\\MarketImageModelRuntimeService');
        $this->expectExceptionMessage('请填写必填参数：提示词');
        MicroVideoFrameCatalog::quote(1,['market_sku_id'=>12],['prompt'=>'','reference_images'=>['https://owned.invalid/role.png']]);
    }
}
