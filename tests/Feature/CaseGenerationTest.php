<?php

use app\common\service\case_gallery\CaseGenerationService as Cases;
use app\common\service\app\AppCaseService;
use PHPUnit\Framework\TestCase;

final class CaseGenerationTest extends TestCase
{
    public function testImageSnapshotPreservesAllReferencesAndExplicitZeroAndFalse(): void
    {
        $task = ['channel'=>'market_image_model:9', 'model'=>'display-only', 'prompt'=>'@图片2', 'negative_prompt'=>'no text',
            'quantity'=>3,'ratio'=>'3:4','quality'=>'2k','reference_images'=>['a.png','b.png'],
            'provider_params_json'=>['seed'=>0,'safety'=>false,'_creation_batch'=>['id'=>'private'],'_material_mode'=>'reference', 'api_key'=>'private']];
        $before = $task;
        $config = Cases::fromTask($task,'aigc_image');
        $request = $config['generation_request'];
        self::assertSame('market_image_model:9',$request['model_id']);
        self::assertSame(3,$request['quantity']);
        self::assertSame(['a.png','b.png'],$request['reference_images']);
        self::assertSame(['seed'=>0,'safety'=>false],$request['provider_params']);
        self::assertSame('reference',$request['material_mode']);
        self::assertSame('no text',$request['negative_prompt']);
        self::assertSame($before,$task);
    }

    public function testVideoSnapshotUsesRequestDurationAndKeepsMixedMaterialOrderAndRoles(): void
    {
        $assets=[['type'=>'image','uri'=>'first.png','role'=>'first_frame_image','field'=>'frames','reference_slot_id'=>'a'],
            ['type'=>'audio','uri'=>'sound.wav','role'=>'reference_audio','reference_slot_id'=>'b'],
            ['type'=>'image','uri'=>'last.png','role'=>'last_frame_image','reference_slot_id'=>'c']];
        $task=['channel'=>'market_video_app:seedance:5','quality'=>'720p','duration'=>1,'request_duration'=>8,
            'reference_images'=>['first.png','last.png'],'reference_assets'=>$assets,'market_sku_id'=>12,
            'model_json'=>['duration'=>6,'generation_method'=>'omni_reference','mode'=>'fast','material_mode'=>'omni',
                'advanced_params'=>['seed'=>0,'model'=>'seedance-2-fast-video-2-video'],'provider_params'=>['generate_audio'=>false],
                'effect_snapshot'=>['id'=>'fx','instruction'=>'move'],'q4_options'=>['watermark'=>false]],
            'pricing_snapshot'=>['secret'=>'private'],'provider_task_id'=>'private'];
        $request=Cases::fromTask($task,'aigc_video')['generation_request'];
        self::assertSame(8,$request['duration']);
        self::assertSame($assets,$request['reference_assets']);
        self::assertSame('omni_reference',$request['generation_method']);
        self::assertSame(false,$request['provider_params']['generate_audio']);
        self::assertSame(0,$request['advanced_params']['seed']);
        self::assertSame('fx',$request['effect_id']);
        self::assertArrayNotHasKey('pricing_snapshot',$request);
        self::assertArrayNotHasKey('provider_task_id',$request);
    }

    public function testNanoRoutingAndDigitalHumanDriverRemainEditableInputs(): void
    {
        $request=Cases::fromTask(['channel'=>'market_nano_banana:5:abc','provider_params_json'=>['variant'=>'fast','market_sku_id'=>23]],'aigc_image')['generation_request'];
        self::assertSame('fast',$request['variant']);self::assertSame(23,$request['market_sku_id']);self::assertSame([],$request['provider_params']);
        $task=['channel'=>'human','script_text'=>'hello','avatar_id'=>3,'voice_id'=>0,'tts_audio_uri'=>'driver.wav','provider_payload_json'=>['drive_mode'=>'audio']];
        $human=Cases::fromTask($task,'aigc_digital_human')['generation_request'];
        self::assertSame('hello',$human['prompt']);self::assertSame('driver.wav',$human['audio_uri']);
        $task['voice_id']=2;$task['provider_payload_json']['drive_mode']='text';
        self::assertArrayNotHasKey('audio_uri',Cases::fromTask($task,'aigc_digital_human')['generation_request']);
    }

    public function testSaveRetainsStructuredParametersAndMultipleReferences(): void
    {
        $build=new ReflectionMethod(AppCaseService::class,'buildSaveData');
        $data=$build->invoke(null,7,'aigc_image',['title'=>'case','prompt'=>'edit','reference_images'=>['a.png','b.png'],
            'config_json'=>Cases::fromTask(['channel'=>'market_image_model:1','provider_params_json'=>['seed'=>0]],'aigc_image')]);
        self::assertSame(['a.png','b.png'],$data['reference_images']);
        self::assertSame(0,$data['config_json']['generation_request']['provider_params']['seed']);
        self::assertSame('market_image_model:1',$data['config_json']['generation_request']['model_id']);
    }

    public function testPublicSnapshotCannotContainCredentialsOrTransientReferenceUrls(): void
    {
        $request=Cases::request(['model_id'=>'model','tenant_id'=>99,'user_id'=>8,'pricing_snapshot'=>['secret'=>'x'],
            'provider_params'=>['api_key'=>'x','nested'=>['access_token'=>'x','enabled'=>false],'seed'=>0],
            'reference_images'=>['a.png','asset://owned-media','blob:x','javascript:bad','data:image/png;base64,xx'],
            'reference_assets'=>[['uri'=>'a.png','url'=>'blob:preview','type'=>'image','role'=>'reference_image','file'=>'local'],['uri'=>'file:///private','type'=>'image']]]);
        self::assertSame(['a.png','asset://owned-media'],$request['reference_images']);
        self::assertCount(1,$request['reference_assets']);
        self::assertArrayNotHasKey('url',$request['reference_assets'][0]);
        self::assertSame(['nested'=>['enabled'=>false],'seed'=>0],$request['provider_params']);
        self::assertArrayNotHasKey('tenant_id',$request);
        self::assertArrayNotHasKey('user_id',$request);
    }
}
