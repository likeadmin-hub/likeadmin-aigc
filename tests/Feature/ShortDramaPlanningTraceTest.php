<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\PlanningTrace;
use app\common\service\app\aigc_short_drama\vnext\SourceTrace;
use app\common\service\app\aigc_short_drama\ShortDramaStoryGeneration;
use PHPUnit\Framework\TestCase;

final class ShortDramaPlanningTraceTest extends TestCase
{
    private function source(): array { return SourceTrace::capture('input1', 'v1', '😀第一集交信，第二集才拆信。主角是老师。', 'inspiration'); }
    private function trace(): array
    {
        return PlanningTrace::build($this->source(), [
            ['quote' => '第一集交信', 'kind' => 'event', 'episode_numbers' => [1]],
            ['quote' => '第二集才拆信', 'kind' => 'event', 'episode_numbers' => [2]],
            ['quote' => '主角是老师', 'kind' => 'invariant', 'episode_numbers' => []],
        ], 2);
    }
    private function episodes(array $trace): array
    {
        $episodes = [];
        foreach (['老师把信交给学生。', '学生拆开信。'] as $i => $text) $episodes[] = ['episode_number' => $i + 1, 'title' => '书信' . $i,
            'story_outline' => $text, 'conflict_point' => '信封封口完整', 'ending_hook' => '谁在门外',
            'source_events' => [['requirement_id' => $trace['requirements'][$i]['id'], 'field' => 'story_outline', 'quote' => $text]]];
        return $episodes;
    }
    public function testUniqueExactQuoteCorrectsOffsetWithoutRewritingSource(): void
    {
        $trace = PlanningTrace::build($this->source(), [['quote' => '第一集交信', 'kind' => 'event', 'episode_numbers' => ['1'], 'start' => 0, 'end' => 5]], 2);
        self::assertSame(1, $trace['requirements'][0]['start']); self::assertSame(6, $trace['requirements'][0]['end']);
        self::assertSame($this->source(), $trace['source']); self::assertSame([1], $trace['requirements'][0]['episode_numbers']);
        self::assertSame($this->trace()['requirements'][0]['id'], $trace['requirements'][0]['id']);
    }
    public function testInventedAndAmbiguousQuotesCannotBecomeRequirements(): void
    {
        foreach (['不存在', '重复'] as $quote) {
            try { PlanningTrace::build(SourceTrace::capture('s', 'v', '重复，重复。', 'inspiration'), [['quote' => $quote, 'kind' => 'event']], 2); self::fail('Unreliable evidence accepted'); }
            catch (\RuntimeException $e) { self::assertSame(422, $e->getCode()); }
        }
        $trace = PlanningTrace::build(SourceTrace::capture('s', 'v', '重复，重复。', 'inspiration'),
            [['quote' => '重复', 'kind' => 'event', 'start' => '3', 'end' => '5']], 2);
        self::assertSame(3, $trace['requirements'][0]['start']);
    }
    public function testSplitEventsRetainOriginalEvidenceAndNoFutureScope(): void
    {
        $trace = $this->trace(); $events = PlanningTrace::events($trace, $this->episodes($trace), 'outline1');
        self::assertCount(2, $events); self::assertSame([1, 2], array_column($events, 'episode_number'));
        self::assertSame([$trace['requirements'][0]['id']], $events[0]['requirement_ids']);
        self::assertSame('老师把信交给学生。', $events[0]['text']); self::assertSame(1, $events[0]['start']);
        self::assertSame($trace['source']['hash'], $events[0]['source_hash']);
        self::assertSame('老师把信交给学生。', $events[0]['outline_source']['quote']);
        $episodes = $this->episodes($trace); $episodes[0]['source_events'][0]['requirement_id'] = $trace['requirements'][1]['id'];
        $this->expectExceptionMessage('范围外'); PlanningTrace::events($trace, $episodes, 'outline1');
    }
    public function testMissingAllocationBlocksConfirmationButPreservesEditableOutline(): void
    {
        $trace = $this->trace(); $episodes = $this->episodes($trace); $episodes[1]['source_events'] = [];
        $plan = ['content_trace' => $trace, 'episodes' => $episodes];
        $issues = PlanningTrace::issues(['_source_trace' => $this->source(), 'confirmed_story_snapshot' => ['content_trace' => $trace]], $plan, 'episodes', 2);
        self::assertCount(1, $issues); self::assertStringContainsString('第二集才拆信', $issues[0]['message']);
        self::assertSame([], PlanningTrace::issues([], $plan, 'episodes', 2), 'Historical requests keep their contract');
        self::assertSame($episodes, $plan['episodes']);
    }
    public function testOutlineEditsInvalidateOnlyTheBrokenEvidence(): void
    {
        $trace = $this->trace(); $episodes = $this->episodes($trace); $episodes[0]['story_outline'] = '老师离开教室。';
        $this->expectExceptionMessage('引文不在原文'); PlanningTrace::events($trace, $episodes, 'outline2');
    }
    public function testMalformedModelEvidenceReturnsRepairableIssues(): void
    {
        $trace = $this->trace();
        foreach ([null, [], ['id' => 'invented'], 123, false] as $invalidId) {
            $episodes = $this->episodes($trace);
            $episodes[0]['source_events'][0]['requirement_id'] = $invalidId;
            $issues = PlanningTrace::issues(['_source_trace' => $this->source(), 'confirmed_story_snapshot' => ['content_trace' => $trace]],
                ['content_trace' => $trace, 'episodes' => $episodes], 'episodes', 2);
            self::assertCount(1, $issues);
            self::assertStringContainsString('requirement_id', $issues[0]['message']);
        }
        $episodes = $this->episodes($trace); $episodes[0]['story_outline'] = ['invalid'];
        $issues = PlanningTrace::issues(['_source_trace' => $this->source(), 'confirmed_story_snapshot' => ['content_trace' => $trace]],
            ['content_trace' => $trace, 'episodes' => $episodes], 'episodes', 2);
        self::assertCount(1, $issues);
        self::assertStringContainsString('story_outline', $issues[0]['message']);
    }
    public function testUnscopedCompoundRequirementCanMapToDifferentEpisodeParts(): void
    {
        $trace = PlanningTrace::build($this->source(), [['quote' => '第一集交信，第二集才拆信', 'kind' => 'event', 'episode_numbers' => []]], 2);
        $episodes = $this->episodes($this->trace());
        foreach ($episodes as &$episode) $episode['source_events'][0]['requirement_id'] = $trace['requirements'][0]['id'];
        $events = PlanningTrace::events($trace, $episodes, 'outline');
        self::assertCount(2, $events); self::assertNotSame($events[0]['text'], $events[1]['text']);
        self::assertSame($events[0]['requirement_ids'], $events[1]['requirement_ids']);
    }
    public function testStoryAndOutlineProduceTraceWithoutExtraProviderStages(): void
    {
        $source = $this->source(); $trace = $this->trace();
        $base = ['title' => '书信', 'type_judgement' => '校园', 'core_theme' => '关心', 'story_outline' => '师生传递书信',
            'subjects' => [['id' => 'c1', 'name' => '老师']], 'locations' => [['id' => 'l1', 'name' => '教室']]];
        $request = ['_source_trace' => $source, 'multi_episode_stage' => 'story', 'episode_count' => 2, 'quality_review_enabled' => false];
        $calls = [];
        $provider = static function ($key, $messages) use (&$calls, $base, $trace): array {
            $calls[] = $key; self::assertStringContainsString('source_requirements', $messages['system_prompt']);
            return ['result' => ['content' => json_encode($base + ['source_requirements' => $trace['requirements']])]];
        };
        $story = ShortDramaStoryGeneration::generate($request, ['context_window' => 100000, 'max_tokens' => 16384], static fn() => ['content' => '故事', 'system_prompt' => ''], $provider)['result'];
        self::assertSame(['story'], $calls); self::assertSame($trace, $story['content_trace']);
        $episodes = $this->episodes($trace);
        $request['multi_episode_stage'] = 'episodes'; $request['confirmed_story_snapshot'] = $story;
        $outline = ShortDramaStoryGeneration::generate($request, ['context_window' => 100000, 'max_tokens' => 16384], static fn() => ['content' => '大纲', 'system_prompt' => ''],
            static function ($key, $messages) use (&$calls, $episodes): array {
                $calls[] = $key; self::assertStringContainsString('source_events', $messages['system_prompt']);
                return ['result' => ['content' => json_encode(['episodes' => $episodes])]];
            })['result'];
        self::assertSame(['story', 'outline_1_2'], $calls);
        self::assertSame([], PlanningTrace::issues($request, $outline, 'episodes', 2));
    }
}
