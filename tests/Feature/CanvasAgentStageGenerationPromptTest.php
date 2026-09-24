<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\canvas_agent\ConversationActionPlan;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationTextContext;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CanvasAgentStageGenerationPromptTest extends TestCase
{
    private function workflow(string $version = '2026-09-23.9'): array
    {
        return [
            'workflow_snapshot' => ['key' => ConversationWorkflow::KEY, 'version' => $version],
            'artifact_memory' => [
                ['stage' => 'art', 'artifact' => 'subject_image_prompt', 'reference_key' => 'art:main_lan', 'title' => '林岚主图', 'content' => '林岚主体主图中文生图提示词'],
                ['stage' => 'art', 'artifact' => 'three_view_prompt', 'reference_key' => 'art:views_lan', 'title' => '林岚三视图', 'content' => '林岚正面、侧面、背面三视图中文提示词'],
                ['stage' => 'art', 'artifact' => 'scene_image_prompt', 'reference_key' => 'art:office', 'title' => '办公室场景', 'content' => '空办公室场景中文生图提示词'],
                ['stage' => 'video_plan', 'artifact' => 'video_prompt_plan', 'reference_key' => 'video_plan:shot_1', 'title' => '镜头一', 'content' => '第一镜头的完整规划'],
                ['stage' => 'assets', 'artifact' => 'subject', 'reference_key' => 'assets:subject_1', 'title' => '林岚主图', 'content' => '图片节点'],
                ['stage' => 'storyboard', 'artifact' => 'storyboard', 'reference_key' => 'storyboard:shot_1', 'title' => '分镜一', 'content' => '分镜节点'],
            ],
        ];
    }

    public function testConfirmedAssetPromptsBecomeEditableNodePromptsWithoutPlanningAppend(): void
    {
        $proposals = [
            ['type' => 'image', 'artifact' => 'subject', 'title' => '林岚主图', 'prompt' => '模型再次改写的内容', 'reference_keys' => ['art:main_lan']],
            ['type' => 'image', 'artifact' => 'three_view', 'title' => '林岚三视图', 'prompt' => '另一个改写版本', 'depends_on' => ['main_lan'], 'reference_keys' => ['art:views_lan']],
            ['type' => 'image', 'artifact' => 'scene', 'title' => '办公室', 'prompt' => '场景改写版本', 'reference_keys' => ['art:office']],
        ];
        $result = ConversationWorkflow::materializeTextReferences($this->workflow(), $proposals);
        self::assertSame('林岚主体主图中文生图提示词', $result[0]['prompt']);
        self::assertSame('林岚正面、侧面、背面三视图中文提示词', $result[1]['prompt']);
        self::assertSame(['main_lan'], $result[1]['depends_on']);
        self::assertSame('空办公室场景中文生图提示词', $result[2]['prompt']);
        foreach ($result as $node) self::assertArrayNotHasKey('reference_keys', $node);
    }

    public function testStoryboardAndVideoKeepTheirOwnReturnedPerShotPrompt(): void
    {
        $proposals = [
            ['type' => 'image', 'artifact' => 'storyboard', 'title' => '分镜一', 'prompt' => '林岚走进办公室，近景，冷色侧光。', 'reference_keys' => ['art:office', 'assets:subject_1']],
            ['type' => 'video', 'artifact' => 'storyboard_video', 'title' => '分镜一视频', 'prompt' => "分镜1｜0:00-0:05\n景别：近景\n构图：居中\n运镜手法：缓推\n画面内容：林岚走进办公室\n声音：脚步声", 'reference_keys' => ['video_plan:shot_1', 'storyboard:shot_1']],
        ];
        $result = ConversationWorkflow::materializeTextReferences($this->workflow(), $proposals);
        self::assertSame($proposals[0]['prompt'], $result[0]['prompt']);
        self::assertSame($proposals[1]['prompt'], $result[1]['prompt']);
        self::assertSame(['assets:subject_1'], $result[0]['reference_keys']);
        self::assertSame(['storyboard:shot_1'], $result[1]['reference_keys']);
    }

    public function testNewWorkflowRejectsAssetWithWrongOrMissingConfirmedPromptSource(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_AGENT_ACTION');
        ConversationWorkflow::materializeTextReferences($this->workflow(), [
            ['type' => 'image', 'artifact' => 'three_view', 'prompt' => '三视图', 'reference_keys' => ['art:main_lan']],
        ]);
    }

    public function testMediaStageContextRetainsScriptAlongsidePerAssetPromptSources(): void
    {
        $workflow=$this->workflow();
        array_unshift($workflow['artifact_memory'], [
            'stage'=>'script', 'artifact'=>'episode_script', 'reference_key'=>'script:episode_1',
            'title'=>'第一集剧本', 'content'=>'林岚在办公室发现一封旧信。',
        ]);
        for ($index=0;$index<5;$index++) $workflow['artifact_memory'][]=[
            'stage'=>'art', 'artifact'=>'subject_image_prompt', 'reference_key'=>'art:extra_'.$index,
            'title'=>'角色'.$index, 'content'=>'角色'.$index.'的中文主图提示词',
        ];
        $workflow['stage_state']=['key'=>'storyboard'];
        $messages=ConversationTextContext::messages([
            'workflow'=>$workflow,
            'messages'=>[['role'=>'user','content'=>'继续分镜图']],
            'selected_nodes'=>[],
        ]);
        $payload=json_decode(substr($messages[0]['content'],strpos($messages[0]['content'],"\n")+1),true,512,JSON_THROW_ON_ERROR);
        self::assertSame('林岚在办公室发现一封旧信。',$payload['confirmed_artifacts'][0]['content']);
        self::assertNotEmpty($payload['generation_prompt_sources']);
        self::assertSame('subject_image_prompt',$payload['generation_prompt_sources'][0]['artifact']);
    }

    public function testEarlierFrozenWorkflowRetainsOriginalPlanningAppendAndInstruction(): void
    {
        $result = ConversationWorkflow::materializeTextReferences($this->workflow('2026-09-23.8'), [
            ['type' => 'image', 'artifact' => 'subject', 'prompt' => '旧版提示词', 'reference_keys' => ['art:main_lan']],
        ]);
        self::assertStringContainsString('【已确认的相关创作规划】', $result[0]['prompt']);
        self::assertStringContainsString('林岚主体主图中文生图提示词', $result[0]['prompt']);
        self::assertStringNotContainsString('原样放进节点输入框', ConversationActionPlan::instruction('manual', 'assets', true));
        self::assertStringContainsString('原样放进节点输入框', ConversationActionPlan::instruction('manual', 'assets', true, true));
    }
}
