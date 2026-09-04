<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ShortDramaStyleSearchContractTest extends TestCase
{
    public function testShortDramaPlanLoadsStyleSearchEnhancement(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root . '/public/short-drama-style-search.js');
        $stylesheet = (string) file_get_contents($root . '/public/_nuxt/short-drama-style-search.css');

        self::assertStringContainsString("panelSelector = '.drama-tool-panel--style, .plan-tool-panel--style'", $script);
        self::assertStringContainsString("panel.querySelector('.drama-style-grid, .plan-style-grid')", $script);
        self::assertStringContainsString("panel.querySelector('.drama-picker-panel__head, .plan-picker-panel__head')", $script);
        self::assertStringContainsString("var panelStates = typeof WeakMap === 'function' ? new WeakMap() : null", $script);
        self::assertStringContainsString("if (panelStates && panelStates.get(panel) === signature)", $script);
        self::assertStringContainsString("element.closest('button[aria-label*=\"画风\"]')", $script);
        self::assertStringContainsString('window.setTimeout(pollForPanel, 250)', $script);
        self::assertStringContainsString("input.placeholder = '搜索风格'", $script);
        self::assertStringContainsString("indexOf(query) !== -1", $script);
        self::assertStringContainsString("empty.textContent = '没有匹配的风格'", $script);
        self::assertStringContainsString('/_nuxt/short-drama-style-search.css?v=20260825-style-search-v5', $script);
        self::assertStringContainsString('.short-drama-style-search:focus', $stylesheet);
        self::assertStringContainsString('.short-drama-style-search-empty', $stylesheet);
        self::assertStringContainsString('.drama-tool-panel--style .short-drama-style-search', $stylesheet);

        foreach ([
            '/public/pc/index.html',
            '/public/pc/ai/index.html',
            '/public/pc/ai/short-drama/index.html',
            '/public/pc/ai/short-drama/plan/index.html',
        ] as $page) {
            $html = (string) file_get_contents($root . $page);
            self::assertStringContainsString('/short-drama-style-search.js?v=20260825-style-search-v5', $html);
        }
    }
}
