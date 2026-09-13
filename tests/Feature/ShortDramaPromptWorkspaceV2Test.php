<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaPromptCatalog as Catalog;
use app\common\service\app\aigc_short_drama\ShortDramaPromptWorkspace as Workspace;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaPromptWorkspaceV2Test extends TestCase
{
    private function call(string $method, ...$args)
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $args);
    }

    public static function baselineCases(): array
    {
        $expected = json_decode(file_get_contents(__DIR__ . '/../fixtures/short_drama_prompt_baseline.json'), true);
        $cases = require __DIR__ . '/../fixtures/short_drama_prompt_cases.php';
        $rows = [];
        foreach ($cases as $name => [$method, $args]) $rows[$name] = [$method, $args, $expected[$name]];
        return $rows;
    }

    /** @dataProvider baselineCases */
    public function testExtractionPreservesDefaultRequests(string $method, array $args, $expected): void
    {
        // JSON numeric representations (60 / 60.0) differ, but all prompt text must be byte-identical.
        self::assertEquals($expected, $this->call($method, ...$args));
        self::assertEquals($expected, Catalog::run(Workspace::resolve(701, [], []), fn() => $this->call($method, ...$args)));
    }

    public function testEveryEditableRuleHasAnActiveRuntimeCallAndChineseDescription(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/app/aigc_short_drama/AigcShortDramaService.php');
        $catalog = Catalog::definition();
        self::assertSame(['script', 'subject', 'scene', 'storyboard', 'music', 'general', 'repair'], array_keys($catalog['groups']));
        foreach ($catalog['items'] as $key => $item) {
            self::assertNotEmpty($item['description'], $key);
            self::assertNotEmpty($item['used_at'], $key);
            self::assertNotEmpty($item['trigger'], $key);
            if ($item['source_method'] === 'workspace') continue;
            self::assertTrue(str_contains($source, "'$key'") || str_contains(json_encode($catalog['systems']), '{{rule:' . $key . '}}'), $key);
            $snapshot = Workspace::resolve(701, ['mode' => 'workspace', 'overrides' => [$key => '租户自定义测试规则']], []);
            self::assertSame('租户自定义测试规则', Catalog::run($snapshot, fn() => Catalog::text($key)));
        }
    }

    public function testInheritanceExplicitEmptyAndSnapshotIsolation(): void
    {
        $platform = ['mode' => 'workspace', 'overrides' => ['scene.image' => '平台场景', 'subject.character' => '平台人物']];
        $snapshot = Workspace::resolve(701, ['mode' => 'workspace', 'revision' => 3, 'overrides' => ['scene.image' => '']], $platform);
        self::assertSame('', $snapshot['values']['scene.image']);
        self::assertSame('tenant', $snapshot['sources']['scene.image']);
        self::assertSame('平台人物', $snapshot['values']['subject.character']);
        self::assertSame('platform', $snapshot['sources']['subject.character']);
        $other = Workspace::resolve(702, [], []);
        self::assertNotSame($snapshot['values']['subject.character'], $other['values']['subject.character']);
        Catalog::run($snapshot, function () use ($other): void {
            self::assertSame('平台人物', Catalog::text('subject.character'));
            try { Catalog::run($other, static function (): void { throw new \RuntimeException('scope test'); }); } catch (\RuntimeException $e) { /* expected */ }
            self::assertSame('平台人物', Catalog::text('subject.character'));
        });
        self::assertNull(Catalog::snapshot());
        self::assertSame($snapshot, Workspace::forTask(701, ['_prompt_snapshot' => $snapshot]));
    }

    public function testLegacyModeKeepsOldRulesUntilExplicitMigration(): void
    {
        $legacy = ['script_system_prompt' => '旧系统消息', 'prompt_config' => ['global_system_prompt' => '旧通用要求', 'subject_image_prompt_template' => "旧包装\n{{prompt}}"]];
        $snapshot = Workspace::resolve(701, [], ['mode' => 'workspace', 'overrides' => ['subject.character' => '平台新规则']], $legacy);
        self::assertSame('legacy', $snapshot['mode']);
        self::assertSame($legacy, $snapshot['legacy_config']);
        $request = Catalog::run($snapshot, fn() => $this->call('shortDramaImageParams', 701, [], ['_prompt_snapshot' => $snapshot, 'subject_name' => '林舟'], 'subject_image', []));
        self::assertStringContainsString('旧包装', $request['prompt']);
        self::assertStringContainsString('旧通用要求', $request['prompt']);
        self::assertStringNotContainsString('平台新规则', $request['prompt']);
    }

    public function testImageRequestUsesReplacementWithoutReappendingOriginalRule(): void
    {
        $snapshot = Workspace::resolve(701, ['mode' => 'workspace', 'overrides' => ['subject.character' => '租户规则：电影柔光，cinematic lighting', 'global_system_prompt' => '通用规则：保持叙事清晰']], []);
        $request = $this->call('shortDramaImageParams', 701, [], ['_prompt_snapshot' => $snapshot, 'subject_name' => '林舟'], 'subject_image', []);
        self::assertStringContainsString('租户规则：电影柔光，cinematic lighting', $request['prompt']);
        self::assertStringNotContainsString(Catalog::defaults()['subject.character'], $request['prompt']);
        self::assertStringContainsString('通用规则：保持叙事清晰', $request['prompt']);
        self::assertStringContainsString('用户本次明确', $request['prompt']);
    }

    public function testChangingOneStageDoesNotModifyUnrelatedRequests(): void
    {
        $snapshot = Workspace::resolve(701, ['mode' => 'workspace', 'overrides' => ['music.mood' => '轻快温暖']], []);
        $baseline = Workspace::resolve(701, [], []);
        $args = [701, [], ['subject_name' => '林舟'], 'subject_image', []];
        $a = Catalog::run($snapshot, fn() => $this->call('shortDramaImageParamsScoped', ...$args));
        $b = Catalog::run($baseline, fn() => $this->call('shortDramaImageParamsScoped', ...$args));
        self::assertSame($a, $b);
    }

    public function testVideoAssemblerKeepsAudioParametersAndAssetTagsProtected(): void
    {
        $snapshot = Workspace::resolve(701, ['mode' => 'workspace', 'overrides' => ['video.consistency' => '租户视频要求：画面细腻', 'negative.video' => '不要压缩噪点']], []);
        $shot = ['shot_id' => 'a', 'subject_ref_ids' => ['s1'], 'scene_ref_id' => 'l1', 'visual_description' => '青年翻开书本', 'recommended_duration_seconds' => 5];
        $plan = ['subjects' => [['id' => 's1', 'name' => '青年']], 'locations' => [['id' => 'l1', 'name' => '书店']]];
        $params = ['duration' => 5, 'model_id' => 'test', 'resolution' => '720p'];
        $references = ['reference_assets' => [], 'input_asset_ids' => [], 'reference_plan' => [], 'generation_method' => 'text_to_video'];
        $result = Catalog::run($snapshot, fn() => $this->call('assembleVideoPromptRequest', 701, $shot, $params, $plan, '9:16', $references, false));
        self::assertSame(false, $result['generate_audio']);
        self::assertSame(5, $result['duration']);
        self::assertStringContainsString('<duration-ms>5000</duration-ms>', $result['prompt']);
        self::assertStringContainsString('租户视频要求', $result['prompt']);
        self::assertStringContainsString('必须静音', $result['prompt']);
        self::assertStringContainsString('不要压缩噪点', $result['negative_prompt']);
    }

    public function testMusicVisionAndRepairUseTheirOwnRules(): void
    {
        $snapshot = Workspace::resolve(701, ['mode' => 'workspace', 'overrides' => ['music.submit' => '采用古典弦乐', 'vision.scene' => '重点描述自然光', 'repair.preserve' => '保留原作叙事视角']], []);
        Catalog::run($snapshot, function (): void {
            self::assertStringContainsString('采用古典弦乐', $this->call('assembleMusicPrompt', '原音乐描述'));
            self::assertStringContainsString('重点描述自然光', $this->call('assembleVisionPrompt', 'scene'));
            $repair = $this->call('assembleRepairPromptRequest', ['storyboard' => []], '用户故事');
            self::assertStringContainsString('保留原作叙事视角', $repair['content']);
            self::assertStringContainsString('只返回合法 JSON', $repair['system_prompt']);
        });
    }

    public function testUnknownFieldsAndVariablesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Catalog::validate(['subject.character' => '{{unknown}}']);
    }

    public function testPublicResponsesRemovePromptDiagnosticsRecursively(): void
    {
        self::assertSame(['prompt' => '用户画面', 'params' => ['duration' => 5]], $this->call('stripPromptDiagnostics', ['prompt' => '用户画面', '_prompt_requests' => ['private'], 'params' => ['_prompt_snapshot' => ['private'], 'duration' => 5]]));
    }

    public function testPreviewContainsNoProviderSubmissionOrStoredPlanCleanup(): void
    {
        $r = new ReflectionMethod(AigcShortDramaService::class, 'previewPromptWorkspace');
        $source = implode('', array_slice(file($r->getFileName()), $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
        self::assertStringNotContainsString('::generate(', $source);
        self::assertStringNotContainsString('self::currentProjectPlanRaw(', $source);
        self::assertStringNotContainsString('->update(', $source);
        self::assertStringNotContainsString('persistBgmAudio(', $source);
    }
}
