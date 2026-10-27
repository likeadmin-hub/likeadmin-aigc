<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaScriptContext as Context, ShortDramaInputContract as Contract,
    ShortDramaScriptGeneration as Generation, ShortDramaEpisodeDuration as Duration, ShortDramaPlanningBudget as Budget,
    ShortDramaPlanningUnit as Unit, ShortDramaStructuredResponse as Response};
use PHPUnit\Framework\TestCase;

class ShortDramaScriptContextTest extends TestCase
{
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function creative(): array
    {
        $subjects = [['id' => 'lin', 'name' => '林舟', 'description' => str_repeat('戴眼镜，始终保管铜钥匙，未读过信B。', 35)],
            ['id' => 'he', 'name' => '何溪', 'description' => str_repeat('知道信A的内容，不知道信B，不能混为一封信。', 35)]];
        $locations = [['id' => 'room', 'name' => '旧屋', 'description' => str_repeat('门口朝西，桌上只有信A，信B在柜内。', 35)],
            ['id' => 'lane', 'name' => '旧巷', 'description' => '旧屋门外，何溪等待的地方。']];
        return ['user_prompt' => "保留原文：https://example.test/a/b。林舟拿铜钥匙出门，把信A交给何溪；信B留在柜内，第十集才揭晓。\n完整台词：这不是给你的。",
            'revision_message' => '', 'revision_target' => [], 'revision_policy' => ['keep_dialogue' => true],
            'series_context' => ['current_episode' => ['episode_number' => 4, 'title' => '交接', 'ending_hook' => '何溪尚未拆信A'],
                'outline' => ['subjects' => $subjects, 'locations' => $locations, 'story_outline' => '分清两封信，按原顺序交接。'],
                'subjects' => $subjects, 'locations' => $locations,
                'continuity' => ['state' => ['key:owner' => 'lin', 'letter_b:opened' => false, 'letter_b:count' => 0],
                    'open_hooks' => ['letter_b' => '第十集才能揭晓'], 'source_history' => [], 'history' => []]]];
    }

