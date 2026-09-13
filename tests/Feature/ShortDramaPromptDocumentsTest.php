<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use app\common\service\app\aigc_short_drama\ShortDramaPromptCatalog as Catalog;
use app\common\service\app\aigc_short_drama\ShortDramaPromptDocuments as Documents;
use app\common\service\app\aigc_short_drama\ShortDramaPromptWorkspace as Workspace;
use PHPUnit\Framework\TestCase;

class ShortDramaPromptDocumentsTest extends TestCase
{
    public function testUnconfirmedStoryDoesNotSendEditableEpisodeCountToModel(): void
    {
        foreach ([[], ['script' => ['mode' => 'custom', 'body' => '按用户灵感创作故事设定']]] as $settings) {
            $prompts = [];
            foreach ([3, 100, 500] as $count) {
                $request = ['workflow_variant' => 'story_outline_v2', 'multi_episode' => true,
                    'multi_episode_stage' => 'story', 'episode_count' => $count,
                    'episode_total_count' => $count, 'episode_batch_end' => $count,
                    'revision_base_result' => ['episode_count' => $count, 'title' => '旧宅']];
                $prompts[] = Catalog::run($this->snapshot($settings), fn() => $this->call('assembleScriptPromptRequest', 701, '调查旧宅的秘密', $request, '旧宅'));
                $template = $this->call('renderScriptPlanPromptTemplate', '{{request_json}} {{episode_count}} {{episode_total_count}} {{revision_base_result}}', '', '', $request, '');
                self::assertStringNotContainsString('"episode_count"', $template);
                self::assertStringContainsString('尚未确认', $template);
                self::assertSame($count, $request['episode_count']);
            }
            self::assertSame($prompts[0], $prompts[1]);
            self::assertSame($prompts[0], $prompts[2]);
            self::assertStringNotContainsString('"episode_count"', $prompts[0]['content']);
            self::assertStringContainsString('不按集数分配剧情', $prompts[0]['system_prompt']);
        }
        $outline = $this->call('buildCompactScriptPlanPrompt', '调查旧宅的秘密', ['workflow_variant' => 'story_outline_v2',
            'multi_episode' => true, 'multi_episode_stage' => 'episodes', 'episode_count' => 5, 'episode_total_count' => 101], '旧宅');
        self::assertStringContainsString('"episode_total_count":101', $outline);
    }

    private function call(string $method, ...$args) { $r = new \ReflectionMethod(Service::class, $method); $r->setAccessible(true); return $r->invokeArgs(null, $args); }
    private function snapshot(array $settings = []): array { return Workspace::resolve(701, ['mode' => 'documents', 'document_settings' => $settings], []); }
    public function testApplicationModePreservesAllExistingDefaultBaselines(): void
    {
        $expected = json_decode(file_get_contents(__DIR__ . '/../fixtures/short_drama_prompt_baseline.json'), true);
        foreach (require __DIR__ . '/../fixtures/short_drama_prompt_cases.php' as $name => [$method, $args]) {
            self::assertEquals($expected[$name], Catalog::run($this->snapshot(), fn() => $this->call($method, ...$args)), $name);
        }
    }
    public function testEachDocumentReachesItsActualAssemblerAndReplacesDefaults(): void
    {
        $shot = ['shot_id' => '1', 'subject_ref_ids' => ['s1'], 'visual_description' => '用户画面内容：青年开门', 'recommended_duration_seconds' => 5];
        $params = ['subject_name' => '青年', 'duration' => 5, 'resolution' => '720p', 'model_id' => 'mock', 'prompt' => '用户明确要求：保留中文水印与 English text'];
        $refs = ['reference_assets' => [], 'input_asset_ids' => [], 'reference_plan' => [], 'generation_method' => 'text_to_video'];
        foreach (Documents::definition()['documents'] as $id => $spec) {
            $marker = 'CUSTOM_' . $id . '_保持一致性 English watermark';
            $snapshot = $this->snapshot([$id => ['mode' => 'custom', 'body' => $marker]]);
            $result = Catalog::run($snapshot, function () use ($id, $params, $shot, $refs) {
                return match ($id) {
                    'script', 'storyboard' => $this->call('assembleScriptPromptRequest', 701, '用户明确要求保留 60 秒全部节点', [], '测试'),
                    'vision_subject', 'vision_scene' => ['prompt' => $this->call('assembleVisionPrompt', $id === 'vision_scene' ? 'scene' : 'character')],
                    'music' => $this->call('assembleMusicPromptRequest', [], []),
                    'shot_video' => $this->call('assembleVideoPromptRequest', 701, $shot, $params, [], '9:16', $refs, true),
                    default => $this->call('shortDramaImageParamsScoped', 701, $shot, $params, $id === 'subject_views' ? 'three_view' : $id, []),
                };
            });
            $text = implode("\n", array_intersect_key($result, array_flip(['prompt', 'content', 'system_prompt', 'negative_prompt'])));
            self::assertSame(1, substr_count($text, $marker), $id);
            foreach ($spec['sections'] as $keys) foreach ($keys as $key) {
                $default = Catalog::defaults()[$key];
                if (mb_strlen($default) > 25) self::assertStringNotContainsString($default, $text, "$id replaces $key");
            }
            self::assertNull(Catalog::snapshot());
        }
    }
    public function testConditionalDocumentsAreNotUnconditionallyCombined(): void
    {
        $body = "通用\n【适用：人物主体】\n人物标记\n【适用：物品主体】\n物品标记\n【适用：物品描述缺失时】\n缺失标记";
        $snapshot = $this->snapshot(Documents::validate(['subject_image' => ['mode' => 'custom', 'body' => $body]]));
        Catalog::run($snapshot, function (): void {
            self::assertSame("通用\n\n人物标记", Documents::render('subject_image'));
            self::assertSame("通用\n\n物品标记", Documents::render('subject_image', ['prop' => true]));
            self::assertStringContainsString('缺失标记', Documents::render('subject_image', ['prop' => true, 'missing' => true]));
        });
    }
    public function testApplicationRestoreBypassesPlatformAndTasksKeepFrozenValues(): void
    {
        $platform = Workspace::resolve(0, ['mode' => 'documents', 'document_settings' => ['scene_image' => ['mode' => 'custom', 'body' => '平台场景']]], []);
        $inherited = Workspace::resolve(701, ['mode' => 'documents'], $platform);
        self::assertSame('平台场景', $inherited['documents']['scene_image']['body']);
        $restored = Workspace::resolve(701, ['mode' => 'documents', 'document_settings' => ['scene_image' => ['mode' => 'application']]], $platform);
        self::assertSame(Documents::body('scene_image'), $restored['documents']['scene_image']['body']);
        self::assertSame($inherited, Workspace::forTask(701, ['_prompt_snapshot' => $inherited]));
        self::assertNotSame($inherited['fingerprint'], $restored['fingerprint']);
    }
    public function testUnrelatedStageAndHistoricalTextAreNotDeleted(): void
    {
        $args = [701, [], ['subject_name' => '青年'], 'subject_image', []];
        $before = Catalog::run($this->snapshot(), fn() => $this->call('shortDramaImageParamsScoped', ...$args));
        $after = Catalog::run($this->snapshot(['subject_views' => ['mode' => 'custom', 'body' => '三视图改变']]), fn() => $this->call('shortDramaImageParamsScoped', ...$args));
        self::assertSame($before, $after);
        self::assertSame(['image_prompt' => '保持一致性，不要水印'], Documents::withoutGeneratedDefaults(['image_prompt' => '保持一致性，不要水印']));
        self::assertSame([], Documents::withoutGeneratedDefaults(['image_prompt' => '系统生成', '_prompt_field_sources' => ['image_prompt' => 'application']]));
    }
    public function testInvalidConditionsAreRejectedNotSilentlyIgnored(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Documents::validate(['subject_image' => ['mode' => 'custom', 'body' => "【适用：打错了】\n规则"]]);
    }

