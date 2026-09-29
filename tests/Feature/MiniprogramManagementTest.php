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

    public function testUnknownOperationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpenPlatformService::normalizeMiniprogramManagementInput('arbitrary_call', []);
    }
}
