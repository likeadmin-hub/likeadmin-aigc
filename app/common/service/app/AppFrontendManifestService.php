<?php

namespace app\common\service\app;

use app\common\service\membership\MembershipService;

class AppFrontendManifestService
{
    public static function tenantEntries(int $tenantId, string $terminal, int $userId = 0): array
    {
        $entries = AppRegistryService::frontendEntries($tenantId, $terminal);
        foreach ($entries as &$entry) {
            $access = MembershipService::appAccess($tenantId, $userId, (string)($entry['app_code'] ?? ''));
            $entry['need_membership'] = $access['need_membership'];
            $entry['membership_allowed'] = $access['allowed'];
            $entry['member_status'] = $access['member_status'];
        }
        return $entries;
    }
}
