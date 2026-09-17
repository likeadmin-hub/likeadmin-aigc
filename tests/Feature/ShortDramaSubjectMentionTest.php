<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Plans;
use app\common\service\app\aigc_short_drama\ShortDramaStoryDraft;
use PHPUnit\Framework\TestCase;

class ShortDramaSubjectMentionTest extends TestCase
{
    private function call(string $method, ...$args)
    {
        $method = new \ReflectionMethod(Plans::class, $method);
        $method->setAccessible(true);
        return $method->invoke(null, ...$args);
    }

    private function request(): array
    {
        return ['subject_mentions' => ['林远'], 'subject_references' => [[
            'id' => '102', 'name' => '林远', 'description' => '沉默的调查员，穿深色西装',
            'category' => 'character', 'image' => 'https://example.test/subject.png',
            'three_view_image' => 'https://example.test/three-view.png',
            'gender' => 'male', 'age_stage' => 'adult',
            'raw_image' => 'private-storage-key', 'internal_field' => 'not-for-model',
        ]]];
    }

    public function testModelPromptIncludesResolvedSubjectDataForCreationAndRevision(): void
    {
        foreach ([[], ['revision_message' => '重写本集，加入@林远', 'revision_base_result' => ['title' => '原剧本']]] as $revision) {
            foreach (['buildCompactScriptPlanPrompt', 'buildScriptPlanPrompt'] as $builder) {
                $prompt = $this->call($builder, '雨夜重逢', $this->request() + $revision, '测试剧本');
                self::assertStringContainsString('沉默的调查员，穿深色西装', $prompt);
                self::assertStringContainsString('subject_references', $prompt);
                self::assertStringContainsString('subject.png', $prompt);
                self::assertStringContainsString('three-view.png', $prompt);
                self::assertStringContainsString('"gender":"male"', $prompt);
                self::assertStringContainsString('"age_stage":"adult"', $prompt);
                self::assertStringNotContainsString('private-storage-key', $prompt);
                self::assertStringNotContainsString('not-for-model', $prompt);
            }
        }
    }

    public function testGeneratedSubjectKeepsItsIdAndReceivesActualReferenceImages(): void
    {
        $result = $this->call('attachSelectedSubjectReferences', ['subjects' => [
            ['id' => 'subject_1', 'name' => '林远', 'description' => '模型生成的描述'],
            ['id' => 'subject_2', 'name' => '另一个角色', 'description' => '原角色'],
        ]], $this->request());
        self::assertCount(2, $result['subjects']);
        self::assertSame('subject_1', $result['subjects'][0]['id']);
        self::assertSame('102', $result['subjects'][0]['library_subject_id']);
        self::assertSame('https://example.test/subject.png', $result['subjects'][0]['image']);
        self::assertSame('https://example.test/three-view.png', $result['subjects'][0]['three_view_image']);
        self::assertSame('male', $result['subjects'][0]['gender']);
        self::assertSame('adult', $result['subjects'][0]['age_stage']);
        self::assertSame('原角色', $result['subjects'][1]['description']);
    }

    public function testReferenceNameMatchingIgnoresCaseAndDoesNotEraseGeneratedDetails(): void
    {
        $request = ['subject_references' => [[
            'id' => '5', 'name' => 'uana', 'description' => '', 'category' => 'character',
            'image' => 'https://example.test/uana.png', 'raw_image' => 'uana-private-key',
        ]]];
        $result = $this->call('attachSelectedSubjectReferences', ['subjects' => [[
            'id' => 'subject_1', 'name' => 'Uana', 'description' => '科技新贵，目标明确',
            'role' => '主要反派', 'background' => '创办人工智能公司',
        ]]], $request);

        self::assertCount(1, $result['subjects']);
        self::assertSame('subject_1', $result['subjects'][0]['id']);
        self::assertSame('Uana', $result['subjects'][0]['name']);
        self::assertSame('科技新贵，目标明确', $result['subjects'][0]['description']);
        self::assertSame('主要反派', $result['subjects'][0]['role']);
        self::assertSame('5', $result['subjects'][0]['library_subject_id']);
        self::assertSame('https://example.test/uana.png', $result['subjects'][0]['image']);
    }

