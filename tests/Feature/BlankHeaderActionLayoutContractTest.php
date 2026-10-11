<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class BlankHeaderActionLayoutContractTest extends TestCase
{
    public function testBlankLayoutActionsStayVisibleAtTheTrailingEdge(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/layouts/blank.vue');
        $layout = \Tests\Support\CompiledAssets::read('pc', 'blank');
        $styles = \Tests\Support\CompiledAssets::read('pc', 'blank', 'css');
        self::assertStringContainsString('blank-header-actions is-workspace', $source);
        self::assertStringContainsString('blank-tutorial-link', $source);
        self::assertStringContainsString('blank-tutorial-label', $source);
        self::assertStringContainsString('current === \'en\' ? \'Tutorial\'', $source);
        self::assertStringContainsString('current === \'zh-TW\' ? \'新手教學\' : \'新手教程\'', $source);
        self::assertStringContainsString('.flow-right', $source);
        self::assertStringContainsString('.app-header__actions', $source);
        self::assertStringContainsString('.topbar-right', $source);
        self::assertStringContainsString('.short-drama-credit-pill.is-fixed', $source);
        self::assertStringContainsString('hasVisibleDialog', $source);
        self::assertStringContainsString('style.display !== \'none\'', $source);
        self::assertStringContainsString('document.body.appendChild(host)', $source);
        self::assertStringContainsString('host.setAttribute(\'data-blank-body-host\', \'\')', $source);
        self::assertStringContainsString('window.innerWidth - rect.left + 10', $source);
        self::assertStringContainsString('host.appendChild(actions)', $source);
        self::assertStringContainsString('actions.classList.add(\'is-attached\')', $source);
        self::assertStringContainsString('blank-header-actions', $layout);
        self::assertStringContainsString('blank-tutorial-label', $layout);
        self::assertMatchesRegularExpression('/\.blank-header-actions[^{]*\{[^}]*display:none/', $styles);
        self::assertMatchesRegularExpression('/\.blank-header-actions\.is-attached[^{]*\{[^}]*display:flex[^}]*pointer-events:auto/', $styles);
        self::assertStringNotContainsString('position:fixed!important', $styles);
        self::assertStringNotContainsString('[object Object]', $layout);
        self::assertContains(\Tests\Support\CompiledAssets::path('pc', 'blank'), \Tests\Support\CompiledAssets::reachable());
    }

    private function asset(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
