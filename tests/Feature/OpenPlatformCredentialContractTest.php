<?php

namespace tests\Feature;

use app\common\service\wechat\OpenPlatformService;
use PHPUnit\Framework\TestCase;

class OpenPlatformCredentialContractTest extends TestCase
{
    public function testMaskedAndLegacyCredentialsAreNotSentAsEmptyValues(): void
    {
        $method = new \ReflectionMethod(OpenPlatformService::class, 'credentialValue');
        $method->setAccessible(true);

        self::assertSame('', $method->invoke(null, 'abcd********wxyz'));
        self::assertSame('legacy-app-secret', $method->invoke(null, 'legacy-app-secret'));
    }

    public function testOpenPlatformCallsUseCredentialResolverInsteadOfRawDecryptOnly(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/wechat/OpenPlatformService.php');

        self::assertStringContainsString("'component_secret' => self::credentialValue", $source);
        self::assertStringContainsString('public static function credentialValue', $source);
        self::assertStringContainsString("if (\$value === '' || str_contains(\$value, '*')) return '';", $source);
    }

    public function testWholeNetworkCallbacksHaveFixedTextAndEventReplies(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/wechat/OpenPlatformCallbackService.php');

        self::assertStringContainsString('TESTCOMPONENT_MSG_TYPE_TEXT_callback', $source);
        self::assertStringContainsString('QUERY_AUTH_CODE:', $source);
        self::assertStringContainsString(". 'from_callback'", $source);
        self::assertStringContainsString('sendAuthorizerCustomText', $source);
    }

    public function testArtifactListingCanRecoverFormalAndVersionedDirectories(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/wechat/OpenPlatformService.php');

        self::assertStringContainsString("\$fallbackDir = 'mp-weixin.pre-release-' . \$version", $source);
        self::assertStringContainsString("\$formalMetadataPath = \$formalDirectory . '/.artifact.meta.json'", $source);
        self::assertStringContainsString("\$relativeDir = 'mp-weixin'", $source);
    }

    public function testAuthorizationProfileAndTemplateDraftsHaveExplicitPersistenceContracts(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/wechat/OpenPlatformService.php');
        $callback = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/wechat/OpenPlatformCallbackService.php');
        $platformController = file_get_contents(dirname(__DIR__, 2) . '/app/platformapi/controller/OpenPlatformController.php');
        $tenantController = file_get_contents(dirname(__DIR__, 2) . '/app/tenantapi/controller/channel/OpenPlatformController.php');

        self::assertStringContainsString('authorizerProfilePayload', $service);
        self::assertStringContainsString("'user_name' => 'original_id'", $service);
        self::assertStringContainsString("'qrcode_url' => 'qrcode_url'", $service);
        self::assertStringContainsString("'wxa/gettemplatedraftlist'", $service);
        self::assertStringContainsString("'wxa/gettemplatelist'", $service);
        self::assertStringContainsString('public static function syncTemplates()', $service);
        self::assertStringContainsString('public static function templateRecords()', $service);
        self::assertStringContainsString('public static function availableTemplates()', $service);
        self::assertStringContainsString('草稿 ID 不是本地产品版本号', $service);
        self::assertStringContainsString('array_merge($profile', $callback);
        self::assertStringContainsString('function templateDrafts()', $platformController);
        self::assertStringContainsString('function templateRecords()', $platformController);
        self::assertStringContainsString('function syncAccount()', $tenantController);
        self::assertStringContainsString('OpenPlatformService::availableTemplates()', $tenantController);
    }
}
