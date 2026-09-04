<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class PromptMentionShortcutContractTest extends TestCase
{
    public function testShortDramaComposersOpenTheSubjectPickerForTypedMentions(): void
    {
        $source = $this->asset('public/_nuxt/index.331744a3.js');

        self::assertStringContainsString('/(^|\\s)@[^\\s@]*$/.test(t)', $source);
        self::assertMatchesRegularExpression('/onInput:\\s*(?:\\(e\\)|e)\\s*=>\\s*handleMentionInput\\(e,\\s*"main"\\)/', $source);
        self::assertMatchesRegularExpression('/onInput:\\s*(?:\\(e\\)|e)\\s*=>\\s*handleMentionInput\\(e,\\s*"floating"\\)/', $source);
        self::assertStringContainsString('onKeydown:e=>handleMentionKeydown(e,"main")', $source);
        self::assertStringContainsString('onBeforeinput:e=>handleMentionBeforeInput(e,"floating")', $source);
        self::assertStringContainsString('d.match(/(^|\\s)@[^\\s@]*$/)', $source);
        self::assertMatchesRegularExpression('/n\\.setSelectionRange\\(W,\\s*W\\)/', $source);
    }

    public function testCreateComposerDoesNotUseAtAsTheReferenceUploadShortcut(): void
    {
        $source = $this->asset('public/_nuxt/create.a5c396bf.js');

        self::assertStringContainsString('Te=()=>{}', $source);
        self::assertStringContainsString('Pe=()=>{}', $source);
        self::assertMatchesRegularExpression('/onKeydown:\\s*\\[\\s*Te,/', $source);
        self::assertStringNotContainsString('i.preventDefault(),Xe(),x("upload")', $source);
    }

    public function testCreationImageAndVideoPagesDoNotUseAtAsTheUploadShortcut(): void
    {
        $imageSource = $this->asset('public/_nuxt/aigc_image.91b50432.js');
        $videoSource = $this->asset('public/_nuxt/aigc_video.93b37873.js');

        self::assertStringContainsString('imagePromptAtKeydown=()=>{}', $imageSource);
        self::assertStringContainsString('id:"image-prompt"', $imageSource);
        self::assertStringContainsString('onKeydown:imagePromptAtKeydown', $imageSource);
        self::assertStringContainsString('onBeforeinput:()=>{}', $imageSource);
        self::assertStringNotContainsString('e.preventDefault(),Qe()', $imageSource);

        self::assertStringContainsString('videoPromptAtKeydown=()=>{}', $videoSource);
        self::assertStringContainsString('id:"video-prompt"', $videoSource);
        self::assertStringContainsString('onKeydown:videoPromptAtKeydown', $videoSource);
        self::assertStringContainsString('onBeforeinput:()=>{}', $videoSource);
        self::assertStringNotContainsString('e.preventDefault(),Rt()', $videoSource);
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
        $source = $this->asset('public/_nuxt/VisualCreationWorkbench.20b439b0.js');

        self::assertStringContainsString('subjectPromptAtKeydown=e=>{(e.key==="@"', $source);
        self::assertStringContainsString('scenePromptAtKeydown=e=>{(e.key==="@"', $source);
        self::assertStringContainsString('onKeydown:subjectPromptAtKeydown', $source);
        self::assertStringContainsString('onKeydown:scenePromptAtKeydown', $source);
        self::assertStringContainsString('onBeforeinput:e=>e.inputType==="insertText"&&e.data==="@"&&(e.preventDefault(),jn())', $source);
    }

    public function testShortDramaDetailCreationPagesShowMentionMenusForAt(): void
    {
        $imageSource = $this->asset('public/_nuxt/images.b7453ae1.js');
        $videoSource = $this->asset('public/_nuxt/video.db2e73b5.js');
        $imageStyles = $this->asset('public/_nuxt/images.f00320f4.css');
        $videoStyles = $this->asset('public/_nuxt/video.259a2f0a.css');

        self::assertStringContainsString('activeMentionType=_("")', $imageSource);
        self::assertStringContainsString(
            'mentionKey=(s,a)=>{a.key==="Escape"&&(activeMentionType.value=""),a.key==="@"&&!a.ctrlKey&&!a.metaKey&&!a.altKey&&(a.preventDefault(),activeMentionType.value=s)}',
            $imageSource
        );
        self::assertStringContainsString('insertMention=(s,a)=>{const e=mentionLabel(a);', $imageSource);
        self::assertStringContainsString('selectedMentions=_([])', $imageSource);
        self::assertStringContainsString('mentionPayload=s=>', $imageSource);
        self::assertStringContainsString('mention_shot_ids:c', $imageSource);
        self::assertStringContainsString('...mentionPayload(s),params:{...mentionPayload(s)', $imageSource);
        self::assertStringContainsString('class:"short-drama-mention-menu"', $imageSource);
        self::assertStringContainsString('\\u6682\\u65e0\\u53ef\\u5f15\\u7528\\u5185\\u5bb9', $imageSource);

        self::assertStringContainsString('activeMentionType=v(!1)', $videoSource);
        self::assertStringContainsString(
            'mentionKey=s=>{s.key==="Escape"&&(activeMentionType.value=!1),s.key==="@"&&!s.ctrlKey&&!s.metaKey&&!s.altKey&&(s.preventDefault(),activeMentionType.value=!0)}',
            $videoSource
        );
        self::assertStringContainsString('insertMention=s=>{const t=mentionLabel(s);', $videoSource);
        self::assertStringContainsString('selectedMentions=v([])', $videoSource);
        self::assertStringContainsString('mention_shot_ids:n', $videoSource);
        self::assertStringContainsString('type:"shot"', $videoSource);
        self::assertStringContainsString('class:"short-drama-mention-menu"', $videoSource);
        self::assertStringContainsString('\\u6682\\u65e0\\u53ef\\u5f15\\u7528\\u5185\\u5bb9', $videoSource);

        self::assertStringContainsString('.short-drama-mention-menu', $imageStyles);
        self::assertStringContainsString('.short-drama-mention-menu', $videoStyles);
    }

    public function testInfiniteCanvasAgentAndAudioPromptsReactToAt(): void
    {
        $agentSource = $this->asset('public/_nuxt/projects.b63ba847.js');
        $nodeSource = $this->asset('public/_nuxt/_id_.2fb60286.js');

        self::assertStringContainsString('function Ys()', $agentSource);
        self::assertStringContainsString('function agentMentionBeforeInput(e)', $agentSource);
        self::assertStringContainsString('e.code==="Digit2"&&e.shiftKey', $agentSource);
        self::assertStringContainsString('agent-composer-mention-menu', $agentSource);
        self::assertStringContainsString('Array.isArray(e.visible_nodes)?e.visible_nodes:[]', $agentSource);
        self::assertStringContainsString('t==null?void 0:t.label', $agentSource);
        self::assertStringContainsString('t==null?void 0:t.preview_url', $agentSource);
        self::assertStringContainsString('e.inputType==="insertText"&&e.data==="@"&&setTimeout(Ys,0)', $agentSource);
        self::assertStringNotContainsString('e.data==="@"&&(e.preventDefault(),setTimeout(Ys,0))', $agentSource);
        self::assertStringContainsString('t==null?void 0:t.metadata', $agentSource);

        self::assertStringContainsString('function Dr(o){', $nodeSource);
        self::assertStringContainsString('function insertTextareaMention(o)', $nodeSource);
        self::assertStringContainsString('onBeforeinput:mentionBeforeInput', $nodeSource);
        self::assertStringContainsString(
            '"data-testid":"canvas-node-audio-prompt-textarea",onInput:Ns,onKeydown:Dr',
            $nodeSource
        );
        self::assertStringContainsString(
            '"data-testid":"canvas-node-audio-lyrics-textarea",onInput:Ts,onKeydown:Dr',
            $nodeSource
        );
        self::assertStringContainsString(
            'Array.isArray(_t.elements)&&_t.elements.length===E.value.length',
            $nodeSource
        );
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
        $entry = $this->asset('public/_nuxt/entry.c46691d5.js');
        $canvasHome = $this->asset('public/_nuxt/index.58ab2732.js');
        $canvasProject = $this->asset('public/_nuxt/_id_.2fb60286.js');
        $shortDramaSubject = $this->asset('public/_nuxt/subject-create.985b7b2c.js');
        $shortDramaScene = $this->asset('public/_nuxt/scene.f6c7fe02.js');

        foreach ([
            'VisualCreationWorkbench.20b439b0.js',
            'images.b7453ae1.js',
            'video.db2e73b5.js',
            'images.f00320f4.css',
            'video.259a2f0a.css',
            'index.58ab2732.js',
            'scene.f6c7fe02.js',
            'subject-create.985b7b2c.js',
        ] as $asset) {
            self::assertStringContainsString('./' . $asset . '?v=20260819-atfix', $entry);
        }

        self::assertStringContainsString('./_id_.2fb60286.js?v=20260827-connected-video-v1', $entry);
        self::assertStringContainsString('./create.a5c396bf.js?v=20260826-mention-picker', $entry);
        self::assertStringContainsString('./storyboard.f2381e09.js?v=20260826-video-model-state-v2', $entry);
        self::assertStringContainsString('./aigc_image.91b50432.js?v=20260826-mention-picker', $entry);
        self::assertStringContainsString('./aigc_video.93b37873.js?v=20260826-mention-picker', $entry);
        self::assertStringContainsString('./projects.b63ba847.js?v=20260826-local-reference-v1', $entry);
        self::assertStringContainsString('./projects.b63ba847.js?v=20260826-local-reference-v1', $canvasHome);
        self::assertStringContainsString('./projects.b63ba847.js?v=20260826-local-reference-v1', $canvasProject);
        self::assertStringContainsString('./VisualCreationWorkbench.20b439b0.js?v=20260819-atfix', $shortDramaSubject);
        self::assertStringContainsString('./VisualCreationWorkbench.20b439b0.js?v=20260819-atfix', $shortDramaScene);
    }

    public function testPcHtmlEntryScriptKeepsOneModuleIdentity(): void
    {
        $root = dirname(__DIR__, 2) . '/public/pc';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'html') {
                continue;
            }

            $source = (string)file_get_contents($file->getPathname());
            if (!str_contains($source, '/_nuxt/entry.c46691d5.js')) {
                continue;
            }

            self::assertStringContainsString('src="/_nuxt/entry.c46691d5.js"', $source);
            self::assertStringContainsString('href="/_nuxt/entry.c46691d5.js"', $source);
            self::assertStringNotContainsString('/_nuxt/entry.c46691d5.js?', $source);
        }
    }

    private function asset(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
