<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class WorkspaceEmptyAssetContractTest extends TestCase
{
    public function testWorkspaceEmptyStateUsesABundledLocalAsset(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root . '/public/_nuxt/workspace-assets.145e1e4d.js');
        $asset = $root . '/public/_nuxt/workspace-empty.44a6b6ee.svg';

        self::assertFileExists($asset);
        self::assertStringContainsString('workspace-empty.44a6b6ee.svg', $module);
        self::assertStringNotContainsString('oss-cn-shenzhen.aliyuncs.com', $module);
    }
}
