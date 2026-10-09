<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextApplicationTest extends TestCase
{
    public function testOnlyNewEligibleProjectsEnterExplicitServerAllowlist(): void
    {
        $execution = ['stage_timeout_seconds' => 100, 'lease_seconds' => 150, 'deadline_seconds' => 1800];
        $policy = ['enabled' => true, 'tenant_ids' => [7], 'models' => ['model1' => $execution]];
        $request = ['_pipeline_version' => 1]; $model = ['id' => 'model1'];
        self::assertSame($execution, ApplicationWorkflow::enrollment(7, $request, $model, $policy, true));
        self::assertNull(ApplicationWorkflow::enrollment(7, $request, $model, $policy, false));
        self::assertNull(ApplicationWorkflow::enrollment(8, $request, $model, $policy, true));
        self::assertNull(ApplicationWorkflow::enrollment(7, $request, ['id' => 'other'], $policy, true));
        self::assertNull(ApplicationWorkflow::enrollment(7, $request, $model, ['enabled' => false] + $policy, true));
        foreach ([['multi_episode' => true], ['episode_id' => 9], ['script_source' => 'upload'], ['source' => 'revision']] as $legacy) {
            self::assertNull(ApplicationWorkflow::enrollment(7, $legacy + $request, $model, $policy, true));
        }
        self::assertFalse(ApplicationWorkflow::current(['_generation_version' => 3]));
        self::assertTrue(ApplicationWorkflow::current(['_pipeline_version' => 1]));
        $this->expectExceptionCode(409); ApplicationWorkflow::current(['_pipeline_version' => 999]);
    }

    public function testCanonicalProjectionPreservesLongDialogueAndBypassesLegacyReadCleanup(): void
    {
        $text = str_repeat('这是完整原文。', 250) . '最后一句不能丢。';
        $context = ['revision_id' => 'revision1', 'episode_id' => 'e1', 'entity_scope' => 't:u:p'];
        $doc = ContentDocument::normalize(['title' => '长对白', 'entities' => [['ref' => 'l1', 'name' => '书房', 'kind' => 'location']],
            'scenes' => [['location' => 'l1', 'units' => [['type' => 'dialogue', 'speech_type' => 'narration', 'text' => $text]]]]], $context);
        $plan = ShotCompiler::compile($doc, ['shots' => [['visual_description' => '阳光照进书房', 'refs' => [['unit' => 'u1']]]]], 'shots1');
        $projection = ApplicationWorkflow::project(['document' => $doc, 'shot_plan' => $plan, 'coverage_report' => ['status' => 'ready']]);
        self::assertSame('旁白：' . $text, $projection['storyboard'][0]['dialogue']);
        self::assertNull($projection['storyboard'][0]['recommended_duration_seconds']);
        $row = ['request_json' => '{"_pipeline_version":1}', 'result_json' => json_encode($projection),
            'tenant_id' => 7, 'user_id' => 8, 'project_id' => 9, 'task_id' => 'task1'];
        $cleanup = new \ReflectionMethod(AigcShortDramaService::class, 'cleanStoredStoryboardForTaskData');
        $cleanup->setAccessible(true);
        self::assertSame($row, $cleanup->invoke(null, $row));
    }

    public function testFrozenInputRetainsExactSourceAndServerOwnedIdentity(): void
    {
        $input = ApplicationWorkflow::input(7, 8, 9, 'task1', ['prompt' => " 第一行\n末尾😀 ",
            'locked_subject_references' => [['id' => 11, 'name' => '陈默']]], ['id' => 'model1'], []);
        $context = $input['context'];
        self::assertSame(" 第一行\n末尾😀 ", $context['requirements'][0]['text']);
        self::assertSame('library:11', $context['entities'][0]['entity_id']);
        self::assertSame('7:8:9', $context['entity_scope']);
    }
    public function testLegacyWritersAcceptOldTasksButCannotMutateCanonicalTasks(): void
    {
        ApplicationWorkflow::assertLegacyWrite(['request_json' => '{"_generation_version":3}']);
        $this->expectExceptionCode(409);
        ApplicationWorkflow::assertLegacyWrite(['request_json' => '{"_pipeline_version":1}']);
    }
}
