<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_llm\agent\AgentCapabilityCatalog;

final class AgentConfigurationTransportTest extends TestCase
{
    public function testCompactTransportRetainsPickerContractsAndDoesNotMutateAdmissionData(): void
    {
        $channel=['id'=>'fixture','material_modes'=>[['fields'=>[['kind'=>'image','maximum'=>4]]]],'specs'=>[['duration'=>4,'user_charge_points'=>2]],'schema'=>['required'=>['prompt']]];
        $options=['channels'=>[$channel],'models'=>[$channel],'applications'=>[],'defaults'=>['channel'=>'fixture']];
        $full=['models'=>['image'=>$options,'video'=>$options,'text'=>['models'=>[['code'=>'text']]],'music'=>$options],
            'tools'=>[['code'=>'aigc_image','schema'=>['required'=>['prompt']],'model_options'=>$options]],'preferences'=>['models'=>['text'=>'text']]];
        $copy=$full;$compact=AgentCapabilityCatalog::presentationConfiguration($full);
        self::assertSame($copy,$full);
        self::assertSame($channel,$compact['models']['image']['channels'][0]);
        self::assertSame($channel,$compact['models']['video']['channels'][0]);
        self::assertSame($full['models']['text'],$compact['models']['text']);
        self::assertSame($full['models']['music'],$compact['models']['music']);
        self::assertSame($full['preferences'],$compact['preferences']);
        self::assertSame($full['tools'][0]['schema'],$compact['tools'][0]['schema']);
        self::assertArrayNotHasKey('model_options',$compact['tools'][0]);
        self::assertArrayNotHasKey('models',$compact['models']['video']);
        $full['models']['image']['channels']=[];
        self::assertSame($full['models']['image'],AgentCapabilityCatalog::presentationConfiguration($full)['models']['image']);
    }
}
