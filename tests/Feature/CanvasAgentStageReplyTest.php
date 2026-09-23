<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStageReply;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow;
use PHPUnit\Framework\TestCase;

class CanvasAgentStageReplyTest extends TestCase
{
    private function workflow(string $stage): array
    {
        return ['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY], 'stage_state'=>['key'=>$stage]];
    }

    public function testFullStructuredBodyNeverBecomesTheVisibleScriptReply(): void
    {
        $body="## 故事设定\nproject_title: 星海来信\n".str_repeat('第一幕的完整剧本对白和画面描述。',30);
        $nodes=[
            ['type'=>'text','artifact'=>'story_setting','title'=>'《星海来信》故事设定','prompt'=>$body],
            ['type'=>'text','artifact'=>'episode_script','title'=>'第一集剧本','prompt'=>'完整剧本仍在受控节点中'],
        ];
        $visible=ConversationStageReply::present($this->workflow('script'),$body,$nodes,false);
        self::assertStringContainsString('星海来信',$visible);
        self::assertStringContainsString('确认',$visible);
        self::assertStringNotContainsString('project_title',$visible);
        self::assertStringNotContainsString('完整剧本对白',$visible);
        self::assertSame($body,$nodes[0]['prompt']);
    }

    public function testConciseContextualModelSummaryIsKept(): void
    {
        $reply='已整理林岚和周临的角色视觉方向，主场景设为雨夜车站。请确认画风，接下来准备主体图。';
        self::assertSame($reply,ConversationStageReply::present($this->workflow('art'),$reply,[['title'=>'林岚主图','type'=>'text']],false));
    }

    public function testUnwrittenPlanCannotClaimCanvasWasAlreadyChanged(): void
    {
        $visible=ConversationStageReply::present($this->workflow('assets'),'已插入画布，图片已生成，请查看。',[['title'=>'林岚主体图','type'=>'image'],['title'=>'林岚三视图','type'=>'image']],false);
        self::assertStringContainsString('林岚主体图',$visible);
        self::assertStringContainsString('预估积分',$visible);
        self::assertStringNotContainsString('图片已生成',$visible);
    }

    public function testVideoNodesAlwaysDescribeManualSubmission(): void
    {
        $visible=ConversationStageReply::present($this->workflow('video_nodes'),'已创建画布节点。',[['title'=>'雨夜车站·镜头一','type'=>'video']],true);
        self::assertStringContainsString('雨夜车站',$visible);
        self::assertStringContainsString('逐个',$visible);
    }

    public function testOrdinaryConversationIsUnchanged(): void
    {
        self::assertSame('## 普通聊天',ConversationStageReply::present([], '## 普通聊天',[],false));
    }
}
