<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ShortDramaInspirationAdminApiContractTest extends TestCase
{
    private string $root;

    private const ROUTES = [
        'app.aigc_short_drama.inspiration/save' => 'aigc_short_drama:inspiration:save',
        'app.aigc_short_drama.inspiration/delete' => 'aigc_short_drama:inspiration:delete',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function testTenantAdminActionsAreDeclaredHandledAndPermitted(): void
    {
        $schema = json_decode(
            (string)file_get_contents($this->root . '/app/apps/aigc_short_drama/api_schema.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $permissions = json_decode(
            (string)file_get_contents($this->root . '/app/apps/aigc_short_drama/permissions/tenant.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach (self::ROUTES as $path => $permissionKey) {
            $routes = array_values(array_filter(
                (array)($schema['apis'] ?? []),
                static fn(array $api): bool => ($api['api_path'] ?? '') === $path && ($api['scene'] ?? '') === 'tenant_admin'
            ));
            self::assertCount(1, $routes, $path);
            self::assertSame('POST', $routes[0]['api_method']);
            self::assertSame($permissionKey, $routes[0]['permission_key']);
            self::assertSame(1, $routes[0]['need_login']);
            self::assertSame(1, $routes[0]['need_role_permission']);

            self::assertTrue((bool)array_filter(
                $permissions,
                static fn(array $permission): bool => ($permission['permission_key'] ?? '') === $permissionKey
                    && ($permission['api_path'] ?? '') === $path
                    && ($permission['api_method'] ?? '') === 'POST'
            ));
        }

        $controller = (string)file_get_contents($this->root . '/app/tenantapi/controller/app/aigc_short_drama/InspirationController.php');
        self::assertStringContainsString('public function save()', $controller);
        self::assertStringContainsString('saveAdminInspiration', $controller);
        self::assertStringContainsString('public function delete()', $controller);
        self::assertStringContainsString('deleteAdminInspiration', $controller);
    }

    public function testTenantAdminActionsExistForFreshAndUpgradedInstallations(): void
    {
        $freshInstallFiles = [
            'app/apps/aigc_short_drama/migrations/install.sql',
            'public/install/db/like.sql',
        ];
        $upgradeFiles = [
            'app/apps/aigc_short_drama/migrations/upgrade_20260915_short_drama_inspiration_admin_actions.sql',
            'upgrade/20260915_short_drama_inspiration_admin_actions.sql',
            'public/upgrade/20260915_short_drama_inspiration_admin_actions.sql',
        ];

        foreach (array_merge($freshInstallFiles, $upgradeFiles) as $file) {
            $sql = (string)file_get_contents($this->root . '/' . $file);
            foreach (array_keys(self::ROUTES) as $path) {
                self::assertStringContainsString($path, $sql, $file);
            }
        }
        foreach ($upgradeFiles as $file) {
            self::assertStringContainsString(
                'ON DUPLICATE KEY UPDATE',
                (string)file_get_contents($this->root . '/' . $file),
                $file
            );
        }
    }
}
