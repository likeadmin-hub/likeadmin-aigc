<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaDirectGeneration as Direct, ShortDramaInputContract as Contract, ShortDramaStoryWorkflow as Workflow};
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeries;
use PHPUnit\Framework\TestCase;

class ShortDramaDirectGenerationTest extends TestCase
{
    public function testPromptOnlyContainsCreativeRequirementsNotInternalBudgets(): void
    {
        $messages=Direct::messages('程序员大战孙悟空', ['ratio'=>'9:16','target_duration_seconds'=>60,
            'duration_source'=>'user', 'shot_duration_rule'=>['min_seconds'=>4,'max_seconds'=>15],
            'storyboard_target_rule'=>['count'=>20], '_prompt_snapshot'=>['system'=>'强制20镜'],
            'selected_style_name'=>'水墨','episode_count'=>3], '新故事');
        $data=json_decode($messages['content'],true);
        self::assertSame(60,$data['creative_context']['target_duration_seconds']);
        self::assertSame('水墨',$data['creative_context']['selected_style_name']);
        self::assertArrayNotHasKey('shot_duration_rule',$data['creative_context']);
        self::assertArrayNotHasKey('storyboard_target_rule',$data['creative_context']);
        self::assertStringNotContainsString('强制20镜',json_encode($messages,JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('4-15',$messages['system_prompt']);
    }

    public function testReturnedDurationsDialogueAndUnknownFieldsAreNotRewritten(): void
    {
        $payload=['title'=>'测试','storyboard'=>[
            ['shot_id'=>'s1','recommended_duration_seconds'=>2,'dialogue'=>str_repeat('台词',100)],
            ['shot_id'=>'s2','recommended_duration_seconds'=>37,'dialogue'=>'保留原文']], 'extra'=>'自定义内容'];
        $raw=json_encode($payload,JSON_UNESCAPED_UNICODE);
        $result=Direct::result(['content'=>$raw],Contract::begin(['target_duration_seconds'=>60]),'标题');
        self::assertSame($payload['storyboard'],$result['storyboard']);
        self::assertSame('自定义内容',$result['extra']);
        self::assertSame($raw,$result['raw_content']);
        self::assertSame('skipped',$result['review_report']['status']);
    }

    public function testNonJsonAndPartialRepliesRemainVisibleAcrossCanvasStages(): void
    {
        foreach(['已经完成的剧本正文','{"storyboard":['] as $raw) {
            $result=Direct::result(['content'=>$raw,'finish_reason'=>'length'],Contract::begin([]),'标题');
            self::assertSame($raw,$result['raw_content']);
            foreach(['story','outline','episode'] as $stage) self::assertStringContainsString($raw,ConversationSeries::text($result,$stage,1));
        }
    }

    public function testInputVersionDoesNotOverrideRequestedReviewPolicy(): void
    {
        foreach(['story','outline','production'] as $stage) {
            $request=Contract::begin(['multi_episode_stage'=>$stage,'quality_review_enabled'=>true]);
            self::assertTrue(Direct::enabled($request));
            self::assertTrue(Workflow::qualityReviewEnabled($request));
            $request['quality_review_enabled'] = false;
            self::assertFalse(Workflow::qualityReviewEnabled($request));
        }
        self::assertFalse(Direct::enabled(['_input_contract_version'=>8]));
        self::assertTrue(Workflow::qualityReviewEnabled(['_input_contract_version'=>8]));
    }
}