    public function testActualVideoConditionsKeepBindingsDurationAndExplicitNegative(): void
    {
        $body = "视频通用\n【适用：空镜】\n空镜条件标记\n【适用：多个主体】\n多主体标记\n【适用：人物镜头有首帧】\n人物首帧标记\n【适用：空镜有首帧】\n空镜首帧标记\n【适用：有尾帧】\n尾帧标记";
        $snapshot = $this->snapshot(['shot_video' => ['mode' => 'custom', 'body' => $body]]);
        $params = ['duration' => 5, 'model_id' => 'mock', 'resolution' => '720p', 'negative_prompt' => '用户本次明确负面'];
        $refs = ['reference_assets' => [], 'input_asset_ids' => [], 'reference_plan' => [], 'generation_method' => 'start_end', 'first_frame_image' => true, 'last_frame_image' => true];
        foreach ([false, true] as $empty) {
            $shot = ['shot_id' => '1', 'subject_ref_ids' => $empty ? [] : ['s1', 's2'], 'shot_type' => $empty ? '空镜' : '中景', 'visual_description' => '用户当前画面'];
            $r = Catalog::run($snapshot, fn() => $this->call('assembleVideoPromptRequest', 701, $shot, $params, [], '9:16', $refs, true));
            self::assertStringContainsString($empty ? '空镜首帧标记' : '人物首帧标记', $r['prompt']);
            self::assertStringNotContainsString($empty ? '人物首帧标记' : '空镜首帧标记', $r['prompt']);
            self::assertStringContainsString('尾帧标记', $r['prompt']);
            self::assertStringContainsString('<duration-ms>5000</duration-ms>', $r['prompt']);
            self::assertSame('用户本次明确负面', $r['negative_prompt']);
            self::assertTrue($r['generate_audio']);
        }
    }

    public function testPostProcessingPreservesAuthoredContentAndDoesNotInventMusicPreferences(): void
    {
        $snapshot = $this->snapshot(['storyboard' => ['mode' => 'custom', 'body' => '租户要求'], 'music' => ['mode' => 'custom', 'body' => '租户音乐要求']]);
        Catalog::run($snapshot, function (): void {
            $text = '手持镜头 English watermark 保持一致性';
            self::assertSame($text, $this->call('normalizeReadableShotVideoPrompt', $text, ['recommended_duration_seconds' => 3]));
            $music = $this->call('normalizeMusicPlan', [], [], [], [], '剧情资料');
            self::assertSame('', $music['global_bgm_prompt']);
            self::assertArrayNotHasKey('instruments', $music);
            $request = $this->call('assembleMusicPromptRequest', [], ['music_plan' => $music, 'story_outline' => '剧情资料']);
            self::assertStringContainsString('租户音乐要求', $request['prompt']);
            self::assertStringNotContainsString('钢琴', $request['prompt']);
        });
    }
    public function testMigrationKeepsRawTextAndFlagsIneffectiveMusicNegative(): void
    {
        $old = Workspace::resolve(701, ['mode' => 'workspace', 'overrides' => ['subject.character' => '原文 English', 'music.negative' => '无效负面原文']], []);
        $draft = Documents::migration($old);
        self::assertStringContainsString('原文 English', $draft['settings']['subject_image']['body']);
        self::assertSame('music.negative', $draft['issues'][0]['key']);
        self::assertTrue($draft['required']);
    }
}
