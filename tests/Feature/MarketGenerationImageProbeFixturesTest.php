<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationImageProbeFixtures as Fixtures;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use PHPUnit\Framework\TestCase;

class MarketGenerationImageProbeFixturesTest extends TestCase
{
    private function mode(bool $requiredMask): array
    {
        return ['key'=>'edit','native_mode'=>'image_edit','fields'=>[
            ['field'=>'image_url','kind'=>'image','role'=>'reference','required'=>true,'minimum'=>1,'maximum'=>1],
            ['field'=>'reference_image_urls','kind'=>'image','role'=>'reference','required'=>true,'minimum'=>2,'maximum'=>3],
            ['field'=>'mask_url','kind'=>'image','role'=>'mask','required'=>$requiredMask,'minimum'=>$requiredMask?1:0,'maximum'=>1]],
            'parameter_schema'=>['properties'=>['image_url'=>['type'=>'string'],
                'reference_image_urls'=>['type'=>'array','items'=>['type'=>'string']],'mask_url'=>['type'=>'string']]]];
    }
    public function testOptionalAndRequiredMasksKeepExactSlotsAndDistinctUploadedSources(): void
    {
        $optional=Fixtures::recipes($this->mode(false));
        self::assertCount(3,$optional['assigned_required_images']);
        self::assertCount(4,$optional['assigned_optional_masks']);
        self::assertSame(['image_url','reference_image_urls','reference_image_urls','mask_url'],array_column($optional['assigned_optional_masks'],'field'));
        self::assertSame('mask_image',$optional['assigned_optional_masks'][3]['role']);
        self::assertCount(4,array_unique(array_column($optional['assigned_optional_masks'],'uri')));
        $required=Fixtures::recipes($this->mode(true));
        self::assertSame(['assigned_required_images'],array_keys($required));self::assertCount(4,$required['assigned_required_images']);
    }
    public function testBrowserShapedRecipeSurvivesActualNativePreparationWithoutSwappingMaskAndReference(): void
    {
        $mode=$this->mode(true);$assets=Fixtures::recipes($mode)['assigned_required_images'];
        $meta=['params_schema'=>$mode['parameter_schema']['properties'],'material_capabilities'=>['modes'=>[
            ['code'=>'image_edit','fields'=>$mode['fields']]]]];
        $p=Images::prepareRequest(['resource_type'=>'model','model_code'=>'assigned-mask-fixture','locked_params'=>[],
            'market_metadata'=>$meta],['reference_assets'=>$assets]);
        self::assertSame($assets[0]['uri'],$p->nativeInput['image_url']);
        self::assertSame([$assets[1]['uri'],$assets[2]['uri']],$p->nativeInput['reference_image_urls']);
        self::assertSame($assets[3]['uri'],$p->nativeInput['mask_url']);
        self::assertStringNotContainsString('blob:',json_encode($p->nativeInput));
    }
    /** @dataProvider unsupportedFields */
    public function testUnsupportedOrUnboundedStructuresStayUncoveredRatherThanBeingInvented(array $field,array $schema): void
    {
        self::assertNull(Fixtures::recipes(['fields'=>[$field],'parameter_schema'=>['properties'=>['input'=>$schema]]]));
    }
    public static function unsupportedFields(): array
    {
        $plain=['field'=>'input','kind'=>'image','role'=>'reference','required'=>true];
        return [[$plain,['type'=>'array','items'=>['type'=>'object']]],
            [array_replace($plain,['kind'=>'video']),['type'=>'string']],
            [array_replace($plain,['minimum'=>17]),['type'=>'string']],
            [array_replace($plain,['maximum'=>0]),['type'=>'string']]];
    }
}
