<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaAttachmentSource as Source;
use app\common\service\app\aigc_short_drama\vnext\AttachmentScreenplay as Import;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\GenerationPipeline;
use app\common\service\app\aigc_short_drama\vnext\ProductionCompiler;
use app\common\service\app\aigc_short_drama\vnext\SeriesContext;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextAttachmentTest extends TestCase
{
    public static function context(string $text = "第1集\n场景：书房\n动作（陈默）：陈默走近书桌。\n陈默：我记得你😀。\n\n旁白：故事还未结束。\n"): array
    {
        $context = ShortDramaVnextSceneShotTest::input()['context']; unset($context['canonical_input']);
        $package = ShortDramaAttachmentSourceTest::capture($text);
        $episode = Source::bind($package, [['episode_number' => 1, 'title' => '完整剧本']])[0];
        $import = Import::prepare($package, $episode, $context['entities']);
        return array_replace($context, ['source_mode' => 'faithful', 'sources' => [$import['source']['source_id'] => $import['source']],
            'structured_screenplay' => $import['screenplay'], 'attachment_import' => $import]);
    }

    public function testImportSkipsCreativeGenerationAndRetainsEverySourceRangeAndAudio(): void
    {
        $context = self::context(); $stages = [];
        $result = GenerationPipeline::run($context, static function ($key, $messages) use (&$stages) {
            $stages[] = $key; return ShortDramaVnextSceneShotTest::reply(json_decode($messages['content'], true));
        }, static function () { self::fail('Unexpected text repair'); }, static function () {});
        self::assertSame(['shots', 'review'], $stages); self::assertSame('ready', $result['coverage_report']['status']);
        $document = $result['document']; $units = array_values(ContentDocument::units($document));
        self::assertSame(['陈默走近书桌。', '我记得你😀。', '故事还未结束。'], array_column($units, 'text'));
        self::assertSame('char1', $units[1]['speaker_id']); self::assertNull($units[2]['speaker_id']);
        self::assertContains('char1', $units[0]['entity_ids']);
        $trace = end($document['transforms']); $cursor = 0;
        foreach ($trace['layout'] as $span) { self::assertSame($cursor, $span['start']); $cursor = $span['end']; }
        self::assertSame(mb_strlen($context['attachment_import']['source']['text']), $cursor);
        foreach ($units as $unit) {
            $link = $unit['source_links'][0]; self::assertSame($unit['text'], $link['quote']);
            self::assertSame($unit['text'], ContentDocument::slice($context['attachment_import']['source']['text'], $link['start'], $link['end']));
        }
        $timings = []; foreach ($result['shot_plan']['shots'] as $shot) $timings[$shot['shot_id']] = ['actual_seconds' => 4, 'version' => 'test'];
        $assets = []; foreach ($document['entities'] as $entity) $assets[$entity['entity_id']] = ['asset_id' => $entity['entity_id'], 'state_version' => '1', 'reference_hash' => 'fixture', 'style' => '写实'];
        $production = ProductionCompiler::compile($document, $result['shot_plan'], ['version' => 'test', 'durations' => [5], 'audio_mode' => 'postproduction'], $timings, $assets, '写实');
        self::assertSame(['我记得你😀。', '故事还未结束。'], array_column($production['audio_segments'], 'text'));
        self::assertSame($document['content_hash'], $production['document_hash']);
        self::assertSame($units[1]['unit_id'], $production['audio_segments'][0]['unit_id']);
    }

    public function testSemanticFailureCannotRewriteImportedText(): void
    {
        $context = self::context(); $calls = 0;
        $result = GenerationPipeline::run($context, static function ($key, $messages) use (&$calls) {
            $calls++; return ShortDramaVnextSceneShotTest::reply(json_decode($messages['content'], true), true);
        }, static function () { self::fail('Protected source was rewritten'); }, static function () {});
        self::assertSame(2, $calls); self::assertSame('source_content_requires_confirmation', $result['stop_reason']);
        self::assertSame(Import::document($context)['scenes'], $result['document']['scenes']);
    }

    /** @dataProvider invalidText */
    public function testAmbiguousFormatNeverInventsPeopleScenesOrNarration(string $text): void
    {
        $this->expectException(\RuntimeException::class); $this->expectExceptionCode(422); self::context($text);
    }
    public static function invalidText(): array
    {
        return [["第1集\n陈默：你好"], ["第1集\n场景：书房\n陌生人：你好"], ["第1集\n场景：未知地点\n陈默：你好"],
            ["第1集\n场景：书房\n没有标注用途的正文"], ["第1集\n场景：书房\n陈默：你好\n场景：书房"],
            ["第1集\n场景：书房\n陈默："], ["第1集\n场景：书房\n动作：有人走近。"]];
    }

    public function testChangedSourceAndFrozenStructureAreRejected(): void
    {
        $context = self::context(); $context['structured_screenplay']['scenes'][0]['units'][0]['text'] = '改写';
        $this->expectException(\RuntimeException::class); $this->expectExceptionCode(409); Import::document($context);
    }

    public function testUploadsRemainOnLegacyPathUntilExplicitlyEnabledAndFrozen(): void
    {
        $request = ['script_source' => 'upload', 'confirmed_story_snapshot' => ['title' => '故事'], 'confirmed_outline_snapshot' => []];
        self::assertNull(SeriesContext::capture(1, 2, 3, 't', $request, [], ['series_enabled' => true]));
        self::assertNull(SeriesContext::capture(1, 2, 3, 't', $request, [], ['series_enabled' => true, 'attachment_enabled' => true]));
    }

    private static function projectedResult(): array
    {
        $document = Import::document(self::context());
        $plan = \app\common\service\app\aigc_short_drama\vnext\ShotCompiler::compile($document,
            ['shots' => [['visual_description' => '书房', 'refs' => [['unit' => 'u1'], ['unit' => 'u2'], ['unit' => 'u3']]]]], 'projection');
        return \app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow::project(
            ['document' => $document, 'shot_plan' => $plan, 'coverage_report' => []]);
    }

    public function testCanonicalPublicationAndNestedReadsPreserveUnicodeWhileLegacyCleaningStaysCompatible(): void
    {
        $service = \app\common\service\app\aigc_short_drama\AigcShortDramaService::class;
        $encode = new \ReflectionMethod($service, 'jsonEncode'); $encode->setAccessible(true);
        $sanitize = new \ReflectionMethod($service, 'sanitizeUtf8Payload'); $sanitize->setAccessible(true);
        $result = self::projectedResult(); $wrapped = ['result' => $result, 'legacy_text' => '旧字段😀'];
        $json = $encode->invoke(null, $wrapped); $restored = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($result, $restored['result']);
        self::assertSame($result, $sanitize->invoke(null, $wrapped)['result']);
        self::assertSame('旧字段', $restored['legacy_text']);
        self::assertStringContainsString('\\ud83d\\ude00', strtolower($json));
        self::assertStringContainsString('😀', $restored['result']['script_lines']);
        self::assertStringContainsString('😀', $restored['result']['storyboard'][0]['dialogue']);
    }

    public function testChangedCanonicalTextCannotBePublishedUnderItsOldHash(): void
    {
        $result = self::projectedResult(); $result['canonical_document']['scenes'][0]['units'][1]['text'] = '删去内容';
        $encode = new \ReflectionMethod(\app\common\service\app\aigc_short_drama\AigcShortDramaService::class, 'jsonEncode');
        $encode->setAccessible(true); $this->expectException(\RuntimeException::class); $this->expectExceptionCode(409);
        $encode->invoke(null, ['result' => $result]);
    }
}
