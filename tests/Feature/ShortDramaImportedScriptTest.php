<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaImportedScript;
use PHPUnit\Framework\TestCase;

class ShortDramaImportedScriptTest extends TestCase
{
    public function testLongSourceAndEndingArePreservedByteForByte(): void
    {
        $source = "\n" . str_repeat('完整台词与动作。', 10000) . "\n最终真相揭晓。\n";
        self::assertSame($source, ShortDramaImportedScript::sourceContent(['content' => $source]));
        self::assertSame($source, ShortDramaImportedScript::sourceContent(['source_content' => $source]));
        self::assertSame($source, ShortDramaImportedScript::sourceContent(['content' => '', 'source_content' => $source]));
    }

    public function testMissingOriginalIsNotFabricatedFromOutline(): void
    {
        self::assertSame('', ShortDramaImportedScript::sourceContent(['story_outline' => '故事梗概']));
    }

    public function testConflictingOriginalsAreNotSilentlyChosen(): void
    {
        $this->expectExceptionCode(422);
        ShortDramaImportedScript::sourceContent(['content' => '原文甲', 'source_content' => '原文乙']);
    }

    public function testStructuredOriginalIsNotConvertedIntoArrayString(): void
    {
        $this->expectExceptionCode(422);
        ShortDramaImportedScript::sourceContent(['content' => ['正文']]);
    }
}
