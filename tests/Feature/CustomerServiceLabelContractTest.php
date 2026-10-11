<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CustomerServiceLabelContractTest extends TestCase
{
    public function testCustomerServiceButtonUsesTheOnlineSupportLabel(): void
    {
        $component = \Tests\Support\CompiledAssets::source('pc/components/ai-workspace/help-float.vue');
        $locales = \Tests\Support\CompiledAssets::source('pc/locales/ui-text.json');
        self::assertStringContainsString('tt(\'联系在线客服\')', $component);
        self::assertStringContainsString(':disabled="!customerService.enterprise_wechat_url"', $component);
        self::assertStringContainsString('@click="openEnterpriseWechat"', $component);
        self::assertStringContainsString('聯絡線上客服', $locales);
        self::assertStringContainsString('Contact online support', $locales);
        self::assertStringNotContainsString('联系企业微信客户', $component);
        self::assertStringNotContainsString('Contact WeCom support', $locales);
        self::assertNotEmpty(\Tests\Support\CompiledAssets::read('pc', 'help-float'));
    }

    private function asset(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
