<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Plans;
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
        self::assertSame('原角色', $result['subjects'][1]['description']);
    }

    public function testEmptySelectionHasNoResidualReferenceContext(): void
    {
        self::assertSame([], $this->call('scriptSubjectReferenceContext', ['subject_references' => []]));
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
