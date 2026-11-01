<?php
namespace Tests\Feature;

use app\common\service\app\aigc_llm\agent\AgentDocumentReader;
use PHPUnit\Framework\TestCase;

final class AgentPlainDocumentMimeTest extends TestCase
{
    private function extract(string $body): string
    {
        $path=tempnam(sys_get_temp_dir(),'agent-plain-document-');
        try{file_put_contents($path,$body);return AgentDocumentReader::extract($path,'资料.txt');}
        finally{unlink($path);}
    }
    public function testChineseLeadingTextIsReadableEvenWhenLibmagicCallsItDosCode(): void
    {
        $body=str_repeat('隔离资料事实。',2200);
        self::assertSame($body,$this->extract($body));
    }
    public function testUnicodeLeadingBytesDoNotAllowBinaryControls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->extract("隔离资料\x00\x01".str_repeat('正文。',100));
    }
    public function testExecutableHeaderIsStillRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->extract("MZ\x90\x00\x03\x00".str_repeat("\x00",200));
    }
    public function testHtmlDisguisedAsTextKeepsItsOriginalRejection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->extract('<!doctype html><html><body>正文<script>alert(1)</script></body></html>');
    }
}
