<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\GenerationPipeline;
use app\common\service\app\aigc_short_drama\vnext\SceneScreenplayExecution;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextSceneScreenplayTest extends TestCase
{
    public static function input(): array
    {
        $input = ShortDramaVnextSceneShotTest::input(); unset($input['context']['canonical_input']);
        $input['context']['scene_shot_version'] = 0;
        $input['context']['scene_screenplay_version'] = 1;
        $input['context']['prompt'] = '陈默在书房先询问，再得到完整答案。';
        return $input;
    }
    public static function proposal(): array
    {
        return ['title' => '完整剧本', 'entities' => [['ref' => 'c2', 'kind' => 'character', 'name' => '小夏', 'identity_facts' => ['role' => '助手']]],
            'scenes' => [['location' => 'l1', 'time_context' => '夜', 'brief' => '陈默询问，小夏回应。', 'target_ids' => []],
                ['location' => 'l1', 'time_context' => '次日', 'brief' => '小夏答复，陈默作出决定。', 'target_ids' => []]]];
    }
    public static function reply(string $key, array $data): array
    {
        if ($key === 'screenplay') return ['content' => '{partial', 'finish_reason' => 'length'];
        if (str_starts_with($key, 'screenplay_plan')) $reply = self::proposal();
        elseif (str_starts_with($key, 'screenplay_scene_')) {
            $n = $data['scene_number']; $reply = ['scene_number' => $n, 'scene' => [
                'location' => 'l1', 'time_context' => $n === 1 ? '夜' : '次日', 'units' => [
                    ['type' => 'dialogue', 'speaker' => 'c1', 'text' => '第' . $n . '场陈默的完整问题😀。'],
                    ['type' => 'dialogue', 'speaker' => 'c2', 'text' => '第' . $n . '场小夏的完整答复😀。']]]];
        } else return ShortDramaVnextSceneShotTest::reply($data);
        return ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop'];
    }
    public static function execution(?array &$plan): array
    {
        return ['screenplay_plan' => static function ($context, $proposal) use (&$plan) {
            if ($proposal !== null) $plan = $proposal;
            return $plan;
        }];
    }
    public function testTruncatedScreenplayBecomesCompleteCanonicalScenesWithStableIdentityAndTrace(): void
    {
        $plan = null; $keys = []; $snapshots = [];
        $result = GenerationPipeline::run(self::input()['context'], static function ($key, $messages) use (&$keys) {
            $keys[] = $key; $data = json_decode($messages['content'], true);
            if ($key === 'screenplay_scene_2') {
                self::assertCount(1, $data['previous_screenplay']['scenes']);
                self::assertSame('第1场小夏的完整答复😀。', $data['previous_screenplay']['scenes'][0]['units'][1]['text']);
                self::assertSame(self::input()['context']['prompt'], $data['context']['prompt']);
            }
            return self::reply($key, $data);
        }, static function () { self::fail('Unexpected repair'); }, static function ($candidate) use (&$snapshots) { $snapshots[] = $candidate; }, self::execution($plan));
        self::assertSame(['screenplay', 'screenplay_plan', 'screenplay_scene_1', 'screenplay_scene_2', 'shots', 'review'], $keys);
        self::assertSame('ready', $result['coverage_report']['status']); self::assertCount(6, $result['receipts']);
        self::assertCount(2, $result['document']['scenes']); self::assertCount(4, $result['shot_plan']['audio_segments']);
        self::assertSame($snapshots[0]['document']['scenes'][0], $result['document']['scenes'][0]);
        self::assertFalse($snapshots[0]['screenplay_windows']['complete']); self::assertTrue($snapshots[1]['screenplay_windows']['complete']);
        self::assertSame('draft', $snapshots[0]['document']['status']);
        $units = array_values(ContentDocument::units($result['document']));
        self::assertSame('char1', $units[0]['speaker_id']); self::assertSame($units[1]['speaker_id'], $units[3]['speaker_id']);
        self::assertCount(3, $result['document']['entities']); self::assertFalse($result['document']['entities'][2]['confirmed']);
        $trace = array_values(array_filter($result['document']['transforms'], static fn($row) => $row['code'] === 'scene_creation'));
        self::assertCount(2, $trace); self::assertSame($plan['plan_hash'], $trace[1]['plan_hash']);
        self::assertSame(array_column($result['document']['scenes'][1]['units'], 'unit_id'), $trace[1]['unit_ids']);
    }
    public function testSuccessfulShortRequestIsIdenticalAndDoesNotCreatePlan(): void
    {
        $context = self::input()['context']; $saved = null; $keys = [];
        GenerationPipeline::run($context, static function ($key, $messages) use ($context, &$keys) {
            $keys[] = $key;
            if ($key === 'screenplay') {
                self::assertSame(GenerationPipeline::messages(GenerationPipeline::creativeContext($context), GenerationPipeline::screenplayInstruction($context), 1), $messages);
                return ['content' => json_encode(['title' => '短集', 'scenes' => [['location' => 'l1', 'units' => [['type' => 'dialogue', 'speaker' => 'c1', 'text' => '结束。']]]]])];
            }
            return self::reply($key, json_decode($messages['content'], true));
        }, static function () {}, static function () {}, self::execution($saved));
        self::assertSame(['screenplay', 'shots', 'review'], $keys); self::assertNull($saved);
    }
    public function testGenericFailureOldVersionAndFaithfulModeCannotStartNewCreativeCalls(): void
    {
        foreach (['transport', 'old', 'faithful'] as $mode) {
            $context = self::input()['context']; $saved = null; $keys = [];
            if ($mode === 'old') unset($context['scene_screenplay_version']);
            if ($mode === 'faithful') $context['source_mode'] = 'faithful';
            try {
                GenerationPipeline::run($context, static function ($key) use ($mode, &$keys) {
                    $keys[] = $key; if ($mode === 'transport') throw new \RuntimeException('capacity failure', 413);
                    return self::reply($key, []);
                }, static function () {}, static function () {}, self::execution($saved)); self::fail('Failure ignored');
            } catch (\RuntimeException $e) { self::assertSame(413, $e->getCode()); }
            self::assertSame(['screenplay'], $keys); self::assertNull($saved);
        }
    }
    public function testPlanMustCoverApplicableRequirementsAndCannotInventTargetsOrIdentities(): void
    {
        $context = self::input()['context']; $context['events'] = [['id' => 'ending'], ['id' => 'later', 'episode_ids' => ['e2']]];
        foreach (['missing', 'unknown', 'duplicate', 'identity', 'too_many', 'empty'] as $mode) {
            $proposal = self::proposal(); $proposal['scenes'][1]['target_ids'] = ['ending'];
            if ($mode === 'missing') $proposal['scenes'][1]['target_ids'] = [];
            if ($mode === 'unknown') $proposal['scenes'][0]['target_ids'] = ['later'];
            if ($mode === 'duplicate') $proposal['scenes'][1]['target_ids'] = ['ending', 'ending'];
            if ($mode === 'identity') $proposal['entities'][] = ['ref' => 'c1', 'kind' => 'character', 'name' => '另一个人'];
            if ($mode === 'too_many') $proposal['scenes'] = array_fill(0, 33, $proposal['scenes'][1]);
            if ($mode === 'empty') $proposal['scenes'] = [];
            try { SceneScreenplayExecution::plan($context, $proposal); self::fail('Invalid plan accepted'); }
            catch (\RuntimeException $e) { self::assertContains($e->getCode(), [409, 422]); }
        }
        $proposal = self::proposal(); $proposal['scenes'][1]['target_ids'] = ['ending'];
        $plan = SceneScreenplayExecution::plan($context, $proposal); SceneScreenplayExecution::assertPlan($context, $plan);
        $context['prompt'] .= '修改'; $this->expectExceptionCode(409); SceneScreenplayExecution::assertPlan($context, $plan);
    }
    public function testSceneFormatRepairIsLocalAndCannotRedefineEarlierScenes(): void
    {
        $saved = null; $keys = [];
        $result = GenerationPipeline::run(self::input()['context'], static function ($key, $messages) use (&$keys) {
            $keys[] = $key; $data = json_decode($messages['content'], true); $data = $data['input'] ?? $data;
            $reply = self::reply($key, $data);
            if ($key === 'screenplay_scene_2') {
                $bad = json_decode($reply['content'], true); $bad['entities'] = [['ref' => 'c1', 'name' => '偷换身份']];
                $reply['content'] = json_encode($bad);
            }
            return $reply;
        }, static function () {}, static function () {}, self::execution($saved));
        self::assertSame(['screenplay', 'screenplay_plan', 'screenplay_scene_1', 'screenplay_scene_2', 'screenplay_scene_2_format', 'shots', 'review'], $keys);
        self::assertSame('ready', $result['coverage_report']['status']);
    }
    public function testSingleSceneTruncationRetainsEarlierContentWithoutRecursivelySplitting(): void
    {
        $saved = null; $keys = []; $snapshots = [];
        try {
            GenerationPipeline::run(self::input()['context'], static function ($key, $messages) use (&$keys) {
                $keys[] = $key;
                return $key === 'screenplay_scene_2' ? ['content' => '{partial', 'finish_reason' => 'length'] : self::reply($key, json_decode($messages['content'], true));
            }, static function () {}, static function ($candidate) use (&$snapshots) { $snapshots[] = $candidate; }, self::execution($saved));
            self::fail('Scene truncation ignored');
        } catch (\RuntimeException $e) { self::assertSame(413, $e->getCode()); }
        self::assertSame(['screenplay', 'screenplay_plan', 'screenplay_scene_1', 'screenplay_scene_2'], $keys);
        self::assertCount(1, $snapshots); self::assertCount(2, ContentDocument::units($snapshots[0]['document']));
        self::assertSame(1, $snapshots[0]['screenplay_windows']['completed']);
    }
    public function testFullReviewCanStillRejectSuccessfullyGeneratedScenes(): void
    {
        $saved = null;
        $result = GenerationPipeline::run(self::input()['context'], static function ($key, $messages) {
            $data = json_decode($messages['content'], true);
            return $key === 'review' ? ShortDramaVnextSceneShotTest::reply($data, true) : self::reply($key, $data);
        }, static function () { throw new \RuntimeException('budget exhausted', 429); }, static function () {}, self::execution($saved));
        self::assertSame('draft', $result['coverage_report']['status']); self::assertSame('budget_exhausted', $result['stop_reason']);
        self::assertCount(4, ContentDocument::units($result['document']));
    }
    public function testSceneMergePreservesUnicodeSourceQuotesAndProductionReferences(): void
    {
        $saved = null; $context = self::input()['context'];
        $source = \app\common\service\app\aigc_short_drama\vnext\SourceTrace::capture('s1', 'v1', '原句😀必须完整保留。', 'inspiration');
        $context['sources'] = ['s1' => $source];
        $link = ['source_id' => 's1', 'source_hash' => $source['hash'], 'start' => 0, 'end' => mb_strlen($source['text']), 'quote' => $source['text']];
        $result = GenerationPipeline::run($context, static function ($key, $messages) use ($link) {
            $data = json_decode($messages['content'], true); $reply = self::reply($key, $data);
            if ($key === 'screenplay_scene_1') {
                $body = json_decode($reply['content'], true);
                $body['scene']['units'][0]['text'] = $link['quote']; $body['scene']['units'][0]['source_links'] = [$link];
                $reply['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
            if ($key === 'screenplay_scene_2') self::assertSame([$link], $data['previous_screenplay']['scenes'][0]['units'][0]['source_links']);
            return $reply;
        }, static function () {}, static function () {}, self::execution($saved));
        $document = $result['document']; $shots = $result['shot_plan'];
        self::assertSame([$link], $document['scenes'][0]['units'][0]['source_links']);
        $assets = []; $timing = [];
        foreach ($document['entities'] as $entity) $assets[$entity['entity_id']] = ['asset_id' => 'asset:' . $entity['entity_id'], 'state_version' => 'v1', 'reference_hash' => 'approved', 'style' => '写实'];
        foreach ($shots['shots'] as $shot) $timing[$shot['shot_id']] = ['version' => 'measured', 'actual_seconds' => 5];
        $production = \app\common\service\app\aigc_short_drama\vnext\ProductionCompiler::compile($document, $shots,
            ['version' => 'v1', 'durations' => [5, 10], 'audio_mode' => 'postproduction'], $timing, $assets, '写实');
        self::assertCount(4, $production['audio_segments']); self::assertSame($source['text'], $production['audio_segments'][0]['text']);
        foreach ($production['fragments'] as $fragment) \app\common\service\app\aigc_short_drama\vnext\ProductionCompiler::assertCurrent($fragment, $document, $shots, $assets);
    }
    public function testImportedCanonicalContentBypassesScenePlanningEvenWithNewVersion(): void
    {
        $context = ShortDramaVnextSceneShotTest::input()['context']; $context['scene_shot_version'] = 0; $context['scene_screenplay_version'] = 1;
        $result = GenerationPipeline::run($context, static fn($key, $messages) => ShortDramaVnextSceneShotTest::reply(json_decode($messages['content'], true)),
            static function () {}, static function () {}, ['screenplay_plan' => static function () { self::fail('Imported text reached creative planner'); }]);
        self::assertSame($context['canonical_input'], $result['document']);
    }
}
