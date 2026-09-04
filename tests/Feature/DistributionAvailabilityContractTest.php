<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;

class DistributionAvailabilityContractTest extends TestCase
{
    public function testUserProfilesExposeTenantScopedDistributionAvailability(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/api/logic/UserLogic.php');

        self::assertStringContainsString("\$user['distribution_enabled'] = DistributionService::isEnabled", $source);
    }

    public function testDistributionUserEndpointsRequireTheTenantFeatureToBeEnabled(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/distribution/DistributionService.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/app/api/controller/DistributionController.php');

        self::assertStringContainsString('public static function assertEnabled', $service);
        self::assertStringContainsString('self::assertEnabled($tenantIds[0]);', $service);
        self::assertStringContainsString('self::assertEnabled($tenantId);', $service);
        self::assertSame(7, substr_count($controller, 'DistributionService::assertEnabled('));
    }

    public function testPcAndUniappEntriesFollowTheProfileAvailabilityFlag(): void
    {
        $root = dirname(__DIR__, 3);
        $pc = file_get_contents($root . '/pc/components/ai-workspace/user-panel.vue');
        $uniapp = file_get_contents($root . '/uniapp/src/pages/user/user.vue');

        self::assertStringContainsString('const distributionEnabled = computed', $pc);
        self::assertStringContainsString("if (distributionEnabled.value) items.push({ key: 'distribution', label: '推广中心' })", $pc);
        self::assertStringContainsString('v-if="distributionEnabled"', $uniapp);
    }
}
