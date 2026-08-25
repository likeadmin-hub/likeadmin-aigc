<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ZipArchive;

class ShortDramaScriptUploadContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function testUploadApiAndReleaseMetadataAreDeclared(): void
    {
        $schema = $this->json('app/apps/aigc_short_drama/api_schema.json');
        $upload = array_values(array_filter(
            $schema['apis'],
            static fn(array $api): bool => ($api['api_path'] ?? '') === 'app.aigc_short_drama.script_plan/upload'
        ));

        self::assertCount(1, $upload);
        self::assertSame('POST', $upload[0]['api_method']);
        self::assertSame('user', $upload[0]['scene']);

        $manifest = $this->json('app/apps/aigc_short_drama/manifest.json');
        self::assertSame('1.0.13', $manifest['version']);
        self::assertStringContainsString('上传 TXT、MD、DOCX 剧本', $manifest['changelog']);
    }

    public function testTextScriptNormalizationHandlesBomLineEndingsAndBlankRuns(): void
    {
        $normalized = $this->invoke('normalizeUploadedScriptText', "\xEF\xBB\xBF第一幕\r\n\r\n\r\n\r\n第二幕\r第三幕");

        self::assertSame("第一幕\n\n第二幕\n第三幕", $normalized);
    }

    public function testTxtScriptReaderPreservesUtf8Content(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'short-drama-script-');
        self::assertNotFalse($path);
        file_put_contents($path, "第一幕\r\n人物：林夏\r\n\r\n对白");

        try {
            $raw = $this->invoke('readUploadedScriptText', $path, 'txt');
            self::assertStringContainsString('人物：林夏', $raw);
            self::assertStringContainsString('对白', $raw);
        } finally {
            @unlink($path);
        }
    }

    public function testDocxScriptReaderExtractsParagraphsAndLineBreaks(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'short-drama-script-');
        self::assertNotFalse($path);
        @unlink($path);

        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>第一幕</w:t><w:br/><w:t>夜</w:t></w:r></w:p><w:p><w:r><w:t>对白</w:t></w:r></w:p></w:body></w:document>'
        );
        $zip->close();

        try {
            $raw = $this->invoke('readUploadedScriptText', $path, 'docx');
            self::assertStringContainsString("第一幕\n夜", $raw);
            self::assertStringContainsString("对白", $raw);
        } finally {
            @unlink($path);
        }
    }

    public function testCreateContractAcceptsUploadedScriptTextAndTracksSource(): void
    {
        $service = (string)file_get_contents(
            $this->root . '/app/common/service/app/aigc_short_drama/AigcShortDramaService.php'
        );

        self::assertStringContainsString("\$params['script_text'] ?? \$params['script_content']", $service);
        self::assertStringContainsString("\$request['script_source'] = \$uploadedScript !== '' ? 'upload'", $service);
        self::assertStringContainsString("以下为用户上传的剧本原文", $service);
        self::assertStringContainsString('SCRIPT_UPLOAD_MAX_BYTES = 10485760', $service);
        self::assertStringContainsString("SCRIPT_UPLOAD_EXTENSIONS = ['txt', 'md', 'docx']", $service);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }

    private function json(string $relativePath): array
    {
        $data = json_decode((string)file_get_contents($this->root . '/' . $relativePath), true);
        self::assertIsArray($data, $relativePath . ' must contain valid JSON');
        return $data;
    }
}
