<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationRequest as Request;
use app\common\service\power\MarketGenerationDeclarationSchema as Declaration;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use PHPUnit\Framework\TestCase;

class MarketDeclaredChildrenValidationTest extends TestCase
{
    private function snapshot(): array
    {
        return ['market_metadata'=>['params_schema'=>['media'=>['type'=>'array','children'=>[
            'type'=>['type'=>'string','options'=>'image / video','required'=>'1'],
            'url'=>['type'=>'string','required'=>true],
            'controls'=>['type'=>'object','children'=>['enabled'=>['type'=>'boolean'],'seed'=>['type'=>'integer','minimum'=>0]]]]]]]];
    }
    public function testDeclaredObjectArrayChildrenValidateWithoutErasingOpenFieldsOrFalseAndZero(): void
    {
        $payload=['media'=>[['type'=>'image','url'=>'https://fixtures.invalid/a.png','controls'=>['enabled'=>false,'seed'=>0,'extension'=>'preserve'],
            'new_open_field'=>'preserve']],'model'=>'transport-model','channel'=>'transport-channel'];$before=$this->snapshot();
        self::assertSame($payload,Request::normalizeLegacyPayload($before,$payload,[]));
        self::assertArrayNotHasKey('items',$before['market_metadata']['params_schema']['media']);
        $expanded=Declaration::expand($before['market_metadata']['params_schema']);
        self::assertTrue($expanded['media']['items']['properties']['type']['required']);
    }
    /** @dataProvider invalidChildren */
    public function testInvalidExplicitChildrenAreRejectedAfterExistingEncoding(string $case): void
    {
        $row=['type'=>'image','url'=>'https://fixtures.invalid/a.png','controls'=>['enabled'=>false,'seed'=>0]];
        switch($case){case 'missing_type':unset($row['type']);break;case 'missing_url':unset($row['url']);break;
            case 'wrong_enum':$row['type']='audio';break;case 'wrong_url_type':$row['url']=['wrong'];break;
            case 'wrong_boolean':$row['controls']['enabled']='not boolean';break;case 'negative_seed':$row['controls']['seed']=-1;break;}
        $this->expectException(\InvalidArgumentException::class);Request::normalizeLegacyPayload($this->snapshot(),['media'=>[$row]],[]);
    }
    public static function invalidChildren(): array {return array_map(static fn($s)=>[$s],['missing_type','missing_url','wrong_enum','wrong_url_type','wrong_boolean','negative_seed']);}

    public function testOpaqueArraysStillDoNotAcquireTypesFromExamples(): void
    {
        $s=['market_metadata'=>['params_schema'=>['opaque'=>['type'=>'array','example'=>[['url'=>'not a schema']]]]]];
        $body=['opaque'=>[['untyped'=>'preserve']]];self::assertSame($body,Request::normalizeLegacyPayload($s,$body,[]));
    }
    public function testDeclaredStringItemsDoNotAcceptAnObjectArray(): void
    {
        $s=['market_metadata'=>['params_schema'=>['urls'=>['type'=>'array','children'=>['item'=>['type'=>'string']]]]]];
        $this->expectException(\InvalidArgumentException::class);Request::normalizeLegacyPayload($s,['urls'=>[['url'=>'wrong shape']]],[]);
    }
    public function testQwenReferenceEditingRejectsAgentInsteadOfSilentlyChangingIt(): void
    {
        $fixture=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/canvas-generation-market-contracts.json'),true);
        $metadata=array_values(array_filter($fixture['contracts'],static fn($c)=>($c['model_code']??'')==='qwen-image-3.0'))[0];
        $metadata['supports_reference_images']=true;$metadata['max_reference_images']=3;
        $snapshot=['model_code'=>'qwen-image-3.0','channel_code'=>'dashscope_compatible','market_metadata'=>$metadata,'locked_params'=>[]];
        $this->expectException(\Exception::class);$this->expectExceptionMessage('direct');
        Images::prepareRequest($snapshot,['prompt'=>'fixture','reference_images'=>['https://fixtures.invalid/a.png'],
            'provider_params'=>['parameters'=>['prompt_extend_mode'=>'agent']]]);
    }
    public function testPublishedHappyHorseMediaDeclarationValidatesEachUrlWithoutDroppingTheObject(): void
    {
        $e=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/market-application-children-review-20261029.json'),true)['entries'][0];
        self::assertSame('happy_horse',$e['app_code']);self::assertSame('submit',$e['api_code']);
        $s=['market_metadata'=>['params_schema'=>[$e['field']=>$e['declaration']]]];
        $good=['media'=>[['type'=>'video','url'=>'https://fixtures.invalid/a.mp4']]];
        self::assertSame($good,Request::normalizeLegacyPayload($s,$good,[]));
        $this->expectException(\InvalidArgumentException::class);Request::normalizeLegacyPayload($s,['media'=>[['type'=>'video','url'=>['wrong shape']]]],[]);
    }
}
