<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\canvas_agent\ConversationTextContext;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class CanvasAgentCreativeSettingsTest extends TestCase
{
    private function ratioState(): array
    {
        return [
            'workflow_snapshot'=>['slot_schema'=>[
                ['key'=>'visual_style','options'=>[]],
                ['key'=>'aspect_ratio','options'=>['9:16','16:9']],
            ]],
            'slot_values'=>[], 'creative_settings'=>[],
        ];
    }

    public function testConfirmedRatioIsCanonicalAndTenantOptionBound(): void
    {
        $state=$this->ratioState();
        $method=new ReflectionMethod(ConversationWorkflow::class,'recordCreativeAnswer');
        $method->setAccessible(true);
        $method->invokeArgs(null,[1,&$state,'aspect_ratio','9：16']);
        self::assertSame('9:16',$state['slot_values']['aspect_ratio']);
        self::assertSame('9:16',$state['creative_settings']['aspect_ratio']);
    }

    public function testUnsupportedRatioDoesNotBecomeAWorkflowSetting(): void
    {
        $state=$this->ratioState();
        $method=new ReflectionMethod(ConversationWorkflow::class,'recordCreativeAnswer');
        $method->setAccessible(true);
        try {
            $method->invokeArgs(null,[1,&$state,'aspect_ratio','1:1']);
            self::fail('The configured ratio must be enforced');
        } catch (RuntimeException $error) {
            self::assertSame('INVALID_WORKFLOW_ANSWER',$error->getMessage());
            self::assertSame([],$state['slot_values']);
        }
    }

    public function testLaterStagesReceiveConfirmedStyleAndRatio(): void
    {
        $messages=ConversationTextContext::messages([
            'workflow'=>[
                'workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,'version'=>ConversationWorkflow::VERSION],
                'stage_state'=>['key'=>'storyboard'],
                'slot_values'=>['visual_style'=>'电影写实','aspect_ratio'=>'9:16'],
                'creative_settings'=>['style_id'=>'12','style_name'=>'电影写实','style_prompt'=>'高对比电影灯光','aspect_ratio'=>'9:16'],
                'artifact_memory'=>[],
            ],
            'messages'=>[['role'=>'user','content'=>'继续生成分镜图']],
            'selected_nodes'=>[],
        ]);
        $payload=json_decode(substr($messages[0]['content'],strpos($messages[0]['content'],"\n")+1),true,512,JSON_THROW_ON_ERROR);
        self::assertSame('高对比电影灯光',$payload['workflow_creative_settings']['style_prompt']);
        self::assertSame('9:16',$payload['workflow_creative_settings']['aspect_ratio']);
    }

    public function testQuotedPlanAcceptsFrozenRatioAndLegacyQuantityOnly(): void
    {
        $plan=['hash'=>str_repeat('a',64),'nodes'=>[['type'=>'image']],'sources'=>[],
            'attachment_images'=>[],'parameters'=>['quantity'=>1,'ratio'=>'9:16'],
            'quotes'=>[[]],'run_id'=>1];
        $method=new ReflectionMethod(ConversationWorkflow::class,'validImagePlan');
        $method->setAccessible(true);
        self::assertTrue($method->invoke(null,$plan));
        $plan['parameters']['ratio']='invalid';
        self::assertFalse($method->invoke(null,$plan));
        $plan['parameters']=['quantity'=>1];
        self::assertTrue($method->invoke(null,$plan));
    }

    public function testAutoImageRequestHashChangesWhenConfirmedRatioOrPromptChanges(): void
    {
        $metadata=['prompt'=>'林岚主图','channel'=>'image-model','ratio'=>'9:16','count'=>1];
        $original=GraphService::autoPayloadHash($metadata);
        self::assertSame($original,GraphService::autoPayloadHash($metadata));
        $metadata['ratio']='16:9';
        self::assertNotSame($original,GraphService::autoPayloadHash($metadata));
        $metadata['ratio']='9:16';
        $metadata['prompt']='不同的主图';
        self::assertNotSame($original,GraphService::autoPayloadHash($metadata));
    }
}
