<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaSceneBatch as Batch, ShortDramaInputContract as Contract,
    ShortDramaEpisodeDuration as Duration, ShortDramaScriptGeneration as Script, ShortDramaStructuredResponse as Response};
use PHPUnit\Framework\TestCase;

class ShortDramaSceneBatchTest extends TestCase
{
    public static function fixture(array $counts = [1, 1]): array
    {
        $plan = ['title' => '两封信', 'story_outline' => '甲拿钥匙并将信A交给乙，信B保留在柜内。',
            'script_lines' => ['甲拿钥匙', '甲将信A交给乙'], 'subjects' => [['id' => 'a', 'name' => '甲'], ['id' => 'b', 'name' => '乙']],
            'locations' => [], 'scene_beats' => []];
        foreach ($counts as $index => $count) {
            $number = $index + 1;
            $plan['locations'][] = ['id' => 'l' . $number, 'name' => '地点' . $number];
            $plan['scene_beats'][] = ['scene_ref_id' => 'l' . $number, 'scene_name' => '地点' . $number,
                'goal' => '完成事件' . $number, 'entry' => '承接上场状态' . $index, 'exit' => '完成本场交接' . $number,
                'key_events' => ['事件' . $number], 'duration_seconds' => $count * 6, 'shot_durations' => array_fill(0, $count, 6)];
        }
        return $plan;
    }

    public static function shot(array $spec, array $plan): array
    {
        $scene = $spec['scene_ref_id'];
        return ['shot_id' => $spec['shot_id'], 'scene_ref_id' => $scene,
            'scene_name' => array_column($plan['locations'], 'name', 'id')[$scene], 'subject_ref_ids' => ['a', 'b'],
            'visual_description' => '甲与乙完成当前场景的交接，信B仍留在柜内。',
            'dialogue' => '甲：这是信A。', 'voice_role' => '甲', 'speech_type' => 'character',
            'recommended_duration_seconds' => $spec['duration_seconds']];
    }

    public static function request(array $counts = [1, 1]): array
    {
        $request = Contract::begin(['episode_duration_policy' => Duration::snapshot([], array_sum($counts) * 6, 0, [])]);
        $request['_scene_batch_policy']['enabled'] = true;
        return $request;
    }

    private function messages(bool $trusted = true): array
    {
        $text = json_encode(['user_prompt' => '甲保管铜钥匙，只交出信A，信B留在柜内，第十集才能揭晓。',
            'series_context' => ['current_episode' => ['episode_number' => 4],
                'continuity' => ['state' => ['key:owner' => 'a'], 'open_hooks' => ['letter_b' => '第十集揭晓']]]], JSON_UNESCAPED_UNICODE);
        return ['system_prompt' => '租户创作规则：区分信A和信B，保持对白和场景顺序。', 'content' => $text, '_stage_content' => $trusted ? $text : null];
    }

