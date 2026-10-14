<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class PromptMentionShortcutContractTest extends TestCase
{
    public function testShortDramaComposersOpenTheSubjectPickerForTypedMentions(): void
    {
        $page = \Tests\Support\CompiledAssets::source('pc/pages/ai/short-drama/index.vue');
        $composer = \Tests\Support\CompiledAssets::source('pc/components/short-drama/ShortDramaMentionComposer.vue');
        self::assertGreaterThanOrEqual(2, substr_count($page, '<ShortDramaMentionComposer'));
        self::assertStringContainsString('@input="handleInput"', $composer);
        self::assertStringContainsString('@beforeinput="handleBeforeInput"', $composer);
        self::assertStringContainsString('@keydown="handleKeydown"', $composer);
        self::assertStringContainsString('[range.startOffset - 1] !== \'@\'', $composer);
        self::assertStringContainsString('triggerRange.setStart(range.startContainer, range.startOffset - 1)', $composer);
        self::assertStringContainsString('menuOpen.value = true', $composer);
        self::assertStringContainsString('insertion.insertNode(chip)', $composer);
        self::assertStringContainsString('setSelection(caret)', $composer);
        self::assertStringContainsString('short-drama-mention-composer', \Tests\Support\CompiledAssets::containing('pc', 'short-drama-mention-composer__chip'));
    }

    public function testCreateComposerDoesNotUseAtAsTheReferenceUploadShortcut(): void
    {
        $page = \Tests\Support\CompiledAssets::source('pc/pages/ai/create.vue');
        $studio = \Tests\Support\CompiledAssets::source('pc/apps/aigc_video/components/InspirationVideoStudio.vue');
        $input = \Tests\Support\CompiledAssets::source('pc/apps/aigc_video/components/VideoPromptInput.vue');
        self::assertStringContainsString('<InspirationVideoStudio', $page);
        self::assertStringContainsString('<VideoPromptInput', $studio);
        self::assertStringContainsString(':items="mentionItems"', $studio);
        self::assertStringContainsString('promptInput?.openMentions($event)', $studio);
        self::assertStringNotContainsString("emit('upload')", $input);
        self::assertStringContainsString('vp-editor', \Tests\Support\CompiledAssets::containing('pc', 'vp-chip'));
    }

    public function testCreationImageAndVideoPagesDoNotUseAtAsTheUploadShortcut(): void
    {
        $studio = \Tests\Support\CompiledAssets::source('pc/apps/aigc_video/components/InspirationVideoStudio.vue');
        $input = \Tests\Support\CompiledAssets::source('pc/apps/aigc_video/components/VideoPromptInput.vue');
        self::assertStringContainsString(':media-type="generationMode"', \Tests\Support\CompiledAssets::source('pc/pages/ai/create.vue'));
        self::assertStringContainsString('isImage', $studio);
        self::assertStringContainsString('openMentions', $input);
        self::assertStringNotContainsString("emit('upload')", $input);
        self::assertNotEmpty(\Tests\Support\CompiledAssets::pcPage('app/aigc_image'));
        self::assertNotEmpty(\Tests\Support\CompiledAssets::pcPage('app/aigc_video'));
    }

    public function testUploadedReferencesAreMentionableAndShortDramaAtOpensThePicker(): void
    {
        $fix = $this->asset('public/prompt-mention-fix.js');

        self::assertStringContainsString('textarea.prompt-card__textarea', $fix);
        self::assertStringContainsString('.reference-item, .upload-panel__preview-frame', $fix);
        self::assertStringContainsString('prompt-mention-picker', $fix);
        self::assertStringContainsString('collectReferenceItems(event.target)', $fix);
        self::assertStringContainsString('var counters = { img: 0, video: 0, audio: 0, reference: 0 }', $fix);
        self::assertStringContainsString('type === "img" ? "图片"', $fix);
        self::assertStringContainsString('label.textContent = "@" + item.name', $fix);
        self::assertStringContainsString('target.dispatchEvent(new Event("input"', $fix);
        self::assertStringContainsString('target.dispatchEvent(new Event("change"', $fix);
        self::assertStringContainsString('target.matches(".composer-editor")', $fix);
        self::assertStringContainsString('button.click()', $fix);
        self::assertStringContainsString('event.stopImmediatePropagation()', $fix);

        foreach ([
            'public/pc/ai/index.html',
            'public/pc/ai/create/index.html',
            'public/pc/app/aigc_image/index.html',
            'public/pc/app/aigc_video/index.html',
            'public/pc/ai/short-drama/index.html',
            'public/pc/ai/short-drama/storyboard/index.html',
        ] as $html) {
            self::assertStringContainsString('/prompt-mention-fix.js?v=20260826-mention-picker', $this->asset($html));
        }
    }

    public function testShortDramaWorkbenchPromptsUseAtToOpenSubjectAndScenePickers(): void
    {
        $source = \Tests\Support\CompiledAssets::source('pc/components/short-drama/VisualCreationWorkbench.vue');
        self::assertStringContainsString('@keydown="subjectPromptAtKeydown"', $source);
        self::assertStringContainsString('@beforeinput="subjectPromptBeforeInput"', $source);
        self::assertStringContainsString('@keydown="scenePromptAtKeydown"', $source);
        self::assertStringContainsString('@beforeinput="scenePromptBeforeInput"', $source);
        self::assertStringContainsString('event.key === \'@\' && !event.ctrlKey && !event.metaKey && !event.altKey', $source);
        self::assertStringContainsString('event.inputType === \'insertText\' && event.data === \'@\'', $source);
        self::assertStringContainsString('void openLibraryImport()', $source);
        self::assertStringContainsString('void openSceneLibraryImport()', $source);
        self::assertMatchesRegularExpression('/\.key==="@"&&![A-Za-z_$][\w$]*\.ctrlKey&&![A-Za-z_$][\w$]*\.metaKey&&![A-Za-z_$][\w$]*\.altKey/', \Tests\Support\CompiledAssets::read('pc', 'VisualCreationWorkbench'));
    }

    public function testShortDramaDetailCreationPagesShowMentionMenusForAt(): void
    {
        foreach (['images', 'video'] as $page) {
            $source = \Tests\Support\CompiledAssets::source('pc/pages/ai/short-drama/' . $page . '.vue');
        self::assertStringContainsString('event.key === \'@\' && !event.ctrlKey && !event.metaKey && !event.altKey', $source);
        self::assertStringContainsString('event.preventDefault()', $source);
        self::assertStringContainsString('selectedMentions', $source);
        self::assertStringContainsString('mention_shot_ids:', $source);
        self::assertStringContainsString('selected_mentions:', $source);
        self::assertStringContainsString('class="short-drama-mention-menu"', $source);
            self::assertStringContainsString('short-drama-mention-menu', \Tests\Support\CompiledAssets::pcPage('ai/short-drama/' . $page));
            self::assertStringContainsString('.short-drama-mention-menu', \Tests\Support\CompiledAssets::pcPage('ai/short-drama/' . $page, 'css'));
        }
    }

    public function testInfiniteCanvasAgentAndAudioPromptsReactToAt(): void
    {
        $agent = \Tests\Support\CompiledAssets::source('pc/apps/aigc_canvas/components/agent/CanvasAgentPanel.vue');
        $node = \Tests\Support\CompiledAssets::source('pc/apps/aigc_canvas/components/native/CanvasNode.vue');
        self::assertStringContainsString('handleComposerInput', $agent);
        self::assertStringContainsString('prefix.lastIndexOf(\'@\')', $agent);
        self::assertStringContainsString('composerMentionOpen.value = true', $agent);
        self::assertStringContainsString('selectedComposerMentions()', $agent);
        self::assertStringContainsString('visible_nodes', $agent);
        self::assertStringContainsString('canvas-node-audio-prompt-textarea', $node);
        self::assertStringContainsString('canvas-node-audio-lyrics-textarea', $node);
        foreach (['prompt', 'lyrics'] as $field) {
            self::assertStringContainsString('@keydown="handleAudioMentionKeydown($event, \'' . $field . '\')"', $node);
            self::assertStringContainsString('@beforeinput="handleAudioMentionBeforeInput($event, \'' . $field . '\')"', $node);
        }
        self::assertStringContainsString('textarea.setSelectionRange(start + inserted.length, start + inserted.length)', $node);
        self::assertStringContainsString('if (!insertAudioMention(item)) appendMentionToComposer(item)', $node);
        self::assertStringContainsString('agent-composer-mention-menu', \Tests\Support\CompiledAssets::read('pc', 'projects', 'js', ['agent-composer']));
    }

    public function testInfiniteCanvasAtFallbackIsLoadedFromTheActualPcEntry(): void
    {
        $fix = $this->asset('public/canvas-agent-at-fix.js');

        self::assertStringContainsString('.agent-composer-editor', $fix);
        self::assertStringContainsString('event.preventDefault()', $fix);
        self::assertStringContainsString('event.stopImmediatePropagation()', $fix);
        self::assertStringContainsString('document.createTextNode("@")', $fix);
        self::assertStringContainsString('new KeyboardEvent("keydown"', $fix);

        foreach ([
            'public/pc/index.html',
            'public/pc/app/aigc_canvas/index.html',
            'public/pc/app/aigc_canvas/replay/index.html',
        ] as $html) {
            self::assertStringContainsString('/canvas-agent-at-fix.js?v=20260824-canvas-at-v1', $this->asset($html));
        }
    }

    public function testMentionFixBundlesAreCacheBustedFromPcEntryPoints(): void
    {
        foreach (['ai/short-drama/images', 'ai/short-drama/video', 'ai/create', 'app/aigc_canvas/project/[id]'] as $page) {
            self::assertNotEmpty(\Tests\Support\CompiledAssets::pcPage($page));
        }
        foreach (['VisualCreationWorkbench', 'subject-create', 'scene'] as $stem) {
            $path = \Tests\Support\CompiledAssets::path('pc', $stem);
            self::assertMatchesRegularExpression('/\.[a-f0-9]{8}\.js$/', $path);
            self::assertContains($path, \Tests\Support\CompiledAssets::reachable());
        }
    }

    public function testPcHtmlEntryScriptKeepsOneModuleIdentity(): void
    {
        $root = \Tests\Support\CompiledAssets::root() . '/public/pc';
        $entry = '/' . \Tests\Support\CompiledAssets::entry();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'html') continue;
            $source = (string)file_get_contents($file->getPathname());
            if (!str_contains($source, '__NUXT_DATA__')) continue;
            self::assertStringContainsString('src="' . $entry . '"', $source, $file->getPathname());
            self::assertStringContainsString('href="' . $entry . '"', $source, $file->getPathname());
            self::assertStringNotContainsString($entry . '?', $source, $file->getPathname());
            $checked++;
        }
        self::assertGreaterThan(0, $checked, 'At least one actual packaged PC entry must be checked');
    }

    private function asset(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
