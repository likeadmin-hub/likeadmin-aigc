<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\GenerationPipeline;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextPipelineTest extends TestCase
{
    private function context(): array
    {
        return ['revision_id' => 'r1', 'episode_id' => 'e1', 'entity_scope' => 't1:p1', 'prompt' => '陈默向老师问好',
            'requirements' => [['id' => 'r:hello', 'text' => '向老师问好', 'blocking' => true]],
            'entities' => [['ref' => 'c1', 'entity_id' => 'c1id', 'kind' => 'character', 'name' => '陈默'],
                ['ref' => 'l1', 'entity_id' => 'l1id', 'kind' => 'location', 'name' => '书房']]];
    }
    private function response(string $key, array $messages, bool $bad = false): array
    {
        $input = json_decode($messages['content'], true); $input = $input['input'] ?? $input;
        if (str_starts_with($key, 'screenplay')) {
            $data = ['title' => '问好', 'scenes' => [['location' => 'l1', 'units' => [['type' => 'dialogue', 'speaker' => 'c1', 'text' => $bad ? '再见。' : '老师您好。']]]]];
        } elseif (str_contains($key, 'shots')) {
            $data = ['shots' => [['visual_description' => '陈默读信', 'refs' => [['unit' => 'u1']]]]];
        } elseif (str_contains($key, 'review')) {
            $unit = $input['document']['scenes'][0]['units'][0]; $checks = [];
            foreach (['r:hello', 'aspect:identity', 'aspect:continuity', 'aspect:shot_fidelity'] as $id) {
                $checks[] = ['id' => $id, 'status' => $bad && $id === 'r:hello' ? 'missing' : 'covered', 'scope' => 'whole_episode',
                    'reason' => '正文实际表达', 'evidence' => [['unit' => 'u1', 'start' => 0, 'end' => mb_strlen($unit['text']), 'quote' => $unit['text']]]];
            }
            $data = ['document_hash' => $input['document']['content_hash'], 'shot_hash' => $input['shot_plan']['content_hash'], 'checks' => $checks];
        } else {
            $unit = $input['document']['scenes'][0]['units'][0];
            $data = ['patches' => [['unit_id' => $unit['unit_id'], 'before_hash' => $unit['text_hash'], 'text' => '老师您好。']]];
        }
        return ['result' => ['content' => json_encode($data, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop']];
    }
    public function testNormalShortEpisodeUsesExactlyThreeCallsAndAlwaysReviews(): void
    {
        $keys = []; $saved = [];
        $result = GenerationPipeline::run($this->context() + ['quality_review_enabled' => false], function ($key, $input) use (&$keys) {
            $keys[] = $key; return $this->response($key, $input);
        }, static function () { self::fail('Normal generation reserved repair budget'); }, static function ($value) use (&$saved) { $saved[] = $value; });
        self::assertSame(['screenplay', 'shots', 'review'], $keys); self::assertCount(3, $saved);
        self::assertSame('ready', $result['coverage_report']['status']);
    }
    public function testFormatRepairIsBoundedAndDoesNotRestartAcceptedStages(): void
    {
        $keys = [];
        $result = GenerationPipeline::run($this->context(), function ($key, $input) use (&$keys) {
            $keys[] = $key;
            return $key === 'shots' ? ['result' => ['content' => '{broken']] : $this->response($key, $input);
        }, static function () { self::fail('Format repair is not a semantic repair round'); }, static function () {});
        self::assertSame(['screenplay', 'shots', 'shots_format', 'review'], $keys);
        self::assertSame('ready', $result['coverage_report']['status']);
    }
    public function testValidatedStructuredInputSkipsCreativeRewrite(): void
    {
        $context = $this->context();
        $context['structured_screenplay'] = ['title' => '上传原文', 'scenes' => [['location' => 'l1', 'units' => [
            ['type' => 'dialogue', 'speaker' => 'c1', 'text' => '老师您好，最后这句也必须保留。'],
        ]]]];
        $keys = [];
        $result = GenerationPipeline::run($context, function ($key, $input) use (&$keys) { $keys[] = $key; return $this->response($key, $input); }, static function () {}, static function () {});
        self::assertSame(['shots', 'review'], $keys);
        self::assertSame('老师您好，最后这句也必须保留。', $result['document']['scenes'][0]['units'][0]['text']);
    }
    public function testRepairReservesReplanningAndReviewBeforePatching(): void
    {
        $operations = [];
        $result = GenerationPipeline::run($this->context(), function ($key, $input, $reservation) use (&$operations) {
            $operations[] = $key; return $this->response($key, $input, in_array($key, ['screenplay', 'review'], true));
        }, static function ($key, $calls) use (&$operations) { self::assertSame(3, $calls); $operations[] = 'reserve:' . $key; }, static function () {});
        self::assertSame(['screenplay', 'shots', 'review', 'reserve:repair_1', 'repair_1', 'repair_1_shots', 'repair_1_review'], $operations);
        self::assertSame('ready', $result['coverage_report']['status']); self::assertSame('r1_r1', $result['document']['revision_id']);
    }
    public function testFaithfulInputCannotBeAutomaticallyRewrittenByAReview(): void
    {
        $context = $this->context();
        $context['structured_screenplay'] = ['title' => '用户原文', 'scenes' => [['location' => 'l1', 'units' => [
            ['type' => 'dialogue', 'speaker' => 'c1', 'text' => '再见。'],
        ]]]];
        $keys = [];
        $result = GenerationPipeline::run($context, function ($key, $input) use (&$keys) { $keys[] = $key; return $this->response($key, $input, true); },
            static function () { self::fail('Source rewrite was reserved without authorization'); }, static function () {});
        self::assertSame(['shots', 'review'], $keys); self::assertSame('source_content_requires_confirmation', $result['stop_reason']);
        self::assertSame('再见。', $result['document']['scenes'][0]['units'][0]['text']);
    }
    public function testBudgetExhaustionRetainsBestCandidate(): void
    {
        $result = GenerationPipeline::run($this->context(), fn($key, $input) => $this->response($key, $input, true),
            static function () { throw new \RuntimeException('budget', 429); }, static function () {});
        self::assertSame('draft', $result['coverage_report']['status']); self::assertSame('budget_exhausted', $result['stop_reason']);
        self::assertSame('再见。', $result['document']['scenes'][0]['units'][0]['text']);
    }
    public function testUnchangedRepairDoesNotEnterAnInfiniteLoop(): void
    {
        $keys = [];
        $result = GenerationPipeline::run($this->context(), function ($key, $input) use (&$keys) {
            $keys[] = $key;
            if ($key === 'repair_1') return ['result' => ['content' => '{"patches":[]}']];
            return $this->response($key, $input, true);
        }, static function () {}, static function () {});
        self::assertSame('unchanged_content', $result['stop_reason']); self::assertCount(4, $keys);
    }
    public function testTruncatedProviderOutputCannotPublishPartialContent(): void
    {
        $this->expectExceptionCode(413);
        GenerationPipeline::run($this->context(), static fn() => ['result' => ['content' => '{"title":"x"}', 'finish_reason' => 'length']], static function () {}, static function () {});
    }
    public function testManualRevisionRetainsUnitIdentityAndCannotBeSilentlyRepaired(): void
    {
        $initial = GenerationPipeline::run($this->context(), fn($key, $input) => $this->response($key, $input), static function () {}, static function () {});
        $unit = $initial['document']['scenes'][0]['units'][0];
        $input = \app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow::revisionInput(['context' => $this->context()], $initial,
            [['unit_id' => $unit['unit_id'], 'before_hash' => $unit['text_hash'], 'text' => '再见。']], 'r1', 'next_task');
        $calls = [];
        $result = GenerationPipeline::run($input['context'], function ($key, $input) use (&$calls) {
            $calls[] = $key; return $this->response($key, $input, true);
        }, static function () { self::fail('A reviewer cannot authorize overwriting manual edits'); }, static function () {});
        self::assertSame(['shots', 'review'], $calls);
        self::assertSame($unit['unit_id'], $result['document']['scenes'][0]['units'][0]['unit_id']);
        self::assertSame('再见。', $result['document']['scenes'][0]['units'][0]['text']);
        self::assertSame('source_content_requires_confirmation', $result['stop_reason']);
    }
}
