<?php
namespace Tests\Feature;

use app\Request;
use app\common\service\power\MarketCreationParameters;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class GenerationHttpInputTest extends TestCase
{
    public function testDefaultHttpFilterTrimsStringsWithoutCastingNestedJsonScalars(): void
    {
        $params = ['prompt'=>'  synthetic  ', 'advanced_params'=>['multi_shots'=>false,'audio'=>true,'seed'=>0,'strength'=>0.25],
            'nodes'=>[['metadata'=>['generationParameters'=>['fixture'=>['audio'=>false,'seed'=>0]]]]],
            'nullable'=>null,'list'=>[]];
        $expected=$params; $expected['prompt']='synthetic';
        $request=(new Request())->withPost($params);
        self::assertSame($expected,$request->post());
        self::assertSame($expected,$request->post());
        self::assertSame($params,$request->post(false));
    }

    /** @dataProvider mediaTypes */
    public function testHttpFalseAndZeroReachSharedCreationAndCanvasValidation(string $type): void
    {
        $input=['channel'=>'fixture','prompt'=>'  synthetic  ','advanced_params'=>['multi_shots'=>false,'seed'=>0]];
        $http=(new Request())->withPost($input)->post();
        $model=['value'=>'fixture','resource_type'=>'model','params_schema'=>['multi_shots'=>['type'=>'boolean'],'seed'=>['type'=>'integer','minimum'=>0]],
            'material_modes'=>[['key'=>'text','method'=>'text_to_video','fields'=>[]]]];
        $standalone=MarketCreationParameters::applyOptions($http,[$model],$type);
        self::assertFalse($standalone['provider_params']['multi_shots']);
        self::assertSame(0,$standalone['provider_params']['seed']);
        $canvas=(new ReflectionMethod(ShortDramaCanvasService::class,'generationPayload'))->invoke(null,$type,$http,1,1,0,true);
        $prepared=MarketCreationParameters::applyOptions($canvas,[$model],$type);
        self::assertFalse($prepared['provider_params']['multi_shots']);
        self::assertSame(0,$prepared['provider_params']['seed']);
        $invalid=$http; $invalid['advanced_params']['seed']=-1;
        $this->expectException(\InvalidArgumentException::class);
        MarketCreationParameters::applyOptions($invalid,[$model],$type);
    }

    public static function mediaTypes(): array { return [['image'],['video']]; }

    public function testExplicitFiltersAndTextFormInputsRetainExistingBehavior(): void
    {
        $request=(new Request())->withPost(['label'=>'  name  ','count'=>'0','flag'=>'false','html'=>' <b>name</b> ']);
        self::assertSame(['label'=>'name','count'=>'0','flag'=>'false','html'=>'<b>name</b>'],$request->post());
        self::assertSame('  NAME  ',$request->post('label',null,'strtoupper'));
        self::assertSame(' <b>name</b> ',$request->post('html',null,null));
    }
}
