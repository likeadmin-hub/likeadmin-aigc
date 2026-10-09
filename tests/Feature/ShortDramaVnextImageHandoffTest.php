<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument as Document;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use app\common\service\app\aigc_short_drama\vnext\ImageHandoff;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextImageHandoffTest extends TestCase
{
    private function fixture(): array
    {
        $document = Document::normalize(['title' => '送信', 'entities' => [
            ['ref' => 'c1', 'kind' => 'character', 'name' => '陈默', 'description' => '蓝色外套'],
            ['ref' => 'l1', 'kind' => 'location', 'name' => '书房'], ['ref' => 'p1', 'kind' => 'prop', 'name' => '信封']],
            'scenes' => [['location' => 'l1', 'units' => [['type' => 'action', 'text' => '陈默拿起信封。', 'characters' => ['c1'], 'props' => ['p1']]]]]],
            ['revision_id' => 'r1', 'episode_id' => 'e1', 'entity_scope' => 'tenant:1:project:1']);
        $plan = ShotCompiler::compile($document, ['shots' => [['visual_description' => '陈默拿着信封', 'refs' => [['unit' => 'u1']]]]], 's1');
        return ['document' => $document, 'shot_plan' => $plan, 'coverage_report' => ['status' => 'ready']];
    }
    public function testPropsAreBoundAndNoRequiredReferenceCanBeSilentlyDropped(): void
    {
        $candidate = $this->fixture(); $targets = ImageHandoff::targets($candidate, '写实');
        self::assertCount(3, $targets[3]['entity_ids']);
        $this->expectExceptionCode(409);
        ImageHandoff::compile($candidate, '写实', 'shot_image', $targets[3]['target_id'], 'r1', []);
    }
    public function testTraceLinksTheExactUnicodeUnitAndDetectsTampering(): void
    {
        $candidate = $this->fixture(); $targets = ImageHandoff::targets($candidate, '写实');
        $input = ImageHandoff::compile($candidate, '写实', 'subject_image', $targets[0]['target_id'], 'r1');
        ImageHandoff::assertIntact($input);
        self::assertSame(7, $input['trace']['unit_refs'][0]['end']);
        self::assertSame($candidate['document']['content_hash'], $input['trace']['document_hash']);
        $input['prompt'] = 'changed'; $this->expectExceptionCode(409); ImageHandoff::assertIntact($input);
    }
    public function testReuseStateIgnoresPromptCompilerAndRevisionButChangesWithAppearanceOrStyle(): void
    {
        $candidate = $this->fixture(); $first = ImageHandoff::targets($candidate, '写实')[0]['state_hash'];
        $unit = $candidate['document']['scenes'][0]['units'][0];
        $candidate['document'] = Document::patch($candidate['document'], [['unit_id' => $unit['unit_id'], 'before_hash' => $unit['text_hash'], 'text' => '陈默放下信封。']], 'r2');
        $candidate['shot_plan'] = ShotCompiler::compile($candidate['document'], ['shots' => [['visual_description' => '放下信封', 'refs' => [['unit' => 'u1']]]]], 's2');
        self::assertSame($first, ImageHandoff::targets($candidate, '写实')[0]['state_hash']);
        self::assertNotSame($first, ImageHandoff::targets($candidate, '动画')[0]['state_hash']);
        $this->expectExceptionCode(409);
        ImageHandoff::compile($candidate, '写实', 'subject_image', $candidate['document']['entities'][0]['entity_id'], 'r1');
    }
}
