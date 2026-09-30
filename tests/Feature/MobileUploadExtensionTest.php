<?php

namespace Tests\Feature;

use app\common\service\storage\engine\Local;
use app\common\service\storage\UploadExtension;
use PHPUnit\Framework\TestCase;
use think\Config;
use think\Container;
use think\Exception;
use think\file\UploadedFile;
use think\Request;

require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

class MobileUploadExtensionTest extends TestCase
{
    /** @runInSeparateProcess */
    public function testExtensionlessMobileImageUsesItsContentTypeAndGetsAUsableName(): void
    {
        $path = $this->temporaryFile(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9sDXsAAAAASUVORK5CYII='
        ));
        try {
            self::assertSame('png', UploadExtension::fromContent($path));
            $driver = $this->driverFor($path, 'file-123');
            self::assertSame('png', $driver->getFileInfo()['ext']);
            self::assertSame('file-123.png', $driver->getFileInfo()['name']);
            self::assertStringEndsWith('.png', $driver->getFileName());
        } finally {
            unlink($path);
        }
    }

    /** @runInSeparateProcess */
    public function testDisallowedOriginalExtensionIsStillRejected(): void
    {
        $path = $this->temporaryFile(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9sDXsAAAAASUVORK5CYII='
        ));
        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('不允许上传exe后缀文件');
            $this->driverFor($path, 'image.exe');
        } finally {
            unlink($path);
        }
    }

    /** @runInSeparateProcess */
    public function testUnrecognizedExtensionlessContentIsRejected(): void
    {
        $path = $this->temporaryFile("\x00\x01\x02\x03");
        try {
            self::assertSame('', UploadExtension::fromContent($path));
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('不允许上传后缀文件');
            $this->driverFor($path, 'file-123');
        } finally {
            unlink($path);
        }
    }

    public function testVideoAndAudioContentCanRecoverMobileUploadExtensions(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertSame('mp4', UploadExtension::fromContent($root . '/public/media/aigc-preview.mp4'));
        self::assertSame('mp3', UploadExtension::fromContent($root . '/public/resource/audio/aigc-music-preview.mp3'));
    }

    private function driverFor(string $path, string $name): Local
    {
        $config = new Config();
        $config->set([
            'file_image' => ['png'],
            'file_video' => [],
            'file_file' => [],
        ], 'project');
        Container::getInstance()->instance('config', $config);

        $request = $this->createMock(Request::class);
        $request->expects(self::once())->method('file')->with('file')
            ->willReturn(new UploadedFile($path, $name, null, null, true));
        Container::getInstance()->instance('request', $request);

        $driver = new Local();
        $driver->setUploadFile('file');
        return $driver;
    }

    private function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mobile-upload-');
        file_put_contents($path, $content);
        return $path;
    }
}
