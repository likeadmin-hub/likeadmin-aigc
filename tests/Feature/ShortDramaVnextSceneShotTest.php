<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\GenerationPipeline;
use app\common\service\app\aigc_short_drama\vnext\SceneShotExecution;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextSceneShotTest extends TestCase
{
    public static function input(int $units = 6): array
    {
        $context = ['revision_id' => 'r1', 'episode_id' => 'e1', 'entity_scope' => 't1:p1', 'prompt_projection_version' => 1, 'scene_shot_version' => 1,
            'entities' => [['ref' => 'c1', 'entity_id' => 'char1', 'kind' => 'character', 'name' => '陈默'], ['ref' => 'l1', 'entity_id' => 'room1', 'kind' => 'location', 'name' => '书房']]];
        $rows = [];
        for ($i = 1; $i <= $units; $i++) $rows[] = ['type' => 'dialogue', 'speaker' => 'c1', 'text' => '第' . $i . '句完整对白😀。'];
        $context['canonical_input'] = ContentDocument::normalize(['title' => '完整剧本', 'scenes' => [['location' => 'l1', 'units' => $rows]]], $context);
        return ['context' => $context, 'model' => ['context_window' => 32768, 'max_output_tokens' => 1536],
            'execution' => ['lease_seconds' => 150, 'stage_timeout_seconds' => 100, 'max_output_tokens' => 1536]];
    }
    public static function reply(array $data, bool $missing = false): array
    {
        $document = $data['document']; $units = array_values(ContentDocument::units($document));
        if (!isset($data['shot_plan'])) {
            $reply = ['shots' => array_map(static fn($unit) => ['visual_description' => '人物说出完整对白', 'refs' => [['unit' => $unit['ref']]]], $units)];
        } else {
            $checks = [];
            foreach (['identity', 'continuity', 'shot_fidelity'] as $aspect) $checks[] = ['id' => 'aspect:' . $aspect,
                'status' => $missing && $aspect === 'continuity' ? 'contradiction' : 'covered', 'scope' => 'whole_episode', 'reason' => '核对全文',
                'evidence' => [['unit' => end($units)['ref'], 'start' => 0, 'end' => mb_strlen(end($units)['text']), 'quote' => end($units)['text']]]];
            $reply = ['document_hash' => $document['content_hash'], 'shot_hash' => $data['shot_plan']['content_hash'], 'checks' => $checks];
        }
        return ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop'];
    }
    private function execution(int $capacity, ?array &$saved): array
    {
        return ['capacity' => static fn() => ['max_tokens' => $capacity],
            'plan' => static function ($key, $document, $proposal) use (&$saved) { if ($proposal !== null) $saved = $proposal; return $saved; }];
    }
    public function testCapacityPartitionsAndFinalCompilePreserveEveryDialogueAndAudioUnit(): void
    {
        $input = self::input(); $saved = null; $keys = []; $checkpoints = [];
        $result = GenerationPipeline::run($input['context'], static function ($key, $messages) use (&$keys) {
            $keys[] = $key; return self::reply(json_decode($messages['content'], true));
        }, static function () { self::fail('Unexpected semantic repair'); }, static function ($candidate) use (&$checkpoints) { $checkpoints[] = $candidate; }, $this->execution(1000, $saved));
        self::assertSame(['shots_window_1', 'shots_window_2', 'shots_window_3', 'review'], $keys);
        self::assertSame('ready', $result['coverage_report']['status']);
        self::assertSame($input['context']['canonical_input']['scenes'], $result['document']['scenes']);
        self::assertCount(6, $result['shot_plan']['shots']); self::assertCount(6, $result['shot_plan']['audio_segments']);
        self::assertStringEndsWith('第6句完整对白😀。', end($result['shot_plan']['shots'])['dialogue_preview']);
        self::assertCount(3, array_filter($checkpoints, static fn($candidate) => isset($candidate['shot_windows'])));
        SceneShotExecution::assertPlan($result['document'], $saved);
    }
    public function testExplicitWholeOutputTruncationFallsBackOnceAndKeepsReceipt(): void
    {
        $input = self::input(); $saved = null; $keys = [];
        $result = GenerationPipeline::run($input['context'], static function ($key, $messages) use (&$keys) {
            $keys[] = $key;
            return $key === 'shots' ? ['content' => '{partial', 'finish_reason' => 'length'] : self::reply(json_decode($messages['content'], true));
        }, static function () {}, static function () {}, $this->execution(8192, $saved));
        self::assertSame(['shots', 'shots_window_1', 'shots_window_2', 'review'], $keys);
        self::assertCount(4, $result['receipts']); self::assertSame('ready', $result['coverage_report']['status']);
    }
    public function testTransportAndGenericCapacityErrorsDoNotAuthorizeFallback(): void
    {
        $saved = null; $calls = 0;
        try { GenerationPipeline::run(self::input()['context'], static function () use (&$calls) { $calls++; throw new \RuntimeException('request timeout', 413); },
            static function () {}, static function () {}, $this->execution(8192, $saved)); self::fail('Failure ignored'); }
        catch (\RuntimeException $e) { self::assertSame('request timeout', $e->getMessage()); }
        self::assertSame(1, $calls); self::assertNull($saved);
    }
    public function testCrossWindowReferenceGetsOneFormatRepairWithoutRestartingEarlierWindow(): void
    {
        $saved = null; $keys = [];
        $result = GenerationPipeline::run(self::input()['context'], static function ($key, $messages) use (&$keys) {
            $keys[] = $key; $data = json_decode($messages['content'], true); $data = $data['input'] ?? $data;
            if ($key === 'shots_window_2') return ['content' => '{"shots":[{"visual_description":"错误窗口","refs":[{"unit":"u1"}]}]}'];
            return self::reply($data);
        }, static function () {}, static function () {}, $this->execution(1000, $saved));
        self::assertSame(['shots_window_1', 'shots_window_2', 'shots_window_2_format', 'shots_window_3', 'review'], $keys);
        self::assertSame('ready', $result['coverage_report']['status']);
    }
    public function testWindowCompletionCannotReplaceWholeEpisodeAcceptance(): void
    {
        $saved = null;
        $result = GenerationPipeline::run(self::input()['context'], static fn($key, $messages) => self::reply(json_decode($messages['content'], true), true),
            static function () { self::fail('Windowed semantic repair must not use a short-episode reservation'); }, static function () {}, $this->execution(1000, $saved));
        self::assertSame('draft', $result['coverage_report']['status']); self::assertSame('windowed_content_requires_revision', $result['stop_reason']);
        self::assertCount(6, $result['shot_plan']['shots']);
    }
    public function testMalformedPlanCannotOmitDuplicateOrReorderContent(): void
    {
        $document = self::input()['context']['canonical_input'];
        $plan = SceneShotExecution::partition($document, static fn() => ['max_tokens' => 1000], 1, false);
        foreach (['omit', 'repeat', 'reverse'] as $kind) {
            $bad = $plan;
            if ($kind === 'omit') array_pop($bad['windows']);
            elseif ($kind === 'repeat') $bad['windows'][] = $bad['windows'][0];
            else $bad['windows'] = array_reverse($bad['windows']);
            unset($bad['plan_hash']); $bad['plan_hash'] = ContentDocument::hash($bad);
            try { SceneShotExecution::assertPlan($document, $bad); self::fail('Broken plan accepted'); }
            catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        }
    }
}
