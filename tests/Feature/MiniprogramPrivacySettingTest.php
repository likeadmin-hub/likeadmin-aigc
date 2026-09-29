<?php

namespace tests\Feature;

use app\common\service\wechat\OpenPlatformService;
use PHPUnit\Framework\TestCase;

class MiniprogramPrivacySettingTest extends TestCase
{
    private function validPayload(): array
    {
        return [
            'privacy_ver' => 2,
            'owner_setting' => [
                'contact_email' => 'support@example.com',
                'notice_method' => '通过弹窗',
                'store_expire_timestamp' => '30天',
                'ext_file_media_id' => '',
            ],
            'setting_list' => [
                ['privacy_key' => 'Album', 'privacy_text' => '处理用户上传的图片'],
                ['privacy_key' => 'Camera', 'privacy_text' => '拍摄创作素材'],
            ],
        ];
    }

    public function testNormalizesCompleteDevelopmentGuide(): void
    {
        $result = OpenPlatformService::normalizePrivacySetting($this->validPayload());
        self::assertSame(2, $result['privacy_ver']);
        self::assertSame('30天', $result['owner_setting']['store_expire_timestamp']);
        self::assertSame('', $result['owner_setting']['contact_phone']);
        self::assertSame(['Album', 'Camera'], array_column($result['setting_list'], 'privacy_key'));
    }

    public function testRejectsLiveGuideMutation(): void
    {
        $payload = $this->validPayload();
        $payload['privacy_ver'] = 1;
        $this->expectException(\InvalidArgumentException::class);
        OpenPlatformService::normalizePrivacySetting($payload);
    }

    public function testRequiresPurposeForEverySelectedInformationType(): void
    {
        $payload = $this->validPayload();
        $payload['setting_list'][1]['privacy_text'] = '';
        $this->expectException(\InvalidArgumentException::class);
        OpenPlatformService::normalizePrivacySetting($payload);
    }

    public function testRejectsDuplicateInformationTypes(): void
    {
        $payload = $this->validPayload();
        $payload['setting_list'][1]['privacy_key'] = 'Album';
        $this->expectException(\InvalidArgumentException::class);
        OpenPlatformService::normalizePrivacySetting($payload);
    }

    public function testRequiresADeveloperContact(): void
    {
        $payload = $this->validPayload();
        $payload['owner_setting']['contact_email'] = '';
        $this->expectException(\InvalidArgumentException::class);
        OpenPlatformService::normalizePrivacySetting($payload);
    }
}
