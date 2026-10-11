<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class WorkspaceEmptyAssetContractTest extends TestCase
{
    public function testWorkspaceEmptyStateUsesABundledLocalAsset(): void
    {
        $module = \Tests\Support\CompiledAssets::read('pc', 'workspace-assets');
        self::assertSame(1, preg_match('/workspace-empty\.[a-f0-9]+\.svg/', $module, $matches));
        self::assertFileExists(\Tests\Support\CompiledAssets::root() . '/public/_nuxt/' . $matches[0]);
        self::assertStringNotContainsString('oss-cn-shenzhen.aliyuncs.com', $module);
    }

}
