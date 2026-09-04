<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ShortDramaLayoutAssetContractTest extends TestCase
{
    public function testShortDramaCssDependenciesUseStandardStylesheetPaths(): void
    {
        $root = dirname(__DIR__, 2);
        $entry = (string)file_get_contents($root . '/public/_nuxt/entry.c46691d5.js');

        self::assertStringContainsString('const o=r.endsWith(".css")', $entry);
        foreach ([
            'index.a3dd556e20.css',
            'plan.95e7815f20.css',
            'VisualCreationWorkbench.d4613354.css',
        ] as $stylesheet) {
            self::assertStringContainsString('"./' . $stylesheet . '"', $entry);
            self::assertDoesNotMatchRegularExpression(
                '/' . preg_quote($stylesheet, '/') . '\?v=/',
                $entry
            );
        }
    }

    public function testEveryShortDramaPageUsesTheCanonicalEntryPath(): void
    {
        $root = dirname(__DIR__, 2);
        $pcEntry = (string)file_get_contents($root . '/public/pc/index.html');
        $shortDramaRoot = $root . '/public/pc/ai/short-drama';
        $pages = array_merge(
            [$shortDramaRoot . '/index.html'],
            glob($shortDramaRoot . '/*/index.html') ?: []
        );

        self::assertSame(
            2,
            substr_count($pcEntry, '/_nuxt/entry.c46691d5.js')
        );
        self::assertDoesNotMatchRegularExpression('/entry\.c46691d5\.js\?v=/', $pcEntry);
        self::assertCount(9, $pages);
        foreach ($pages as $page) {
            $html = (string)file_get_contents($page);
            self::assertSame(
                2,
                substr_count($html, '/_nuxt/entry.c46691d5.js'),
                $page
            );
            self::assertDoesNotMatchRegularExpression(
                '/entry\.c46691d5\.js\?v=/',
                $html,
                $page
            );
        }
    }

    public function testShortDramaBackgroundKeepsItsFullscreenLayoutRules(): void
    {
        $root = dirname(__DIR__, 2);
        $css = (string)file_get_contents($root . '/public/_nuxt/index.a3dd556e20.css');

        self::assertStringContainsString(
            '.short-drama-page__media-bg[data-v-7ba4ab9b]{height:100%;inset:0;pointer-events:none;position:fixed;width:100%}',
            $css
        );
        self::assertStringContainsString('object-fit:cover', $css);
    }

    public function testStoryboardGenerationStatusRemainsScrollableAboveComposer(): void
    {
        $root = dirname(__DIR__, 2);
        $css = (string)file_get_contents($root . '/public/_nuxt/storyboard.1ba7be52.css');
        $javascript = (string)file_get_contents($root . '/public/_nuxt/storyboard.f2381e09.js');

        self::assertStringContainsString(
            '.draw-scroll[data-v-d7ca7865]{contain:none;overflow-anchor:none;padding-bottom:24px;scroll-padding-bottom:24px}',
            $css
        );
        self::assertStringContainsString(
            '.conversation-stream[data-v-d7ca7865]{contain:none;content-visibility:visible}',
            $css
        );
        self::assertStringContainsString(
            'Math.max(0,t.scrollHeight-t.clientHeight)',
            $javascript
        );
        self::assertStringContainsString('requestAnimationFrame(()=>a(!1))', $javascript);
    }
}