    public function testGenericLookingMentionNameIsAStableLibraryEntityInPrompt(): void
    {
        $request = ['subject_references' => [[
            'id' => '9', 'name' => '美女', 'description' => '', 'category' => 'character',
            'image' => 'https://example.test/beauty.png', 'gender' => 'unknown', 'age_stage' => 'unknown',
        ]]];

        foreach (['buildCompactScriptPlanPrompt', 'buildScriptPlanPrompt'] as $builder) {
            $prompt = $this->call($builder, '@美女 与帅哥海边邂逅', $request, '测试剧本');
            self::assertStringContainsString('generic Chinese description', $prompt);
            self::assertStringContainsString('identical name and library_subject_id', $prompt);
            self::assertStringContainsString('"name":"美女"', $prompt);
        }

        $result = $this->call('attachSelectedSubjectReferences', ['subjects' => [[
            'id' => 'subject_1', 'name' => '林浅', 'description' => '模型生成角色', 'role' => '女主角',
        ]]], $request);
        self::assertCount(1, $result['subjects']);
        self::assertSame('美女', $result['subjects'][0]['name']);
        self::assertSame('9', $result['subjects'][0]['library_subject_id']);
        self::assertArrayNotHasKey('is_library_reference', $result['subjects'][0]);
        $normalized = $this->call('attachSelectedSubjectReferences', [
            'story_outline' => '林浅在海边散心',
            'series_bible' => ['logline' => '林浅与 LUKE 相遇'],
            'subjects' => [
                ['id' => 'subject_1', 'name' => '林浅', 'description' => '模型生成角色', 'role' => '女主角', 'category' => 'character'],
                ['id' => 'subject_2', 'name' => 'LUKE', 'description' => '模型生成角色', 'role' => '男主角', 'category' => 'character'],
            ],
        ], $request);
        self::assertCount(2, $normalized['subjects']);
        self::assertSame('美女', $normalized['subjects'][0]['name']);
        self::assertSame('美女在海边散心', $normalized['story_outline']);
        self::assertSame('美女与 LUKE 相遇', $normalized['series_bible']['logline']);
    }

    public function testLegacyEmptyLibraryDuplicateIsRemovedBeforeReferenceMatching(): void
    {
        $request = ['subject_references' => [[
            'id' => '5', 'name' => 'uana', 'description' => '', 'category' => 'character',
            'image' => 'https://example.test/uana.png',
        ]]];
        $result = $this->call('attachSelectedSubjectReferences', ['subjects' => [
            ['id' => 'subject_1', 'name' => 'Uana', 'description' => '科技新贵，目标明确', 'role' => '主要反派'],
            ['id' => 'library_5', 'library_subject_id' => '5', 'name' => 'uana', 'description' => '', 'is_library_reference' => true],
        ]], $request);

        self::assertCount(1, $result['subjects']);
        self::assertSame('subject_1', $result['subjects'][0]['id']);
        self::assertSame('Uana', $result['subjects'][0]['name']);
        self::assertSame('科技新贵，目标明确', $result['subjects'][0]['description']);
        self::assertSame('5', $result['subjects'][0]['library_subject_id']);
        self::assertSame('https://example.test/uana.png', $result['subjects'][0]['image']);
        self::assertArrayNotHasKey('is_library_reference', $result['subjects'][0]);
    }

    public function testLegacyPlaceholderDoesNotBlockGenericNameRebinding(): void
    {
        $request = ['subject_references' => [[
            'id' => '9', 'name' => '美女', 'description' => '', 'category' => 'character',
            'image' => 'https://example.test/beauty.png', 'three_view_image' => 'https://example.test/beauty-three.png',
        ]]];
        $result = $this->call('attachSelectedSubjectReferences', [
            'story_outline' => '林悦在海边遇见 LUKE',
            'subjects' => [
                ['id' => 'subject_1', 'name' => '林悦', 'description' => '模型生成女主', 'role' => '女主角', 'category' => 'character'],
                ['id' => 'subject_2', 'name' => 'LUKE', 'description' => '模型生成男主', 'role' => '男主角', 'category' => 'character'],
                ['id' => 'library_9', 'library_subject_id' => '9', 'name' => '美女', 'is_library_reference' => true],
            ],
        ], $request);

        self::assertCount(2, $result['subjects']);
        self::assertSame('subject_1', $result['subjects'][0]['id']);
        self::assertSame('美女', $result['subjects'][0]['name']);
        self::assertSame('9', $result['subjects'][0]['library_subject_id']);
        self::assertSame('https://example.test/beauty.png', $result['subjects'][0]['image']);
        self::assertSame('https://example.test/beauty-three.png', $result['subjects'][0]['three_view_image']);
        self::assertSame('美女在海边遇见 LUKE', $result['story_outline']);
        self::assertArrayNotHasKey('is_library_reference', $result['subjects'][0]);
    }

    public function testReadingLegacyPlanAlsoHidesEmptyLibraryDuplicate(): void
    {
        $result = $this->call('enhancePlanResult', [
            'subjects' => [
                ['id' => 'subject_1', 'name' => 'Uana', 'description' => '科技新贵，目标明确'],
                ['id' => 'library_5', 'library_subject_id' => '5', 'name' => 'uana', 'description' => '', 'image' => 'https://example.test/uana.png', 'is_library_reference' => true],
            ],
            'locations' => [],
            'storyboard' => [],
            'art_style' => [],
        ]);

        self::assertCount(1, $result['subjects']);
        self::assertSame('subject_1', $result['subjects'][0]['id']);
        self::assertSame('https://example.test/uana.png', $result['subjects'][0]['image']);
    }