    private function generate(array $counts, array $request, ?callable $alter = null, bool $trusted = true): array
    {
        $plan = self::fixture($counts); $calls = [];
        $result = Script::generate($request, ['context_window' => 65536, 'max_output_tokens' => 8192], $this->messages($trusted),
            static function ($key, $input) use ($plan, &$calls, $alter): array {
                $calls[] = compact('key', 'input');
                if (str_contains($key, 'skeleton')) $payload = $plan;
                else {
                    $context = Response::decode(['content' => $input['content']]);
                    $specs = $context['required_shots'];
                    if (!isset($context['scene_groups'])) foreach ($specs as &$spec) $spec['scene_ref_id'] = $context['scene_beat']['scene_ref_id'];
                    unset($spec);
                    $payload = ['storyboard' => array_map(static fn($spec) => self::shot($spec, $plan), $specs)];
                }
                if ($alter) $payload = $alter($key, $payload, $input);
                return isset($payload['_response']) ? ['result' => $payload['_response']]
                    : ['result' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop']];
            });
        return compact('result', 'calls');
    }

    public function testPairsKeepOriginalScenesShotsAndDialogueWhileReducingCalls(): void
    {
        $counts = [1, 1, 2, 2, 3]; $newRequest = self::request($counts); $oldRequest = $newRequest;
        $oldRequest['_input_contract_version'] = 13;
        $old = $this->generate($counts, $oldRequest); $new = $this->generate($counts, $newRequest);
        self::assertCount(6, $old['calls']); self::assertCount(4, $new['calls']);
        self::assertSame($old['result']['payload']['storyboard'], $new['result']['payload']['storyboard']);
        self::assertSame(['v3_timed_skeleton_0', 'v3_timed_batch_1_2', 'v3_timed_batch_3_4', 'v3_timed_scene_5_1'], array_column($new['calls'], 'key'));
        foreach (array_slice($new['calls'], 1, 2) as $call) {
            $context = Response::decode(['content' => $call['input']['content']]);
            self::assertCount(2, $context['scene_groups']);
            self::assertLessThanOrEqual(4, count($context['required_shots']));
            foreach ($context['scene_groups'] as $scene) {
                self::assertTrue($scene['scene_progress']['at_scene_start']);
                self::assertTrue($scene['scene_progress']['at_scene_end']);
                self::assertArrayNotHasKey('$ref', $scene);
                self::assertSame($scene['scene_beat']['shot_durations'], array_column($scene['required_shots'], 'duration_seconds'));
            }
            self::assertArrayNotHasKey('_share_script_context', $call['input']);
            self::assertStringContainsString('租户创作规则', $call['input']['system_prompt']);
        }
    }

    /** @dataProvider badScene */
    public function testOnlyRejectedSceneIsRegeneratedAndAcceptedSceneIsKept(int $bad): void
    {
        $result = $this->generate([1, 1], self::request(), static function ($key, $payload) use ($bad) {
            if ($key === 'v3_timed_batch_1_2') $payload['storyboard'][$bad]['recommended_duration_seconds'] = 7;
            return $payload;
        });
        self::assertSame(['v3_timed_skeleton_0', 'v3_timed_batch_1_2', 'v3_timed_scene_' . ($bad + 1) . '_1'], array_column($result['calls'], 'key'));
        self::assertSame(['s1_1', 's2_1'], array_column($result['result']['payload']['storyboard'], 'shot_id'));
        self::assertSame(1, $result['result']['payload']['timing_diagnostics']['content_repairs']);
        self::assertStringContainsString('本场已收到的内容', $result['calls'][2]['input']['content']);
        if ($bad === 0) self::assertStringContainsString('相邻后场已保留', $result['calls'][2]['input']['content']);
    }
    public static function badScene(): array { return [[0], [1]]; }

    public function testMalformedReceivedBatchFallsBackButRefusalDoesNot(): void
    {
        foreach ([['content' => '{"storyboard":[', 'finish_reason' => 'stop'], ['content' => '{}', 'finish_reason' => 'length']] as $response) {
            $result = $this->generate([1, 1], self::request(), static fn($key, $payload) => $key === 'v3_timed_batch_1_2' ? ['_response' => $response] : $payload);
            self::assertSame(['v3_timed_skeleton_0', 'v3_timed_batch_1_2', 'v3_timed_scene_1_1', 'v3_timed_scene_2_1'], array_column($result['calls'], 'key'));
            self::assertCount(2, $result['result']['payload']['storyboard']);
        }
        $this->expectExceptionCode(403);
        $this->generate([1, 1], self::request(), static fn($key, $payload) => $key === 'v3_timed_batch_1_2' ? ['_response' => ['finish_reason' => 'refusal']] : $payload);
    }

    /** @dataProvider terminalErrors */
    public function testUnknownBillingCancellationAndPreflightErrorsNeverTriggerFallback(int $code): void
    {
        $seen = [];
        try {
            $this->generate([1, 1], self::request(), static function ($key, $payload) use (&$seen, $code) {
                $seen[] = $key;
                if ($key === 'v3_timed_batch_1_2') throw new \RuntimeException('provider or preflight boundary', $code);
                return $payload;
            });
            self::fail('Must retain uncertain state');
        } catch (\RuntimeException $error) { self::assertSame($code, $error->getCode()); }
        self::assertSame(['v3_timed_skeleton_0', 'v3_timed_batch_1_2'], $seen);
    }
    public static function terminalErrors(): array { return [[0], [403], [409], [413], [425], [429]]; }

    public function testUnknownDuplicateReorderedAndCrossBoundSceneIdsAreRejected(): void
    {
        $plan = self::fixture(); $request = self::request();
        $first = self::shot(['shot_id' => 's1_1', 'scene_ref_id' => 'l1', 'duration_seconds' => 6], $plan);
        $second = self::shot(['shot_id' => 's2_1', 'scene_ref_id' => 'l2', 'duration_seconds' => 6], $plan);
        foreach ([[$second, $first], [$first, $first, $second], [$first, $second, array_replace($second, ['shot_id' => 'unknown'])]] as $shots) {
            self::assertSame([null, null], Batch::partition(['storyboard' => $shots], $plan, [0, 1], $request));
        }
        $second['scene_ref_id'] = 'l1'; $second['scene_name'] = '地点1';
        $parts = Batch::partition(['storyboard' => [$first, $second]], $plan, [0, 1], $request);
        self::assertNotNull($parts[0]); self::assertNull($parts[1]);
        self::assertNotNull(Batch::partition(['storyboard' => [$first]], $plan, [0, 1], $request)[0]);
    }

    public function testComplexScenesTimelinesCustomTemplatesAndSmallModelsStaySeparate(): void
    {
        $request = self::request(); $messages = $this->messages(); $plan = self::fixture();
        foreach (['闪回', '回忆', '梦境', '时间跳跃', '重大反转', str_repeat('完整剧情', 120), '“' . str_repeat('完整台词', 25) . '”'] as $complex) {
            $changed = $plan; $changed['scene_beats'][0]['goal'] = $complex;
            self::assertSame([], Batch::plan($request, $messages, $changed, 4)['groups']);
        }
        self::assertSame([], Batch::plan($request, $this->messages(false), $plan, 4)['groups']);
        self::assertSame([], Batch::plan($request, $messages, $plan, 1)['groups']);
        $timeline = $request;
        $timeline['episode_duration_policy'] = Duration::snapshot([], 0, 0, [
            ['start_seconds' => 0, 'end_seconds' => 6, 'duration_seconds' => 6], ['start_seconds' => 6, 'end_seconds' => 12, 'duration_seconds' => 6]]);
        self::assertSame([], Batch::plan($timeline, $messages, $plan, 4)['groups']);
        $custom = $this->generate([1, 1], $request, null, false);
        self::assertCount(3, $custom['calls']);
        $old = $request; $old['_input_contract_version'] = 13;
        self::assertSame([], Batch::plan($old, $messages, $plan, 4)['groups']);
        unset($request['_scene_batch_policy']);
        self::assertSame([], Batch::plan($request, $messages, $plan, 4)['groups']);
    }

    public function testFrozenPlanIsPassedThroughRealScriptBoundaryBeforeAnyBatchCall(): void
    {
        $plan = self::fixture(); $request = self::request(); $frozen = false;
        $this->expectExceptionMessage('stop at provider');
        Script::generate($request, ['context_window' => 65536, 'max_output_tokens' => 8192], $this->messages(),
            static function ($key) use ($plan, &$frozen) {
                if (!str_contains($key, 'skeleton')) {
                    self::assertTrue($frozen);
                    throw new \RuntimeException('stop at provider', 409);
                }
                return ['result' => ['content' => json_encode($plan), 'finish_reason' => 'stop']];
            }, null, null, static function ($snapshot) use (&$frozen) {
                $frozen = true; self::assertSame([[0, 1]], $snapshot['groups']);
                self::assertSame(4, $snapshot['part_size']);
                return $snapshot;
            });
    }

    public function testOldSkeletonWithWrongPlaceOrJourneyAndMalformedEventsCannotBeBatched(): void
    {
        $request = self::request(); $messages = $this->messages(); $plan = self::fixture();
        $plan['locations'] = [['id' => 'l1', 'name' => '老屋'], ['id' => 'l2', 'name' => '社区资料室']];
        $plan['scene_beats'][0]['scene_name'] = '社区资料室';
        $plan['scene_beats'][0]['scene_ref_id'] = 'l2';
        $plan['scene_beats'][0]['entry'] = '甲在老屋拆开信件';
        self::assertSame([], Batch::plan($request, $messages, $plan, 4)['groups']);
        $plan = self::fixture(); $plan['scene_beats'][0]['goal'] = '甲前往资料室';
        self::assertSame([], Batch::plan($request, $messages, $plan, 4)['groups']);
        $plan['scene_beats'][0]['goal'] = '当前事件'; $plan['scene_beats'][0]['key_events'] = [['event' => '非字符串']];
        self::assertSame([], Batch::plan($request, $messages, $plan, 4)['groups']);
        $plan = self::fixture();
        $snapshot = Batch::plan($request, $messages, $plan, 4);
        foreach ([['groups' => [[1, 0]]], ['groups' => [[0, 1], [0, 1]]], ['part_size' => 0], ['signature' => 'changed']] as $change) {
            try { Batch::restore($snapshot, array_replace($snapshot, $change), $plan); self::fail('Corrupt plan cannot restart paid work'); }
            catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
        }
    }
}
