<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;

class TenantUserMembershipAssignmentContractTest extends TestCase
{
    public function testAssignmentIsTenantScopedAndUsesSharedPointService(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root . '/app/common/service/membership/MembershipService.php');
        $controller = file_get_contents($root . '/app/tenantapi/controller/user/UserController.php');

        self::assertStringContainsString("'tenant_id' => \$tenantId", $service);
        self::assertStringContainsString('UserPointService::grantMembershipBonus', $service);
        self::assertStringContainsString('MembershipService::assignPlan', $controller);
        self::assertStringContainsString("(int)\$params['id']", $controller);
    }

    public function testInstallAndUpgradeScriptsExposeBothPermissions(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            $root . '/upgrade/20260904_tenant_user_set_membership.sql',
            $root . '/public/upgrade/20260904_tenant_user_set_membership.sql',
            $root . '/public/install/db/like.sql',
            $root . '/app/platformapi/db/tenantData.sql',
        ];
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            self::assertStringContainsString('user.user/setMembership', $sql, $file);
            self::assertStringContainsString('user.user/membershipPlans', $sql, $file);
        }
    }
}
