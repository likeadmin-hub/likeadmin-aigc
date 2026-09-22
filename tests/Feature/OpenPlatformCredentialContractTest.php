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

    public function testDraftMiniProgramReadinessUsesRawCredentialBeforeItIsMasked(): void
    {
        $method = new \ReflectionMethod(OpenPlatformService::class, 'draftMiniprogramConfigured');
        $method->setAccessible(true);

        self::assertTrue($method->invoke(null, [
            'developer_app_id' => 'wx1234567890abcdef',
            'upload_private_key' => 'legacy-private-key',
        ]));
        self::assertFalse($method->invoke(null, [
            'developer_app_id' => 'wx1234567890abcdef',
            'upload_private_key' => 'lega*********-key',
        ]));

        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/wechat/OpenPlatformService.php');
        self::assertStringContainsString("\$row['draft_miniprogram_configured'] = self::draftMiniprogramConfigured(\$raw);", $source);
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

    public function testManualUploadCanUseAVerifiedFormalArtifactWithoutARegistryRow(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/wechat/OpenPlatformService.php');

        self::assertStringContainsString('Tenant uploads only need the signed', $source);
        self::assertStringContainsString("return ['id' => 0, 'version' => \$version, 'dir' => 'mp-weixin'];", $source);
        self::assertStringContainsString("(array)(\$formalMetadata['files'] ?? []) !== \$formalFiles", $source);
        self::assertStringContainsString("(string)(\$formalMetadata['sha256'] ?? '') !== \$formalHash", $source);
        self::assertStringContainsString("\$formalDirectory . '/config/runtime.js'", $source);
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
        self::assertStringContainsString("'template.list', ['component_access_token' => self::componentAccessToken()], 0, 0, 'GET'", $service);
        self::assertStringContainsString("'template.drafts', ['component_access_token' => self::componentAccessToken()], 0, 0, 'GET'", $service);
        self::assertStringContainsString("\$method === 'GET'", $service);
        self::assertStringContainsString('beforeTemplateIds', $service);
        self::assertStringContainsString('public static function syncTemplates()', $service);
        self::assertStringContainsString('public static function templateRecords()', $service);
        self::assertStringContainsString('public static function availableTemplates()', $service);
        self::assertStringContainsString('public static function addTemplateFromDraft(', $service);
        self::assertStringContainsString('public static function uploadDraftForArtifact(', $service);
        self::assertStringContainsString("'wechat.draft.upload'", $service);
        self::assertStringContainsString("(int)\$item['draft_id'] < 0", $service);
        self::assertStringContainsString('草稿 ID 不是本地产品版本号', $service);
        self::assertStringContainsString('array_merge($profile', $callback);
        self::assertStringContainsString('function templateDrafts()', $platformController);
        self::assertStringContainsString('function templateRecords()', $platformController);
        self::assertStringContainsString('function uploadDraft()', $platformController);
        self::assertStringContainsString('function syncAccount()', $tenantController);
        self::assertStringContainsString('OpenPlatformService::availableTemplates()', $tenantController);
    }

    public function testDraftUploadSchemaIsMirroredForUpgradeAndFreshInstall(): void
    {
        $root = dirname(__DIR__, 2);
        $serverUpgrade = (string)file_get_contents($root . '/upgrade/20260920_wechat_template_drafts.sql');
        $publicUpgrade = (string)file_get_contents($root . '/public/upgrade/20260920_wechat_template_drafts.sql');
        $install = (string)file_get_contents($root . '/public/install/db/like.sql');

        self::assertSame($serverUpgrade, $publicUpgrade);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `la_wechat_template_drafts`', $serverUpgrade);
        self::assertStringContainsString('`developer_app_id` varchar(64)', $serverUpgrade);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `la_wechat_template_drafts`', $install);
    }

    public function testLegacyNestedOpenPlatformMenuHasAnIdempotentHideUpgrade(): void
    {
        $root = dirname(__DIR__, 2);
        $serverUpgrade = (string)file_get_contents($root . '/upgrade/20260919_hide_legacy_open_platform_menu.sql');
        $publicUpgrade = (string)file_get_contents($root . '/public/upgrade/20260919_hide_legacy_open_platform_menu.sql');

        self::assertSame($serverUpgrade, $publicUpgrade);
        self::assertStringContainsString("`source_menu_key` = 'core_channel_manage'", $serverUpgrade);
        self::assertStringContainsString("`source_menu_key` = 'core_open_platform'", $serverUpgrade);
        self::assertStringContainsString('`is_show` = 0, `is_disable` = 1', $serverUpgrade);
        self::assertStringContainsString('@open_platform_root_id IS NOT NULL', $serverUpgrade);
    }

    public function testTemplateVersionAndWeChatConsoleAuditContractsAreEnforced(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/wechat/OpenPlatformService.php');

        self::assertStringContainsString("foreach (['authorizer_id', 'template_id'] as \$key)", $service);
        self::assertStringContainsString("\$version = trim((string)\$template['template_version']);", $service);
        self::assertStringContainsString("'wxa/get_auditstatus', ['auditid' => (int)\$auditNo]", $service);
        self::assertStringContainsString('请先提交审核', $service);
        self::assertStringContainsString('downloadExperienceQrcode', $service);
        self::assertStringContainsString("\$query->where('upload_mode', 'template');", $service);
    }

    public function testAuditSubmissionRecoversAnAcceptedWeChatAuditBeforeRetrying(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/wechat/OpenPlatformService.php');

        self::assertStringContainsString('private static function syncLatestAudit(', $service);
        self::assertStringContainsString("'wxa/get_latest_auditstatus'", $service);
        self::assertStringContainsString('self::isAuditAlreadyPendingError($e)', $service);
        self::assertStringContainsString("'already submit a version under auditing'", $service);
        self::assertStringContainsString('private static function saveMnpReview(', $service);
        self::assertStringNotContainsString('WechatMnpReview::withoutGlobalScope()->create(', $service);
        self::assertStringContainsString('new WechatMnpReview()', $service);
        self::assertStringContainsString("->order('id desc')", $service);
    }

    public function testAuthorizedTenantExtJsonIsGeneratedAtCommitTime(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/wechat/OpenPlatformService.php');

        self::assertStringContainsString('self::tenantRuntimeConfig($tenantId, true)', $service);
        self::assertStringContainsString('self::templateExtJson($runtimeConfig, $authorizer)', $service);
        self::assertStringContainsString("'ext_json' => '{}'", $service);
        self::assertStringContainsString("'ext' => ['runtime_config' => \$runtimeConfig]", $service);
        self::assertStringContainsString('请先配置租户小程序业务域名后再提交体验版', $service);
    }

    public function testLegacyMiniProgramManagementEndpointsRemainCompatibleDuringRollingUpgrade(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/wechat/OpenPlatformService.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/app/tenantapi/controller/channel/OpenPlatformController.php');

        self::assertStringContainsString('public static function miniprogramManagement(int $tenantId)', $service);
        self::assertStringContainsString('public static function undoAudit(int $tenantId, int $id)', $service);
        self::assertStringContainsString("['privacy_ver' => 2], 'miniprogram.privacy.get'", $service);
        self::assertStringContainsString("'wxa/undocodeaudit', [], 'release.audit.undo', ['access_token' => self::authorizerToken((int)\$authorizer['id'])], \$tenantId, (int)\$authorizer['id'], 'GET');", $service);
        self::assertStringContainsString("'wxa/get_auditstatus', ['auditid' => (int)\$auditNo]", $service);
        self::assertStringContainsString('OpenPlatformService::miniprogramManagement($this->tenantId)', $controller);
        self::assertStringContainsString('OpenPlatformService::undoAudit($this->tenantId, $id)', $controller);
    }

    public function testAuthorizedMiniProgramLoginAndPhoneCodesUseOpenPlatformEndpoints(): void
    {
        $root = dirname(__DIR__, 2);
        $service = (string)file_get_contents($root . '/app/common/service/wechat/OpenPlatformService.php');
        $mnp = (string)file_get_contents($root . '/app/common/service/wechat/WeChatMnpService.php');

        self::assertStringContainsString('public static function hasAuthorizedMiniprogram(int $tenantId)', $service);
        self::assertStringContainsString("'sns/component/jscode2session'", $service);
        self::assertStringContainsString("'component_appid' => (string)\$config['app_id']", $service);
        self::assertStringContainsString("'wxa/business/getuserphonenumber'", $service);
        self::assertStringContainsString("['access_token' => self::authorizerToken((int)\$authorizer['id'])]", $service);
        self::assertStringContainsString('OpenPlatformService::hasAuthorizedMiniprogram($this->tenantId)', $mnp);
        self::assertStringContainsString('OpenPlatformService::authorizedMnpSessionByCode($this->tenantId, $code)', $mnp);
        self::assertStringContainsString('OpenPlatformService::authorizedMnpPhoneNumber($this->tenantId, $code)', $mnp);
    }
}
