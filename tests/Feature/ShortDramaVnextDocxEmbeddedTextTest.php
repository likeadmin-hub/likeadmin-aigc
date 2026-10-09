<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaAttachmentSource as Source;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextDocxEmbeddedTextTest extends TestCase
{
    private const W='http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const P='http://schemas.openxmlformats.org/package/2006/relationships';
    private const T='http://schemas.openxmlformats.org/package/2006/content-types';
    private function fixture(array $replace=[]): array
    {
        return array_replace([
            'word/document.xml'=>'<w:document xmlns:w="'.self::W.'" xmlns:r="'.self::R.'"><w:body><w:p><w:r><w:t>前文😀</w:t></w:r></w:p><w:altChunk r:id="chunk1"/><w:p><w:r><w:t>后文尾句😀</w:t></w:r></w:p></w:body></w:document>',
            'word/_rels/document.xml.rels'=>'<Relationships xmlns="'.self::P.'"><Relationship Id="chunk1" Type="'.self::R.'/aFChunk" Target="embedded/source.txt"/></Relationships>',
            '[Content_Types].xml'=>'<Types xmlns="'.self::T.'"><Default Extension="txt" ContentType="text/plain"/><Default Extension="html" ContentType="text/html"/></Types>',
            'word/embedded/source.txt'=>"嵌入全文😀\r\n\r\n不可缺少尾句。\r\n",
        ],$replace);
    }
    private function capture(array $parts): array
    {
        $path=tempnam(sys_get_temp_dir(),'embedded_docx_');$zip=new \ZipArchive();$zip->open($path,\ZipArchive::OVERWRITE);
        foreach($parts as $name=>$bytes)$zip->addFromString($name,$bytes);$zip->close();
        try{return Source::capture($path,'docx',1,2,'embedded_docx');}finally{unlink($path);}
    }
    public function testInternalPlainTextKeepsOrderBlankLinesAndUnicodePositions(): void
    {
        $parts=$this->fixture();$package=$this->capture($parts);Source::assertPackage($package);
        self::assertSame("前文😀\n嵌入全文😀\n\n不可缺少尾句。\n后文尾句😀\n",$package['source']['text']);
        self::assertSame(4,$package['extraction']['version']);$entry=$package['extraction']['embedded_parts'][0];
        self::assertSame('word/embedded/source.txt',$entry['part']);self::assertSame(hash('sha256',$parts[$entry['part']]),$entry['sha256']);
        self::assertSame("嵌入全文😀\n\n不可缺少尾句。\n",ContentDocument::slice($package['source']['text'],$entry['start'],$entry['end']));
        self::assertSame(hash('sha256',ContentDocument::slice($package['source']['text'],$entry['start'],$entry['end'])),$entry['text_hash']);
        self::assertStringNotContainsString("\0",$package['source']['text']);
        self::assertSame($package,$this->capture($parts));
    }
    public function testRepeatedTextAndRepeatedChunkUseTheirActualAnchorsInsteadOfFirstTextMatch(): void
    {
        $parts=$this->fixture(['word/embedded/source.txt'=>'相同😀']);
        $parts['word/document.xml']=str_replace('前文😀','相同😀',$parts['word/document.xml']);
        $parts['word/document.xml']=str_replace('<w:p><w:r><w:t>后文尾句😀', '<w:altChunk r:id="chunk1"/><w:p><w:r><w:t>后文尾句😀',$parts['word/document.xml']);
        $package=$this->capture($parts);$entries=$package['extraction']['embedded_parts'];
        self::assertSame([4,7],array_column($entries,'start'));self::assertSame([7,10],array_column($entries,'end'));
        self::assertSame("相同😀\n相同😀相同😀后文尾句😀\n",$package['source']['text']);
    }
    public function testRestrictedHtmlIncludesTablesAndTextEntitiesWithoutFetchingLinks(): void
    {
        $parts=$this->fixture();$parts['word/_rels/document.xml.rels']=str_replace('source.txt','source.html',$parts['word/_rels/document.xml.rels']);
        unset($parts['word/embedded/source.txt']);$parts['word/embedded/source.html']='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>标题元数据</title></head><body><p>台词&amp;😀<br>尾句。</p><table><tr><td>甲</td><td>乙</td></tr></table><a href="https://invalid.example/never-fetch">链接文字</a></body></html>';
        $package=$this->capture($parts);$entry=$package['extraction']['embedded_parts'][0];
        self::assertSame("台词&😀\n尾句。\n甲\t乙\t\n链接文字",ContentDocument::slice($package['source']['text'],$entry['start'],$entry['end']));
        self::assertSame('text/html',$entry['content_type']);self::assertStringNotContainsString('标题元数据',$package['source']['text']);
    }
    public function testNotesResolveChunksRelativeToTheirOwnRelationshipPart(): void
    {
        $parts=$this->fixture();$parts['word/document.xml']=str_replace('<w:altChunk r:id="chunk1"/>','<w:p><w:r><w:footnoteReference w:id="1"/></w:r></w:p>',$parts['word/document.xml']);
        unset($parts['word/_rels/document.xml.rels']);
        $parts['word/footnotes.xml']='<w:footnotes xmlns:w="'.self::W.'" xmlns:r="'.self::R.'"><w:footnote w:id="1"><w:altChunk r:id="noteText"/></w:footnote></w:footnotes>';
        $parts['word/_rels/footnotes.xml.rels']='<Relationships xmlns="'.self::P.'"><Relationship Id="noteText" Type="'.self::R.'/aFChunk" Target="embedded/source.txt"/></Relationships>';
        $package=$this->capture($parts);$entry=$package['extraction']['embedded_parts'][0];
        self::assertSame('word/footnotes.xml',$entry['source_part']);self::assertSame('inline_labeled_reference',$package['extraction']['note_projection']);
        self::assertStringContainsString('不可缺少尾句',ContentDocument::slice($package['source']['text'],$entry['start'],$entry['end']));
    }
    /** @dataProvider unsafeCases */
    public function testIncompleteOrUnsafeEmbeddedSourceIsNeverTreatedAsComplete(string $case): void
    {
        $parts=$this->fixture();$relation=&$parts['word/_rels/document.xml.rels'];
        if($case==='external')$relation=str_replace('Target="embedded/source.txt"','TargetMode="External" Target="https://example.test/content"',$relation);
        if($case==='missing')unset($parts['word/embedded/source.txt']);
        if($case==='type')$parts['[Content_Types].xml']=str_replace('text/plain','application/rtf',$parts['[Content_Types].xml']);
        if($case==='duplicate')$relation=str_replace('</Relationships>',substr($relation,strpos($relation,'<Relationship Id='),strpos($relation,'/></Relationships>')-strpos($relation,'<Relationship Id=')+2).'</Relationships>',$relation);
        if($case==='path')$relation=str_replace('embedded/source.txt','../../../outside.txt',$relation);
        if($case==='escaped')$relation=str_replace('embedded/source.txt','embedded%2Fsource.txt',$relation);
        if($case==='nul')$parts['word/embedded/source.txt']="原文\0缺失";
        if($case==='encoding')$parts['word/embedded/source.txt']="\xFF\xFE原文";
        if($case==='entity')$relation='<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]>'.$relation;
        if($case==='excess')$parts['word/document.xml']=str_replace('<w:altChunk r:id="chunk1"/>',str_repeat('<w:altChunk r:id="chunk1"/>',129),$parts['word/document.xml']);
        if(str_starts_with($case,'html_')) {
            $relation=str_replace('source.txt','source.html',$relation);unset($parts['word/embedded/source.txt']);
            $cases=['html_image'=>'<img src="word/media.png">','html_script'=>'<script>value</script>',
                'html_style'=>'<p style="display:none">隐藏原文</p>','html_css'=>'<style>p:before{content:"遗漏文字"}</style><p>正文</p>',
                'html_encoding'=>'<meta charset="GBK"><p>原文</p>'];
            $parts['word/embedded/source.html']='<html><head></head><body>'.$cases[$case].'</body></html>';
        }
        $this->expectExceptionCode(422);$this->capture($parts);
    }
    public static function unsafeCases(): array {return array_map(static fn($case)=>[$case],['external','missing','type','duplicate','path','escaped','nul','encoding','entity','excess','html_image','html_script','html_style','html_css','html_encoding']);}
}
