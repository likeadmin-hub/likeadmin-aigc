<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\PlanningTrace as Trace;
use app\common\service\app\aigc_short_drama\vnext\SourceTrace;
use app\common\service\app\aigc_short_drama\ShortDramaStoryGeneration;
use PHPUnit\Framework\TestCase;

final class ShortDramaPlanningRevisionTraceTest extends TestCase
{
    private function base(): array
    {
        $source = SourceTrace::capture('input', 'initial', '主角是老师。第一集交信，第二集拆信。', 'inspiration');
        $trace = Trace::build($source, [
            ['quote' => '主角是老师', 'kind' => 'invariant'],
            ['quote' => '第一集交信', 'kind' => 'event', 'episode_numbers' => [1]],
            ['quote' => '第二集拆信', 'kind' => 'event', 'episode_numbers' => [2]],
        ], 2);
        return ['title' => '书信', 'type_judgement' => '校园', 'core_theme' => '关心', 'story_outline' => '传递书信',
            'subjects' => [['id' => 'c1', 'name' => '老师']], 'locations' => [['id' => 'l1', 'name' => '教室']], 'content_trace' => $trace];
    }
    private function request(array $base, string $message = '主角改为医生', string $task = 'revision1'): array
    {
        return Trace::revisionRequest(['_source_trace' => $base['content_trace']['source'], 'episode_count' => 2,
            'multi_episode_stage' => 'story', 'quality_review_enabled' => false], $base, $task, $message, 'story');
    }
    public function testRevisionPreservesUnmentionedRequirementsAndOriginalSources(): void
    {
        $base = $this->base(); $request = $this->request($base); $old = $base['content_trace']['requirements'];
        $rows = [['quote' => '主角改为医生', 'kind' => 'invariant', 'supersedes' => [$old[0]['id']]]];
        $result = Trace::story($request, $base + ['source_requirements' => $rows]); $trace = $result['content_trace'];
        self::assertSame($base['content_trace']['source'], $trace['source']);
        self::assertSame(array_slice($old, 1), array_slice($trace['requirements'], 0, 2));
        self::assertSame('主角改为医生', $trace['requirements'][2]['text']);
        self::assertSame('revision_revision1', $trace['requirements'][2]['source_id']);
        self::assertSame([$old[0]], $trace['replacements'][0]['removed']);
        self::assertSame([], Trace::issues($request, $result, 'story', 2));
        $next = $this->request($result, '画面简洁', 'revision2');
        $nextResult = Trace::story($next, $result + ['source_requirements' => [['quote' => '画面简洁', 'kind' => 'advice']]]);
        self::assertCount(3, Trace::sources($nextResult['content_trace']));
        self::assertSame($trace['requirements'], array_slice($nextResult['content_trace']['requirements'], 0, 3));
        self::assertSame($trace['replacements'], $nextResult['content_trace']['replacements']);
        self::assertSame($old, $base['content_trace']['requirements'], 'The source version is never rewritten');
    }
    public function testOmissionDoesNotDeleteAndExplicitRemovalLeavesEvidence(): void
    {
        $base = $this->base(); $request = $this->request($base, '删除第一集交信要求');
        $result = Trace::story($request, $base + ['source_requirements' => []]);
        self::assertSame($base['content_trace']['requirements'], $result['content_trace']['requirements']);
        $result = Trace::story($request, $base + ['source_requirements' => [['action' => 'remove', 'quote' => '删除第一集交信要求',
            'supersedes' => [$base['content_trace']['requirements'][1]['id']]]]]);
        self::assertCount(2, $result['content_trace']['requirements']);
        self::assertSame([], $result['content_trace']['replacements'][0]['added_requirement_ids']);
        self::assertSame('第一集交信', $result['content_trace']['replacements'][0]['removed'][0]['quote']);
    }
    public function testInvalidSupersessionAndInventedLatestQuotesAreRepairable(): void
    {
        $base = $this->base(); $request = $this->request($base);
        foreach ([['quote' => '主角改为医生', 'supersedes' => [['id' => 'invalid']]], ['quote' => '主角改为医生', 'supersedes' => ['unknown']],
            ['quote' => '主角是老师'], ['quote' => '主角改为医生', 'action' => 'remove']] as $row) {
            try { Trace::story($request, $base + ['source_requirements' => [$row + ['kind' => 'invariant']]]); self::fail('Invalid change accepted'); }
            catch (\RuntimeException $error) { self::assertSame(422, $error->getCode()); }
        }
    }
    public function testRevisionGenerationUsesTheSameStoryCallAndCanAdvance(): void
    {
        $base = $this->base(); $request = $this->request($base); $calls = [];
        $result = ShortDramaStoryGeneration::generate($request, ['context_window' => 100000, 'max_tokens' => 16384],
            static fn() => ['system_prompt' => '', 'content' => '修改故事'],
            static function ($key, $messages) use (&$calls, $base): array {
                $calls[] = $key; self::assertStringContainsString('supersedes', $messages['system_prompt']);
                return ['result' => ['content' => json_encode($base + ['source_requirements' => [['quote' => '主角改为医生', 'kind' => 'invariant',
                    'supersedes' => [$base['content_trace']['requirements'][0]['id']]]]])]];
            })['result'];
        self::assertSame(['story'], $calls);
        unset($request['_trace_revision']); $request['confirmed_story_snapshot'] = $result;
        self::assertSame($result['content_trace'], Trace::outlineTrace($request), 'Advancement does not invent a source');
    }
    public function testOutlineRevisionLocksStoryRequirementsAndAppendsOnlyOneScopedSource(): void
    {
        $base = $this->base(); $request = ['_source_trace' => $base['content_trace']['source'], 'confirmed_story_snapshot' => $base,
            'revision_target' => ['type' => 'episode', 'id' => 2]];
        $request = Trace::revisionRequest($request, $base, 'outline2', '第二集拆信后停在沉默，不说话', 'episodes');
        $trace = Trace::outlineTrace($request);
        self::assertSame($base['content_trace']['requirements'], $trace['requirements']);
        self::assertSame([2], $trace['sources'][1]['episode_numbers']);
        self::assertSame($trace, Trace::outlineTrace($request), 'Replay does not duplicate messages');
        $edited = $trace; $edited['sources'][1]['text'] = '伪造';
        $this->expectException(\RuntimeException::class); Trace::assertTrace($edited);
    }
    public function testUnknownSourceCannotEnterManualDraftAndLegacyRequestsStayUnchanged(): void
    {
        self::assertSame([], Trace::revisionRequest([], [], 'task', 'message', 'story'));
        $base = $this->base(); $request = $this->request($base);
        $trace = Trace::story($request, $base + ['source_requirements' => []])['content_trace'];
        $this->expectExceptionMessage('未知输入来源');
        Trace::rebuild($trace, [['source_id' => 'forged', 'quote' => '伪造', 'kind' => 'event']], 2);
    }
}
