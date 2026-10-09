<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaAttachmentSource as Source;
use app\common\service\app\aigc_short_drama\ShortDramaSourceBoundary as Boundary;
use app\common\service\app\aigc_short_drama\ShortDramaImportedScript as Imported;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use PHPUnit\Framework\TestCase;

final class ShortDramaAttachmentSourceTest extends TestCase
{
    public static function capture(string $text): array
    {
        $path = tempnam(sys_get_temp_dir(), 'attachment_'); file_put_contents($path, $text);
        try { return Source::capture($path, 'txt', 2000000719, 7, 'attachment_test'); }
        finally { unlink($path); }
    }

    public function testHeadingBindingsRestoreCompleteTextDespiteTruncatedOrCrossEpisodeProviderExcerpts(): void
    {
        $raw = "\xEF\xBB\xBF人物设定：陈默\r\n第1集\r\n我记得你😀。\r\n\r\n\r\n结尾保留。\r\n第2集\r\n完整后续。";
        $package = self::capture($raw); Source::assertPackage($package);
        self::assertSame(hash('sha256', $raw), $package['file_sha256']);
        self::assertSame("人物设定：陈默\n第1集\n我记得你😀。\n\n\n结尾保留。\n第2集\n完整后续。", $package['source']['text']);
        $episodes = Source::bind($package, [['episode_number' => 1, 'content' => '漏掉结尾'], ['episode_number' => 2, 'content' => $raw]]);
        self::assertSame("第1集\n我记得你😀。\n\n\n结尾保留。\n", $episodes[0]['source_content']);
        self::assertSame("第2集\n完整后续。", $episodes[1]['source_content']);
        foreach ($episodes as $episode) {
            $trace = $episode['attachment_trace'];
            self::assertSame($episode['source_content'], ContentDocument::slice($package['source']['text'], $trace['start'], $trace['end']));
            self::assertSame('episode_heading', $trace['binding']); Source::assertEpisode($episode);
        }
        self::assertSame($episodes, Boundary::episodes($episodes));
        $outline = ['episodes' => $episodes, 'episode_count' => 2];
        self::assertSame($episodes, Imported::confirmedOutline($outline, ['episode_count' => 2])['episodes']);
        $request = ['series_context' => ['current_episode' => $episodes[0]]]; Boundary::assertRequest($request);
        $request['series_context']['current_episode']['source_content'] .= '被后改';
        $this->expectException(\RuntimeException::class); $this->expectExceptionCode(409); Boundary::assertRequest($request);
    }

    public function testUnheadedSourceNeedsExactCompleteUniqueMapping(): void
    {
        $package = self::capture("开始😀\n\n中段。\n最后。\n");
        $episodes = Source::bind($package, [['episode_number' => 1, 'source_content' => "开始😀\r\n\r\n中段。"], ['episode_number' => 2, 'content' => '最后。']]);
        self::assertSame('exact_text', $episodes[0]['attachment_trace']['binding']);
        self::assertSame("开始😀\n\n中段。", $episodes[0]['source_content']);
        self::assertSame($episodes, Boundary::episodes($episodes));
    }

    /** @dataProvider invalidMappings */
    public function testUnreliableSourceMappingsFailWithoutChangingSnapshot(string $text, array $episodes): void
    {
        $source = self::capture($text); $before = $source;
        try { Source::bind($source, $episodes); self::fail('Unverified mapping accepted'); }
        catch (\RuntimeException $e) { self::assertSame(422, $e->getCode()); self::assertSame($before, $source); }
    }
    public static function invalidMappings(): array
    {
        return [
            ['第一段。第二段。结尾。', [['episode_number'=>1, 'content'=>'第一段。'], ['episode_number'=>2, 'content'=>'结尾。']]],
            ['第一段。结尾。', [['episode_number'=>1, 'content'=>'第一段。']]],
            ['相同。相同。', [['episode_number'=>1, 'content'=>'相同。'], ['episode_number'=>2, 'content'=>'相同。']]],
            ['完整原文。', [['episode_number'=>1, 'content'=>'模型重写。']]],
            ["第1集\n甲\n第2集\n乙", [['episode_number'=>1, 'content'=>'甲']]],
            ["第2集\n乙\n第1集\n甲", [['episode_number'=>1], ['episode_number'=>2]]],
        ];
    }

    public function testFrozenSnapshotCorruptionIsRejected(): void
    {
        $source = self::capture('完整原文'); $source['source']['text'] .= '改动';
        $this->expectException(\RuntimeException::class); $this->expectExceptionCode(409); Source::assertPackage($source);
    }

    public function testUtf16InputConvertsLosslesslyAndRetainsOriginalByteHash(): void
    {
        $text = "第一集😀\r\n对白。"; $bytes = "\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $source = self::capture($bytes);
        self::assertSame('UTF-16LE', $source['extraction']['encoding']);
        self::assertTrue($source['extraction']['leading_bom_removed']);
        self::assertSame("第一集😀\n对白。", $source['source']['text']);
        self::assertSame(hash('sha256', $bytes), $source['file_sha256']);
    }

    public function testDocxExtractsOnlyCurrentMainDocumentTextWithNamespacesAndBoundedXml(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'attachment_docx_');
        $zip = new \ZipArchive(); $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<x:document xmlns:x="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><x:body><x:p><x:r><x:t>原文&amp;😀</x:t><x:tab/><x:t>继续</x:t><x:br/><x:t>结尾</x:t></x:r><x:del><x:r><x:delText>删除文字</x:delText></x:r></x:del></x:p></x:body></x:document>'); $zip->close();
        try {
            $source = Source::capture($path, 'docx', 1, 2, 'docx_test');
            self::assertSame("原文&😀\t继续\n结尾\n", $source['source']['text']);
            self::assertSame('word_main_document_text', $source['extraction']['scope']);
            $zip->open($path); $zip->addFromString('word/document.xml', '<!DOCTYPE a [<!ENTITY x "injected">]><a>&x;</a>'); $zip->close();
            $this->expectException(\RuntimeException::class); $this->expectExceptionCode(422);
            Source::capture($path, 'docx', 1, 2, 'docx_test');
        } finally { unlink($path); }
    }
}
