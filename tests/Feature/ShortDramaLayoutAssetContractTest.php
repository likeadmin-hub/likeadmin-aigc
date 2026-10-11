<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ShortDramaLayoutAssetContractTest extends TestCase
{
    public function testShortDramaCssDependenciesUseStandardStylesheetPaths(): void
    {
        $entry = \Tests\Support\CompiledAssets::read('pc', 'entry');
        self::assertStringContainsString('.endsWith(".css")', $entry);
        foreach (['index' => 'short-drama-page__media-bg', 'plan' => '.plan-', 'VisualCreationWorkbench' => '.'] as $stem => $marker) {
            $path = \Tests\Support\CompiledAssets::path('pc', $stem, 'css', [$marker]);
            self::assertStringContainsString(basename($path), $entry);
            self::assertStringNotContainsString(basename($path) . '?v=', $entry);
        }
    }

    public function testEveryShortDramaPageUsesTheCanonicalEntryPath(): void
    {
        $root = \Tests\Support\CompiledAssets::root() . '/public/pc/ai/short-drama';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $entry = '/' . \Tests\Support\CompiledAssets::entry();
        $checked = 0;
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'html') continue;
            $html = (string)file_get_contents($file->getPathname());
            self::assertSame(2, substr_count($html, $entry), $file->getPathname());
            self::assertStringNotContainsString($entry . '?', $html);
            $checked++;
        }
        self::assertGreaterThanOrEqual(9, $checked);
    }

    public function testShortDramaBackgroundKeepsItsFullscreenLayoutRules(): void
    {
        $css = \Tests\Support\CompiledAssets::pcPage('ai/short-drama/index', 'css');
        self::assertMatchesRegularExpression('/\.short-drama-page__media-bg[^{]*\{[^}]*height:100%;[^}]*inset:0;[^}]*pointer-events:none;[^}]*position:fixed;[^}]*width:100%/', $css);
        self::assertStringContainsString('object-fit:cover', $css);
    }

    public function testStoryboardGenerationStatusRemainsScrollableAboveComposer(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/components/short-drama/StoryboardCreationWorkbench.vue');
        $css = \Tests\Support\CompiledAssets::pcPage('ai/short-drama/storyboard', 'css');
        self::assertMatchesRegularExpression('/\.draw-scroll[^{]*\{[^}]*contain:none[^}]*padding-bottom:24px[^}]*scroll-padding-bottom:24px/', $css);
        self::assertMatchesRegularExpression('/\.conversation-stream[^{]*\{[^}]*contain:none[^}]*content-visibility:visible/', $css);
        self::assertMatchesRegularExpression('/const scrollDrawToLatest = async.*?await nextTick\(\).*?target\.scrollTo\(\{\s*top: target\.scrollHeight,\s*behavior: smooth \? \'smooth\' : \'auto\'/s', $source);
        self::assertStringContainsString('chatMessages.value.push(message)', $source);
        self::assertStringContainsString('scrollDrawToLatest()', $source);
    }

}
