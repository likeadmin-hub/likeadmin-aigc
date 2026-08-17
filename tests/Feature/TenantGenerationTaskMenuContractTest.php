<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class TenantGenerationTaskMenuContractTest extends TestCase
{
    public function testTenantMenuRuntimeMovesGenerationTasksUnderTaskLogs(): void
    {
        $root = dirname(__DIR__, 2);
        $logic = (string)file_get_contents($root . '/app/tenantapi/logic/auth/MenuLogic.php');

        self::assertStringContainsString('self::ensureTaskLogMenu($tenantId);', $logic);
        self::assertStringContainsString("'aigc_image_task' => ['name' => '生图列表', 'paths' => 'image', 'sort' => 90]", $logic);
        self::assertStringContainsString("'aigc_video_task' => ['name' => '视频列表', 'paths' => 'video', 'sort' => 80]", $logic);
        self::assertStringContainsString('self::grantParentMenuToChildRoles($roleMenuTable, $taskMenuId, $taskLogId);', $logic);
        self::assertStringNotContainsString('self::grantChildMenuToParentRoles($roleMenuTable, $taskLogId, $menuId);', $logic);
    }

    public function testGenerationTaskPagesRemainBackedByTheirDedicatedLists(): void
    {
        $root = dirname(__DIR__, 2);
        $imageMenu = (string)file_get_contents($root . '/app/apps/aigc_image/menus/tenant.json');
        $videoMenu = (string)file_get_contents($root . '/app/apps/aigc_video/menus/tenant.json');

        self::assertStringContainsString('"source_menu_key": "aigc_image_task"', $imageMenu);
        self::assertStringContainsString('"component": "apps/aigc_image/task"', $imageMenu);
        self::assertStringContainsString('"source_menu_key": "aigc_video_task"', $videoMenu);
        self::assertStringContainsString('"component": "apps/aigc_video/task"', $videoMenu);
    }

    public function testInstallAndTenantSeedsUseTheTaskLogHierarchy(): void
    {
        $root = dirname(__DIR__, 2);
        $tenantSeed = (string)file_get_contents($root . '/app/platformapi/db/tenantData.sql');
        $installSeed = (string)file_get_contents($root . '/public/install/db/like.sql');

        self::assertStringContainsString("@core_tenant_task_log_id,'C','生图列表','',90", $tenantSeed);
        self::assertStringContainsString("@core_tenant_task_log_id,'C','视频列表','',80", $tenantSeed);
        self::assertStringContainsString("`name`='生图列表',`sort`=90,`paths`='image'", $installSeed);
        self::assertStringContainsString("`name`='视频列表',`sort`=80,`paths`='video'", $installSeed);
    }

    public function testUpgradeIsMirroredAndPreservesExistingTaskPermissions(): void
    {
        $root = dirname(__DIR__, 2);
        $upgrade = (string)file_get_contents($root . '/upgrade/20260817_tenant_generation_task_menu.sql');
        $publicUpgrade = (string)file_get_contents($root . '/public/upgrade/20260817_tenant_generation_task_menu.sql');

        self::assertSame($upgrade, $publicUpgrade);
        self::assertStringContainsString("image_task.`name`='生图列表'", $upgrade);
        self::assertStringContainsString("video_task.`name`='视频列表'", $upgrade);
        self::assertStringContainsString("task_menu.`source_menu_key` IN ('aigc_image_task','aigc_video_task')", $upgrade);
        self::assertStringContainsString('INSERT IGNORE INTO `la_tenant_system_role_menu`', $upgrade);
    }
}
