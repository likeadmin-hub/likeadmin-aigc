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

    public function testDocxIdenticalAlternateTextIsReadOnceAndTrailingParagraphIsRetained(): void
    {
        $body='<mc:AlternateContent><mc:Choice Requires="x"><x:p><x:r><x:t>陈默：完整台词😀</x:t></x:r></x:p></mc:Choice><mc:Fallback><x:p><x:r><x:t>陈默：完整台词😀</x:t></x:r></x:p></mc:Fallback></mc:AlternateContent><x:p><x:r><x:t>尾句。</x:t></x:r></x:p>';
        $source=$this->captureDocx($body);
        self::assertSame("陈默：完整台词😀\n尾句。\n",$source['source']['text']);self::assertSame(2,$source['extraction']['version']);
        Source::assertPackage($source);
    }

    /** @dataProvider unrepresentedDocx */
    public function testDocxCannotSilentlyOmitUnrepresentedMaterialOrSelectDifferentAlternativeText(string $body): void
    {
        $this->expectException(\RuntimeException::class);$this->expectExceptionCode(422);$this->captureDocx($body);
    }
    public static function unrepresentedDocx(): array
    {
        return [['<x:p><x:r><x:t>正文</x:t><x:footnoteReference x:id="1"/></x:r></x:p>'],
            ['<x:p><x:r><x:t>正文</x:t><x:endnoteReference x:id="1"/></x:r></x:p>'],
            ['<x:p><x:r><x:t>正文</x:t></x:r></x:p><x:altChunk/>'],
            ['<mc:AlternateContent><mc:Choice Requires="x"><x:p><x:r><x:t>甲的正文</x:t></x:r></x:p></mc:Choice><mc:Fallback><x:p><x:r><x:t>乙的正文</x:t></x:r></x:p></mc:Fallback></mc:AlternateContent>']];
    }
    public function testDocxIncludesReferencedFootnotesAndEndnotesInItsFrozenTextView(): void
    {
        $body = '<x:tbl><x:tr><x:tc><x:p><x:r><x:t>正文😀</x:t><x:footnoteReference x:id="1"/><x:endnoteReference x:id="2"/></x:r></x:p></x:tc></x:tr></x:tbl>';
        $notes = ['word/footnotes.xml' => '<x:footnotes xmlns:x="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><x:footnote x:id="1"><x:p><x:r><x:footnoteRef/><x:t>必须保留的脚注。</x:t></x:r></x:p></x:footnote></x:footnotes>',
            'word/endnotes.xml' => '<x:endnotes xmlns:x="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><x:endnote x:id="2"><x:p><x:r><x:t>尾注结尾😀。</x:t></x:r></x:p></x:endnote></x:endnotes>'];
        $source = $this->captureDocx($body, $notes); Source::assertPackage($source);
        self::assertSame("正文😀〔脚注1〕必须保留的脚注。\n〔/脚注1〕〔尾注2〕尾注结尾😀。\n〔/尾注2〕\n", $source['source']['text']);
        self::assertSame(3, $source['extraction']['version']); self::assertSame('inline_labeled_reference', $source['extraction']['note_projection']);
        self::assertCount(3, $source['extraction']['parts']);
        self::assertSame(hash('sha256', $notes['word/footnotes.xml']), $source['extraction']['parts']['word/footnotes.xml']['sha256']);
    }
    public function testDocxWithImageMaterialCannotPublishTextOnlyAsCompleteSource(): void
    {
        $this->expectExceptionCode(422);
        $this->captureDocx('<x:p><x:r><x:t>只有部分文字。</x:t><x:drawing><a:blip xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"/></x:drawing></x:r></x:p>');
    }
    public function testDocxCyclicNoteCannotDuplicateOrInventBodyText(): void
    {
        $this->expectExceptionCode(422);
        $this->captureDocx('<x:p><x:r><x:t>原文</x:t><x:footnoteReference x:id="1"/></x:r></x:p>', [
            'word/footnotes.xml' => '<x:footnotes xmlns:x="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><x:footnote x:id="1"><x:p><x:r><x:t>脚注</x:t><x:footnoteReference x:id="1"/></x:r></x:p></x:footnote></x:footnotes>']);
    }
    private function captureDocx(string $body, array $parts = []): array
    {
        $path=tempnam(sys_get_temp_dir(),'docx_material_');$zip=new \ZipArchive();$zip->open($path,\ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml','<x:document xmlns:x="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"><x:body>'.$body.'</x:body></x:document>');
        foreach ($parts as $name => $text) $zip->addFromString($name, $text); $zip->close();
        try { return Source::capture($path,'docx',1,2,'docx_material'); } finally { unlink($path); }
    }
}
