<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaSubjectVoiceContractTest extends TestCase
{
    public function testShortDramaExposesVoiceCloneServiceMethods(): void
    {
        foreach (['saveVoice', 'previewVoice', 'trimVoiceSample', 'processPendingVoiceClones'] as $method) {
            self::assertTrue(method_exists(AigcShortDramaService::class, $method));
        }

        $normalizer = new ReflectionMethod(AigcShortDramaService::class, 'normalizeSubjectVoiceSelection');
        self::assertTrue($normalizer->isPrivate());
    }

    public function testVoiceApiSchemaAndSubjectDatabaseContractAreRegistered(): void
    {
        $root = dirname(__DIR__, 2);
        $schema = json_decode(
            (string)file_get_contents($root . '/app/apps/aigc_short_drama/api_schema.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $paths = array_column((array)($schema['apis'] ?? []), 'api_path');
        foreach (['save', 'preview', 'trim'] as $action) {
            self::assertContains('app.aigc_short_drama.voice/' . $action, $paths);
        }

        $installSql = (string)file_get_contents($root . '/app/apps/aigc_short_drama/migrations/install.sql');
        $upgradeSql = (string)file_get_contents($root . '/upgrade/20260815_short_drama_subject_voice.sql');
        foreach (['voice_id', 'voice_name', 'voice_label', 'voice_source'] as $column) {
            self::assertStringContainsString('`' . $column . '`', $installSql);
            self::assertStringContainsString("COLUMN_NAME = '{$column}'", $upgradeSql);
        }
    }

    public function testVoiceNamesAcceptPromptResultObjectsAndHideLegacyObjectStrings(): void
    {
        $normalizer = new ReflectionMethod(AigcDigitalHumanService::class, 'normalizeVoiceName');
        $normalizer->setAccessible(true);

        self::assertSame('我的女主音色', $normalizer->invoke(null, [
            'value' => '我的女主音色',
            'action' => 'confirm',
        ], '我的声音'));
        self::assertSame('未命名声音', $normalizer->invoke(null, '[object Object]', '未命名声音'));
    }

    public function testCompiledWorkbenchSupportsCloningAndBlocksPendingVoices(): void
    {
        $root = dirname(__DIR__, 2);
        $api = (string)file_get_contents($root . '/public/_nuxt/short_drama.629b99f7.js');
        $workbench = (string)file_get_contents($root . '/public/_nuxt/VisualCreationWorkbench.20b439b0.js');

        self::assertStringContainsString('/app.aigc_short_drama.voice/save', $api);
        self::assertStringContainsString('/app.aigc_short_drama.voice/trim', $api);
        self::assertStringContainsString('Tt as T', $api);
        self::assertStringContainsString('voiceCloneBusy', $workbench);
        self::assertStringContainsString('voiceUploadInputRef', $workbench);
        self::assertStringContainsString('status!=="ready"', $workbench);
        self::assertStringContainsString('class:"voice-clone-btn"', $workbench);
        self::assertStringContainsString('class:"sound-btn"', $workbench);
        self::assertStringContainsString('Y as uploadVoiceFile', $workbench);
        self::assertStringContainsString('T as trimVoiceSample', $workbench);
        $handlerStart = strpos($workbench, 'handleVoiceUpload=async');
        self::assertIsInt($handlerStart);
        $handlerEnd = strpos($workbench, ',ut=async', $handlerStart);
        self::assertIsInt($handlerEnd);
        $voiceUploadHandler = substr($workbench, $handlerStart, $handlerEnd - $handlerStart);

        self::assertStringContainsString('await uploadVoiceFile({file:t})', $voiceUploadHandler);
        self::assertStringContainsString(
            'await trimVoiceSample({file:t,data:{start:"0",duration:"10"}})',
            $voiceUploadHandler
        );
        self::assertStringContainsString('String(s?.value??s??"").trim()', $voiceUploadHandler);
        self::assertStringNotContainsString('String(s||"").trim()', $voiceUploadHandler);
        self::assertStringContainsString('Math.min(10,Math.ceil', $voiceUploadHandler);
        self::assertStringNotContainsString('await pl({file:t})', $voiceUploadHandler);
        self::assertStringNotContainsString('return}const s=await m.prompt', $voiceUploadHandler);
    }
}
