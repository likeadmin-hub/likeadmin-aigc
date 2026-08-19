<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CustomerServiceLabelContractTest extends TestCase
{
    public function testCustomerServiceButtonUsesTheOnlineSupportLabel(): void
    {
        $component = $this->asset('public/_nuxt/index.de219304.js');
        $locales = $this->asset('public/_nuxt/entry.c46691d5.js');

        self::assertStringContainsString('g(h("联系在线客服"))', $component);
        self::assertStringContainsString('disabled:!B.value.enterprise_wechat_url,onClick:Ha', $component);
        self::assertStringNotContainsString('联系企业微信客户', $component);

        self::assertStringContainsString(
            '联系在线客服:{"zh-TW":{t:0,b:{t:2,i:[{t:3}],s:"聯絡線上客服"}},en:{t:0,b:{t:2,i:[{t:3}],s:"Contact online support"}}}',
            $locales
        );
        self::assertStringNotContainsString('联系企业微信客户', $locales);
        self::assertStringNotContainsString('Contact WeCom support', $locales);
    }

    private function asset(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