    public function testStoryDraftAlsoReconcilesLegacyLibraryDuplicates(): void
    {
        $draft = ShortDramaStoryDraft::effective([
            'workflow_variant' => 'story_outline_v2',
            'multi_episode' => true,
            'episode_count' => 3,
            '_story_draft' => ['result' => ['subjects' => [
                ['id' => 'subject_1', 'name' => 'Uana', 'description' => '科技新贵，目标明确'],
                ['id' => 'library_5', 'library_subject_id' => '5', 'name' => 'uana', 'description' => '', 'is_library_reference' => true],
            ]]],
        ], []);

        self::assertCount(1, $draft['subjects']);
        self::assertSame('subject_1', $draft['subjects'][0]['id']);
    }

    public function testEmptySelectionHasNoResidualReferenceContext(): void
    {
        self::assertSame([], $this->call('scriptSubjectReferenceContext', ['subject_references' => []]));
    }

    public function testLockedSubjectSnapshotWinsOverMutableReferenceData(): void
    {
        $request = [
            'subject_references' => [[
                'id' => '9', 'name' => '林浅', 'description' => '不应覆盖已选主体',
                'category' => 'character', 'gender' => 'female',
            ]],
            'locked_subject_references' => [[
                'id' => '9', 'name' => '美女', 'description' => '主体库原始配置',
                'category' => 'character', 'gender' => 'male', 'age_stage' => 'adult',
                'image' => 'https://example.test/beauty.png', 'raw_image' => 'tenant/beauty.png',
                'three_view_image' => 'https://example.test/beauty-three.png', 'three_view_raw_image' => 'tenant/beauty-three.png',
                'browser_only' => 'must not survive',
            ]],
        ];

        $context = $this->call('scriptSubjectReferenceContext', $request);
        self::assertCount(1, $context);
        self::assertSame('美女', $context[0]['name']);
        self::assertSame('male', $context[0]['gender']);
        self::assertArrayNotHasKey('raw_image', $context[0]);

        $result = $this->call('attachSelectedSubjectReferences', ['subjects' => [[
            'id' => 'subject_1', 'name' => '美女', 'description' => '故事角色', 'category' => 'character',
        ]]], $request);
        self::assertSame('美女', $result['subjects'][0]['name']);
        self::assertSame('male', $result['subjects'][0]['gender']);
        self::assertSame('https://example.test/beauty-three.png', $result['subjects'][0]['three_view_image']);

        $frozen = $this->call('lockedSubjectReferences', $request);
        self::assertSame('library_subject:9', $frozen[0]['binding_key']);
        self::assertArrayNotHasKey('browser_only', $frozen[0]);

        $normalizedBrowserRequest = $this->call('normalizeCreateRequest', [
            'prompt' => '@美女',
            'subject_ids' => ['9'],
            'locked_subject_references' => $request['locked_subject_references'],
        ], []);
        self::assertSame([], $normalizedBrowserRequest['locked_subject_references']);

        $reconciled = $this->call('hydratePlanLibrarySubjectReferences', 1, 1, [
            'subjects' => [[
                'id' => 'subject_1', 'library_subject_id' => '9', 'name' => '美女',
                'description' => '故事角色', 'gender' => 'female',
            ]],
        ], $frozen);
        self::assertSame('美女', $reconciled['subjects'][0]['name']);
        self::assertSame('male', $reconciled['subjects'][0]['gender']);
    }

    public function testProjectReferencesResolveOnlyAgainstCurrentPlan(): void
    {
        $subjects = [['id' => 'person1', 'name' => '本剧角色', 'description' => '本剧人物设定']];
        $selected = $this->call('selectedScriptProjectSubjects', ['subjects' => $subjects], ['person1', 'foreign-person']);
        self::assertSame($subjects, $selected);
        self::assertSame([], $this->call('selectedScriptProjectSubjects', ['subjects' => $subjects], []));
        $context = $this->call('scriptSubjectReferenceContext', $this->request() + ['project_subject_references' => $selected]);
        self::assertCount(2, $context);
        self::assertSame('本剧人物设定', $context[1]['description']);
        $hydrated = $this->call('selectedScriptProjectSubjects', ['subjects' => $subjects], ['person1'], [
            ['asset_type' => 'three_view', 'meta' => ['subject_id' => 'person1'], 'url' => 'https://example.test/project-three.png'],
        ]);
        self::assertSame('https://example.test/project-three.png', $hydrated[0]['three_view_image']);
    }
}
