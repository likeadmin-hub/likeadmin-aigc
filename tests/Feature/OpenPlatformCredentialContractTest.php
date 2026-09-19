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

        self::assertStringContainsString("'component_appsecret' => self::credentialValue", $source);
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
}
