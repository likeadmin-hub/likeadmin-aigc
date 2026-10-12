<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaPlanningBudget as Budget;
use app\common\service\app\aigc_short_drama\ShortDramaScriptGeneration as Generation;
use PHPUnit\Framework\TestCase;

class ShortDramaEmbeddedContextBudgetTest extends TestCase
{
    private function messages(int $episodes): array
    {
        $state = [];
        for ($episode = 1; $episode <= $episodes; $episode++) {
            for ($fact = 0; $fact < 8; $fact++) {
                $state["person_{$episode}:fact_{$fact}"] = str_repeat("第{$episode}集确认的角色经历与道具归属。", 7);
            }
        }
        $context = json_encode([
            'user_prompt' => '只展开本集，保留后续悬念。原文链接 https://example.test/story 不得更改。',
            'series_context' => ['current_episode' => ['episode_number' => $episodes + 1],
                'continuity' => ['state' => $state, 'open_hooks' => ['key' => '铜钥匙仍在林舟手中']]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['system_prompt' => '生成本集，不改写已确认历史。',
            'content' => "Create a complete Chinese short-drama story plan from the context.\nContext: " . $context
                . "\nJSON schema: {\"storyboard\":[]}\n仅修复指定字段，不能改写对白。",
            '_stage_content' => $context];
    }

    public function testWrappedHistoricalContextFitsWithoutChangingInstructionsOrStoredFacts(): void
    {
        foreach ([false, true] as $bounded) {
            $messages = $this->messages(100);
            $original = $messages;
            $before = json_decode($original['_stage_content'], true);
            $budget = Budget::fitMessages($messages, [], 'script', 8192, $bounded);
            self::assertSame(8192, $budget['max_tokens']);
            self::assertLessThan(15000, $budget['input_estimate']);
            self::assertSame($original['system_prompt'], $messages['system_prompt']);
            self::assertSame(str_replace($original['_stage_content'], $messages['_stage_content'], $original['content']), $messages['content']);
            $after = json_decode($messages['_stage_content'], true);
            self::assertSame($before['user_prompt'], $after['user_prompt']);
            self::assertSame($before['series_context']['current_episode'], $after['series_context']['current_episode']);
            self::assertSame(800, $after['series_context']['continuity']['memory_scope']['state']['total']);
            foreach ($after['series_context']['continuity']['state'] as $key => $fact) {
                self::assertSame($before['series_context']['continuity']['state'][$key], $fact);
            }
            self::assertSame($this->messages(100), $original);
        }
    }

    public function testFittingPaidPromptIsNotRewritten(): void
    {
        foreach ([false, true] as $bounded) {
            $messages = $this->messages(2);
            $original = $messages;
            Budget::fitMessages($messages, [], 'script', 8192, $bounded);
            self::assertSame($original['content'], $messages['content']);
        }
    }

    public function testUntrustedOrAmbiguousTemplateDoesNotGetRewritten(): void
    {
        foreach (['custom', 'duplicate', 'different', 'no_space'] as $case) {
            $messages = $this->messages(100);
            $model = [];
            if ($case === 'custom') $messages['_stage_content'] = null;
            if ($case === 'duplicate') $messages['content'] .= $messages['_stage_content'];
            if ($case === 'different') $messages['_stage_content'] = '{"user_prompt":"不同的上下文"}';
            if ($case === 'no_space') $model = ['context_window' => 2048];
            $original = $messages;
            try {
                Budget::fitMessages($messages, $model, 'script', 8192, true);
                self::fail('Must keep the capacity guard: ' . $case);
            } catch (\RuntimeException $error) {
                self::assertSame(413, $error->getCode());
                self::assertSame($original, $messages);
            }
        }
    }

    public function testGenerationProjectsBeforeRemovingPrivateContextMetadata(): void
    {
        $messages = $this->messages(100);
        $calls = [];
        $payload = ['title' => '本集', 'story_outline' => '林舟保管铜钥匙。',
            'subjects' => [['id' => 'p', 'name' => '林舟']],
            'locations' => [['id' => 'room', 'name' => '房间']],
            'storyboard' => [['shot_id' => 's1', 'scene_ref_id' => 'room', 'subject_ref_ids' => ['p'],
                'visual_description' => '林舟握住铜钥匙。', 'dialogue' => '', 'voice_role' => '',
                'speech_type' => 'none', 'recommended_duration_seconds' => 6.0]]];
        $result = Generation::generate(['_input_contract_version' => 7], [], $messages,
            static function ($key, $input, $budget) use (&$calls, $payload) {
                $calls[] = $key;
                self::assertArrayNotHasKey('_stage_content', $input);
                self::assertLessThan(15000, $budget['input_estimate']);
                self::assertStringContainsString('memory_scope', $input['content']);
                self::assertStringEndsWith('仅修复指定字段，不能改写对白。', $input['content']);
                return ['result' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop']];
            });
        self::assertSame(['v3_script'], $calls);
        self::assertSame($payload, $result['payload']);
    }
}
