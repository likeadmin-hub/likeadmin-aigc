<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationParameters as Parameters;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use app\common\service\power\MarketGenerationMedia as Media;
use PHPUnit\Framework\TestCase;

/** Native flat document fields; no downloads, uploads, tasks or billing. */
class MarketNativeDocumentParametersTest extends TestCase
{
    private function metadata(): array
    {
        $array=['type'=>'array','maxItems'=>1,'items'=>['type'=>'string','format'=>'uri']];
        return ['params_schema'=>['type'=>'object','properties'=>['prompt'=>['type'=>'string'],
            'reference_file_urls'=>$array,'reference_link_urls'=>$array]],
            'material_capabilities'=>['modes'=>[['code'=>'reference_to_video','method'=>'omni_reference','fields'=>[
                ['field'=>'reference_file_urls','kind'=>'file','role'=>'reference','maximum'=>1],
                ['field'=>'reference_link_urls','kind'=>'file','role'=>'reference','maximum'=>1]]]]]];
    }
    private function snapshot(): array {return ['resource_type'=>'model','model_code'=>'document-fixture','channel_code'=>'offline','locked_params'=>[],'market_metadata'=>$this->metadata()];}
    public function testOnlyDeclaredTypedDocumentSlotsReceiveControls(): void
    {
        $metadata=$this->metadata();
        $model=$metadata;$model['material_modes']=[['key'=>'reference','fields'=>$metadata['material_capabilities']['modes'][0]['fields']]];
        $fields=Parameters::definitions($model,'video');
        self::assertSame(['file','link'],array_column($fields,'control'));
        unset($model['material_modes']);
        self::assertSame([],array_column(Parameters::definitions($model,'video'),'control'));
        $model['material_modes']=[['key'=>'reference','fields'=>$metadata['material_capabilities']['modes'][0]['fields']]];
        unset($model['params_schema']['properties']['reference_file_urls']['items']['type']);
        self::assertSame(['link'],array_column(Parameters::definitions($model,'video'),'control'));
    }
    /** @dataProvider kinds */
    public function testDocumentAddressesArePreparedAndExcludedFromMediaMetering(string $field): void
    {
        $r=['prompt'=>'Synthetic document fixture','generation_method'=>'omni_reference','provider_params'=>[$field=>['https://fixtures.invalid/document']]];
        $p=Videos::prepareRequest($this->snapshot(),$r);
        self::assertSame($r['provider_params'][$field],$p->nativeInput[$field]);
        self::assertSame(['image'=>[],'video'=>[],'audio'=>[]],Media::inputs($p->nativeInput));
        self::assertSame($p->nativeInput,Videos::prepareRequest(json_decode(json_encode($this->snapshot()),true),$r)->nativeInput);
    }
    public static function kinds(): array{return [['reference_file_urls'],['reference_link_urls']];}
    /** @dataProvider rejectedAddresses */
    public function testNativeDocumentAddressesRejectUnsupportedSchemesAndCredentials(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Videos::prepareRequest($this->snapshot(),['prompt'=>'fixture','generation_method'=>'omni_reference',
            'provider_params'=>['reference_link_urls'=>[$value]]]);
    }
    public static function rejectedAddresses(): array{return [[''],['file:///fixture'],['blob:fixture'],['data:text/plain,fixture'],['https://fixture:fixture@fixtures.invalid/page'],['/relative/page']];}
    public function testDeclaredDocumentLimitStillRejectsExtraFiles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Videos::prepareRequest($this->snapshot(),['prompt'=>'fixture','generation_method'=>'omni_reference',
            'provider_params'=>['reference_file_urls'=>['https://fixtures.invalid/a','https://fixtures.invalid/b']]]);
    }
}
