<?php

namespace tests\Feature;

use app\common\service\wechat\OpenPlatformService;
use PHPUnit\Framework\TestCase;

class MiniprogramManagementTest extends TestCase
{
    public function testTesterListUsesWechatAction(): void
    {
        self::assertSame(['action' => 'get_experiencer'], OpenPlatformService::normalizeMiniprogramManagementInput('testers', []));
    }

    public function testEmptyWechatPostUsesObjectPayload(): void
    {
        self::assertSame('{}', OpenPlatformService::encodeWechatRequestBody(
            OpenPlatformService::normalizeMiniprogramManagementInput('illegal_records', [])
        ));
    }

    public function testCategoryTypeUsesNumberPayload(): void
    {
        self::assertSame('{"verify_type":1}', OpenPlatformService::encodeWechatRequestBody(
            OpenPlatformService::normalizeMiniprogramManagementInput('categories_by_type', ['verify_type' => '1'])
        ));
    }

    public function testAppealRecordsRequireAnIllegalRecord(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpenPlatformService::normalizeMiniprogramManagementInput('appeal_records', []);
    }

    public function testLocationApplicationKeepsOnlyApprovedFields(): void
    {
        $payload = OpenPlatformService::normalizeMiniprogramManagementInput('apply_privacy_interface', [
            'api_name' => 'wx.getLocation', 'content' => '提供附近服务', 'pic_list' => ['https://example.com/a.png'], 'access_token' => 'forged'
        ]);
        self::assertSame(['api_name', 'content', 'pic_list'], array_keys($payload));
    }

    public function testLocationApplicationRejectsNonHttpsPicture(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpenPlatformService::normalizeMiniprogramManagementInput('apply_privacy_interface', [
            'api_name' => 'wx.getLocation', 'content' => '提供附近服务', 'pic_list' => ['http://example.com/a.png']
        ]);
    }

    public function testCategoryRequestHasWeChatStructure(): void
    {
        self::assertSame(['categories' => [['first' => 8, 'second' => 39, 'certicates' => []]]],
            OpenPlatformService::normalizeMiniprogramManagementInput('add_category', ['first' => 8, 'second' => 39]));
    }

    public function testCategoryEvidenceAcceptsRealImageAndRejectsRenamedFile(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/w1cAAAAASUVORK5CYII=');
        self::assertSame(['mime' => 'image/png', 'extension' => 'png'],
            OpenPlatformService::categoryImageType('资质.png', $png));
        $this->expectException(\InvalidArgumentException::class);
        OpenPlatformService::categoryImageType('资质.jpg', $png);
    }

    public function testCategoryEvidenceRejectsOversizedImageBeforeWechatCall(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpenPlatformService::categoryImageType('资质.png', str_repeat('x', 2 * 1024 * 1024 + 1));
    }

    public function testUnknownOperationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpenPlatformService::normalizeMiniprogramManagementInput('arbitrary_call', []);
    }
}