    /** Independent expansion verifies every reference resolves within this single request. */
    private function expand(array $document): array
    {
        $walk = function (mixed $node, array $chain = []) use (&$walk, $document): mixed {
            if (!is_array($node)) return $node;
            if (isset($node['$ref'])) {
                self::assertSame(['$ref'], array_keys($node));
                $pointer = $node['$ref'];
                self::assertStringStartsWith('#/', $pointer);
                self::assertNotContains($pointer, $chain, 'Reference must not form a cycle');
                $value = $document;
                foreach (explode('/', substr($pointer, 2)) as $part) {
                    $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                    self::assertIsArray($value);
                    self::assertArrayHasKey($part, $value, $pointer);
                    $value = $value[$part];
                }
                return $walk($value, array_merge($chain, [$pointer]));
            }
            foreach ($node as $key => $value) $node[$key] = $walk($value, $chain);
            return $node;
        };
        return $walk($document);
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$child) $child = $this->canonical($child);
        unset($child);
        return $value;
    }

    private function generate(int $version, bool $trusted = true): array
    {
        $request = ['_input_contract_version' => $version,
            'episode_duration_policy' => Duration::snapshot([], 16, 0, [])];
        $creative = $this->json($this->creative());
        $messages = ['system_prompt' => "租户自定义：保留信件区别与完整台词。\n" . Duration::instruction($request),
            'content' => $creative, '_stage_content' => $trusted ? $creative : null];
        $skeleton = ['title' => '交接', 'story_outline' => '林舟拿铜钥匙出门，将信A交给何溪，信B仍在柜内。',
            'script_lines' => ['拿钥匙', '交出信A'], 'subjects' => [['id' => 'lin', 'name' => '林舟'], ['id' => 'he', 'name' => '何溪']],
            'locations' => [['id' => 'room', 'name' => '旧屋'], ['id' => 'lane', 'name' => '旧巷']],
            'scene_beats' => [
                ['scene_ref_id' => 'room', 'goal' => '拿钥匙离开', 'entry' => '林舟在旧屋', 'exit' => '走向旧巷',
                    'key_events' => ['拿铜钥匙出门'], 'duration_seconds' => 8, 'shot_durations' => [8]],
                ['scene_ref_id' => 'lane', 'goal' => '交出信A', 'entry' => '林舟走出旧屋', 'exit' => '何溪未拆信A',
                    'key_events' => ['将信A交给何溪'], 'duration_seconds' => 8, 'shot_durations' => [8]],
            ]];
        $calls = [];
        $result = Generation::generate($request, ['context_window' => 65536, 'max_output_tokens' => 8192], $messages,
            function ($key, $input, $budget) use (&$calls, $skeleton): array {
                self::assertArrayNotHasKey('_share_script_context', $input);
                self::assertArrayNotHasKey('_stage_content', $input);
                $calls[] = compact('key', 'input', 'budget');
                if (str_contains($key, 'skeleton')) $payload = $skeleton;
                else {
                    $context = Response::decode(['content' => $input['content']]);
                    $scene = $context['scene_beat']['scene_ref_id'];
                    $payload = ['storyboard' => array_map(static fn($shot) => [
                        'shot_id' => $shot['shot_id'], 'scene_ref_id' => $scene, 'subject_ref_ids' => $scene === 'room' ? ['lin'] : ['lin', 'he'],
                        'scene_name' => $scene === 'room' ? '旧屋' : '旧巷',
                        'visual_description' => $scene === 'room' ? '林舟拿铜钥匙出门，信B留在柜内' : '林舟将信A交给何溪，何溪尚未拆信',
                        'dialogue' => $scene === 'room' ? '' : '这不是给你的。',
                        'voice_role' => $scene === 'room' ? '' : '林舟', 'speech_type' => $scene === 'room' ? 'none' : 'character',
                        'recommended_duration_seconds' => $shot['duration_seconds'],
                    ], $context['required_shots'])];
                }
                return ['result' => ['content' => $this->json($payload), 'finish_reason' => 'stop']];
            });
        return compact('calls', 'result', 'messages');
    }

    public function testRealGenerationBoundaryPreservesAllFactsAndOutputWithSmallerInput(): void
    {
        $old = $this->generate(12); $new = $this->generate(14);
        self::assertSame(array_column($old['calls'], 'key'), array_column($new['calls'], 'key'));
        self::assertCount(3, $new['calls']);
        self::assertSame($old['result']['payload'], $new['result']['payload']);
        self::assertSame($old['calls'][0]['input']['system_prompt'], $new['calls'][0]['input']['system_prompt']);
        foreach ([1, 2] as $index) {
            $before = Response::decode(['content' => $old['calls'][$index]['input']['content']]);
            $after = Response::decode(['content' => $new['calls'][$index]['input']['content']]);
            $before['creative_context'] = json_decode($before['creative_context'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($this->canonical($before), $this->canonical($this->expand($after)));
            self::assertSame($before['creative_context']['user_prompt'], $after['creative_context']['user_prompt']);
            foreach (['current_episode', 'continuity'] as $field) self::assertArrayNotHasKey('$ref', $after['creative_context']['series_context'][$field]);
            foreach (['scene_beat', 'required_shots', 'previous_shots', 'scene_progress'] as $field) self::assertSame($before[$field], $after[$field]);
            self::assertStringContainsString('租户自定义：保留信件区别与完整台词。', $new['calls'][$index]['input']['system_prompt']);
            self::assertSame($old['calls'][$index]['input']['system_prompt'] . "\n" . Context::INSTRUCTION, $new['calls'][$index]['input']['system_prompt']);
            self::assertLessThan($old['calls'][$index]['budget']['input_estimate'], $new['calls'][$index]['budget']['input_estimate']);
            self::assertSame($old['calls'][$index]['budget']['max_tokens'], $new['calls'][$index]['budget']['max_tokens']);
        }
        self::assertSame($this->json($this->creative()), $new['messages']['_stage_content']);
    }

    public function testCustomTemplateIsNotDecodedEvenWhenItLooksLikeJson(): void
    {
        $old = $this->generate(12, false); $new = $this->generate(14, false);
        foreach ([1, 2] as $index) {
            self::assertSame($old['calls'][$index]['input']['content'], $new['calls'][$index]['input']['content']);
            self::assertSame($old['calls'][$index]['input']['system_prompt'], $new['calls'][$index]['input']['system_prompt']);
            self::assertStringNotContainsString(Context::INSTRUCTION, $new['calls'][$index]['input']['system_prompt']);
        }
    }

    public function testOnlyIdenticalDataCanBeSharedAndAllReferencesRemainSelfContained(): void
    {
        $creative = $this->creative();
        $creative['series_context']['subjects'][0]['description'] .= '这条追加要求不能丢失。';
        $context = ['creative_context' => $this->json($creative), 'scene_beat' => ['goal' => '当前场次']];
        $saved = $context;
        $shared = Context::scene($context, ['_input_contract_version' => 13], $context['creative_context']);
        $decoded = json_decode($this->json($shared), true);
        $saved['creative_context'] = $creative;
        self::assertSame($saved, $this->expand($decoded));
        self::assertStringContainsString('追加要求不能丢失', $this->json($shared));
        self::assertSame($context['creative_context'], $this->json($creative));
    }

    public function testOldRequestsAndAmbiguousOrUntrustedDataStayExact(): void
    {
        $context = ['creative_context' => $this->json($this->creative())];
        foreach ([0, 7, 12] as $version) self::assertSame($context, Context::scene($context, ['_input_contract_version' => $version], $context['creative_context']));
        foreach ([null, '{}', 'invalid'] as $trusted) self::assertSame($context, Context::scene($context, ['_input_contract_version' => 13], $trusted));
        foreach (['[]', 'not JSON', '{"literal":{"$ref":"user-authored-data"}}'] as $text) {
            $input = ['creative_context' => $text];
            self::assertSame($input, Context::scene($input, ['_input_contract_version' => 13], $text));
        }
        self::assertFalse(Context::enabled(['_input_contract_version' => 12]));
        self::assertTrue(Context::enabled(Contract::begin([])));
    }

    public function testReferencesEscapeKeysAndPreserveJsonTypes(): void
    {
        $value = ['description' => str_repeat('铜钥匙在林舟手中，不能交给何溪。', 30), 'empty' => [], 'flag' => false, 'count' => 0];
        $creative = $this->json(['a/b~c' => $value, 'copy' => $value]);
        $shared = Context::scene(['creative_context' => $creative], ['_input_contract_version' => 13], $creative);
        $decoded = json_decode($this->json($shared), true);
        self::assertSame('#/creative_context/a~1b~0c', $decoded['creative_context']['copy']['$ref']);
        self::assertSame(json_decode($creative, true), $this->expand($decoded)['creative_context']);
        $objects = '{"empty_object":{},"empty_array":[],"zero":0,"flag":false}';
        $result = Context::scene(['creative_context' => $objects], ['_input_contract_version' => 13], $objects);
        self::assertStringContainsString('"empty_object":{}', $this->json($result));
        self::assertStringContainsString('"empty_array":[]', $this->json($result));
    }

    public function testWorkingMemoryIsFittedBeforeSharingAndRepairSuffixStaysVerbatim(): void
    {
        $creative = $this->creative();
        for ($i = 0; $i < 80; $i++) $creative['series_context']['continuity']['state']['person_' . $i] = str_repeat('已确认的原值与否定事实不得改写。', 15);
        $input = ['system_prompt' => '保留事实', 'content' => $this->json(['creative_context' => $this->json($creative)]) . "\n修复只针对镜头s1，原对白不动。", '_share_script_context' => true];
        Budget::fitMessages($input, [], 'script', 8192, true);
        $before = Response::decode(['content' => $input['content']]);
        $before['creative_context'] = json_decode($before['creative_context'], true);
        $result = Context::messages($input, ['_input_contract_version' => 13]);
        self::assertSame($this->canonical($before), $this->canonical($this->expand(Response::decode(['content' => $result['content']]))));
        self::assertStringEndsWith("\n修复只针对镜头s1，原对白不动。", $result['content']);
        self::assertArrayNotHasKey('_share_script_context', $result);
    }

    public function testSmallRequestsKeepCheaperOriginalAndFrozenSignaturesAreStable(): void
    {
        $input = ['system_prompt' => '规则', 'content' => '{"creative_context":"{\"user_prompt\":\"原文\"}"}', '_share_script_context' => true];
        $expected = $input; unset($expected['_share_script_context']);
        self::assertSame($expected, Context::messages($input, ['_input_contract_version' => 13]));
        $old = $this->generate(12); $again = $this->generate(12);
        self::assertSame($old['calls'], $again['calls']);
        $new = $this->generate(13); $again = $this->generate(13);
        foreach ($new['calls'] as $i => $call) self::assertSame(Unit::requestSignature($call['input']), Unit::requestSignature($again['calls'][$i]['input']));
    }
}
