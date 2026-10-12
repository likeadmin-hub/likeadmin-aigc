<?php

namespace Tests\Feature;

use app\common\service\storage\engine\Local;
use app\common\service\storage\SvgSanitizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use think\Config;
use think\Container;
use think\file\UploadedFile;
use think\Request;

require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

final class SvgUploadTest extends TestCase
{
    public function testStaticShapesGradientsAndInlineStylesSurvive(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
            . '<defs><linearGradient id="paint"><stop offset="0" stop-color="#fff"/></linearGradient></defs>'
            . '<path d="M0 0h24v24z" fill="url(#paint)" style="stroke:rgb(10, 20, 30);stroke-width:2"/>'
            . '<text x="1" y="12">图标 &amp; logo</text></svg>';
        $clean = SvgSanitizer::sanitize($svg);
        self::assertStringContainsString('viewBox="0 0 24 24"', $clean);
        self::assertStringContainsString('fill="url(#paint)"', $clean);
        self::assertStringContainsString('stroke:rgb(10, 20, 30);stroke-width:2', $clean);
        self::assertStringContainsString('图标 &amp; logo', $clean);
        self::assertSame($clean, SvgSanitizer::sanitize($clean));
    }

    public function testActiveContentAndExternalResourcesAreRemoved(): void
    {
        $clean = SvgSanitizer::sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">'
            . '<?xml-stylesheet href="https://evil.test/a.css"?><script>alert(1)</script>'
            . '<foreignObject><body xmlns="http://www.w3.org/1999/xhtml">bad</body></foreignObject>'
            . '<image href="https://evil.test/a.png"/><use xlink:href="javascript:alert(1)"/>'
            . '<style>@import "https://evil.test/a.css";</style>'
            . '<animate attributeName="href" values="javascript:alert(1)"/>'
            . '<path d="M0 0h10" onclick="alert(1)" fill="url(https://evil.test/a.svg)" '
            . 'style="fill:u\72l(https://evil.test/a);stroke:red;filter:url(https://evil.test/a)"/>'
            . '<path xmlns="https://evil.test/ns" onload="alert(1)"/></svg>');
        foreach (['evil.test', 'script', 'alert', 'onload', 'onclick', 'foreignObject', '<image', '<use', '<style', '<animate', 'xlink'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $clean);
        }
        self::assertStringContainsString('style="stroke:red"', $clean);
        self::assertStringContainsString('d="M0 0h10"', $clean);
    }

    /** @dataProvider invalidSvg */
    public function testInvalidDocumentsAreRejected(string $source): void
    {
        $this->expectException(InvalidArgumentException::class);
        SvgSanitizer::sanitize($source);
    }

    public function invalidSvg(): array
    {
        return [
            [''], ['<html/>'], ['<svg xmlns="http://www.w3.org/2000/svg">'],
            ['<svg xmlns="https://evil.test"/>'], ["\0<svg/>"],
            ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'],
            ['<!DOCTYPE svg SYSTEM "https://evil.test/svg.dtd"><svg xmlns="http://www.w3.org/2000/svg"/>'],
            [str_repeat(' ', 5 * 1024 * 1024 + 1)],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUploadDriverStoresSanitizedBytesWithSvgMetadata(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'svg-upload-');
        $dir = sys_get_temp_dir() . '/svg-output-' . bin2hex(random_bytes(8));
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h24v24z"/></svg>');
        $config = new Config();
        Container::getInstance()->instance('env', new \think\Env());
        $config->set(require dirname(__DIR__, 2) . '/config/project.php', 'project');
        Container::getInstance()->instance('config', $config);
        $request = $this->createMock(Request::class);
        $request->expects(self::once())->method('file')->with('file')
            ->willReturn(new UploadedFile($path, 'logo.SVG', 'text/plain', null, true));
        Container::getInstance()->instance('request', $request);
        $saved = '';
        try {
            $driver = new Local();
            $driver->setUploadFile('file');
            self::assertSame('svg', $driver->getFileInfo()['ext']);
            self::assertSame('image/svg+xml', $driver->getFileInfo()['mime']);
            self::assertSame(filesize($path), $driver->getFileInfo()['size']);
            self::assertTrue($driver->upload($dir));
            $saved = $dir . '/' . $driver->getFileName();
            self::assertStringContainsString('<path', file_get_contents($saved));
            self::assertStringNotContainsString('script', file_get_contents($saved));
        } finally {
            if (is_file($path)) { unlink($path); }
            if ($saved && is_file($saved)) { unlink($saved); }
            if (is_dir($dir)) { rmdir($dir); }
        }
    }
}
