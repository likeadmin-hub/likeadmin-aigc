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
        self::assertStringContainsString('d.match(/(^|\\s)@[^\\s@]*$/)', $source);
        self::assertMatchesRegularExpression('/n\\.setSelectionRange\\(W,\\s*W\\)/', $source);
    }

    public function testCreateComposerUsesAtAsTheReferenceUploadShortcut(): void
    {
        $source = $this->asset('public/_nuxt/create.a5c396bf.js');

        self::assertMatchesRegularExpression('/i\\.key\\s*===\\s*"@"/', $source);
        self::assertMatchesRegularExpression('/i\\.preventDefault\\(\\),\\s*Xe\\(\\),\\s*x\\("upload"\\)/', $source);
        self::assertMatchesRegularExpression('/onKeydown:\\s*\\[\\s*Te,/', $source);
    }

    private function asset(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
