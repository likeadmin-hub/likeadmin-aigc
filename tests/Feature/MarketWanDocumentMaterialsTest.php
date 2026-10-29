<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationMaterialEncoding as Encoding;
use app\common\service\power\MarketGenerationMaterialPath as Path;
use app\common\service\power\MarketGenerationMediaInputs as Inputs;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/MarketMixedMaterialSelectorsTest.php';

class MarketWanDocumentMaterialsTest extends TestCase
{
    public static function bundle(): array
    {
        $b=MarketMixedMaterialSelectorsTest::bundle();$e=&$b['entries'][0];
        $schema=$e['declaration']['params_schema'];
        $schema['properties']['input']['properties']['media']['items']['properties']['type']['enum']=array_merge(
            $schema['properties']['input']['properties']['media']['items']['properties']['type']['enum'],['file','link']);
        $e['declaration']['params_schema']=$schema;
        foreach($e['modes']as&$mode)$mode['schema']=$schema;unset($mode);
        foreach(['file','link']as$kind){
            $code=$kind.'_reference';$field=['field'=>'input.media.*.url','kind'=>$kind,'role'=>'reference','maximum'=>1,'required'=>true,'item_match'=>['type'=>$kind]];
            $e['declaration']['material_capabilities']['modes'][]=['code'=>$code,'view_key'=>$kind,'method'=>'omni_reference','fields'=>[$field]];
            $e['modes'][$code]=['schema'=>$schema,'rules'=>[]];
        }
        return $b;
    }
    /** @dataProvider kinds */
    public function testExplicitReviewedDocumentReferenceIsEncodedWithoutMediaProbes(string $kind): void
    {
        $s=MarketMixedMaterialSelectorsTest::snapshot(self::bundle());$r=MarketMixedMaterialSelectorsTest::request();
        $r['material_mode']=$kind;$r['reference_assets']=[['type'=>$kind,'url'=>'https://fixtures.invalid/'.$kind.'?signature=fixture']];
        $native=Videos::prepareRequest($s,$r)->nativeInput;
        self::assertSame([['type'=>$kind,'url'=>$r['reference_assets'][0]['url']]],$native['input']['media']);
        self::assertSame(['image'=>[],'video'=>[],'audio'=>[]],Inputs::extract($native));
        self::assertSame([],Encoding::wanMedia($r)); // Existing callers do not silently gain document support.
    }
    public static function kinds(): array{return [['file'],['link']];}
    /** @dataProvider rejected */
    public function testDocumentModesRetainLimitsExclusivityRolesAndAddressValidation(string $case): void
    {
        $s=MarketMixedMaterialSelectorsTest::snapshot(self::bundle());$r=MarketMixedMaterialSelectorsTest::request();
        $r['material_mode']='file';$r['reference_assets']=[['type'=>'file','url'=>'https://fixtures.invalid/file']];
        switch($case){
            case 'file_and_link':$r['reference_assets'][]=['type'=>'link','url'=>'https://fixtures.invalid/page'];break;
            case 'file_and_frames':$r['reference_assets'][]=['type'=>'image','role'=>'first_frame_image','url'=>'https://fixtures.invalid/frame'];break;
            case 'two_files':$r['reference_assets'][]=['type'=>'file','url'=>'https://fixtures.invalid/file2'];break;
            case 'missing_file':$r['reference_assets']=[];break;
            case 'wrong_role':$r['reference_assets'][0]['role']='first_frame_image';break;
            case 'credentials':$r['reference_assets'][0]['url']='https://user:secret@fixtures.invalid/file';break;
            case 'relative_page':$r['material_mode']='link';$r['reference_assets']=[['type'=>'link','url'=>'page/path']];break;
            case 'data_page':$r['material_mode']='link';$r['reference_assets']=[['type'=>'link','url'=>'data:text/plain,fixture']];break;
            case 'unapproved_mode':$r['material_mode']='reference';break;
        }
        $this->expectException(\InvalidArgumentException::class);Videos::prepareRequest($s,$r);
    }
    public static function rejected(): array{return array_map(static fn($x)=>[$x],['file_and_link','file_and_frames','two_files','missing_file','wrong_role','credentials','relative_page','data_page','unapproved_mode']);}
    public function testDocumentsCannotBypassTheBusinessTotalAssetLimit(): void
    {
        $r=['reference_assets'=>[['type'=>'file','url'=>'https://fixtures.invalid/file']]];
        for($i=0;$i<15;$i++)$r['reference_assets'][]=['type'=>'image','url'=>'https://fixtures.invalid/i'.$i];
        $this->expectException(\InvalidArgumentException::class);Encoding::wanMedia($r,['file']);
    }
    public function testRawNativeDocumentsAreCountedByTypeAndCannotHideOtherKinds(): void
    {
        $s=MarketMixedMaterialSelectorsTest::snapshot(self::bundle());$r=MarketMixedMaterialSelectorsTest::request();
        $r['material_mode']='file';unset($r['reference_assets']);$r['provider_params']=['input'=>['media'=>[['type'=>'file','url'=>'https://fixtures.invalid/file']]]];
        self::assertSame(1,Path::count(Videos::prepareRequest($s,$r)->nativeInput,['field'=>'input.media.*.url','item_match'=>['type'=>'file']]));
        $r['provider_params']['input']['media'][]=['type'=>'link','url'=>'https://fixtures.invalid/page'];
        $this->expectException(\InvalidArgumentException::class);Videos::prepareRequest($s,$r);
    }
}
