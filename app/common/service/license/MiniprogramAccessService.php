<?php

declare(strict_types=1);
namespace app\common\service\license;

/** Platform qualification only; tenant permissions still apply separately. */
class MiniprogramAccessService
{
    public const MESSAGE = '微信小程序为商业授权专属功能，当前平台尚未开通，请联系平台管理员开通商业授权后使用。';
    private SiteLicenseAccessService $license;

    public function __construct(?SiteLicenseAccessService $license = null)
    {
        $this->license = $license ?? new SiteLicenseAccessService();
    }

    public function enabled(): bool
    {
        $site = $this->license->snapshot();
        return ($site['edition'] ?? '') === 'commercial' && ($site['can_customize'] ?? false) === true;
    }

    public function capability(): array
    {
        $enabled = $this->enabled();
        return ['enabled' => $enabled, 'message' => $enabled ? '' : self::MESSAGE];
    }

    public function assertEnabled(): void
    {
        if (!$this->enabled()) throw new \RuntimeException(self::MESSAGE);
    }

    public static function requiresAccess(string $scope, string $controller, string $action, string $type = ''): bool
    {
        $controller = strtolower(str_replace(['_', '-', '\\'], ['', '', '.'], $controller));
        $action = strtolower(str_replace(['_', '-'], '', $action));
        if ($scope === 'tenant' && $controller === 'channel.mnpsettings') return true;
        if ($controller !== ($scope === 'tenant' ? 'channel.openplatform' : 'openplatform')) return false;
        // Generic authorization can authorize a mini program too, so require an explicit official type.
        if ($action === 'authurl') return $type !== 'official';
        if ($scope === 'tenant') {
            if (in_array($action, ['syncaccount', 'unbind'], true)) return $type === 'miniprogram';
            return !in_array($action, ['status', 'accounts'], true);
        }
        return !in_array($action, ['config', 'saveconfig', 'startticket', 'authorizers', 'syncauthorizers', 'logs'], true);
    }
}
