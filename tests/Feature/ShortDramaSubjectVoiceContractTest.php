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
        $source = \Tests\Support\CompiledAssets::source('pc/components/short-drama/VisualCreationWorkbench.vue');
        $api = \Tests\Support\CompiledAssets::containing('pc', '/app.aigc_short_drama.voice/save');
        self::assertStringContainsString('/app.aigc_short_drama.voice/trim', $api);
        self::assertStringContainsString('voice.status !== \'ready\'', $source);
        self::assertStringContainsString('voiceCloneBusy.value', $source);
        self::assertStringContainsString('const handleVoiceUpload = async', $source);
        self::assertStringContainsString('promptResult as any', $source);
        self::assertStringContainsString('if (duration > 10)', $source);
        self::assertStringContainsString('await trimShortDramaVoice({ file, data: { start: \'0\', duration: \'10\' } })', $source);
        self::assertStringContainsString('await uploadVoiceFile({ file })', $source);
        self::assertStringContainsString('Math.min(10, Math.ceil(duration))', $source);
        self::assertStringContainsString('voice-clone-btn', \Tests\Support\CompiledAssets::read('pc', 'VisualCreationWorkbench'));
        self::assertStringContainsString('sound-btn', \Tests\Support\CompiledAssets::read('pc', 'VisualCreationWorkbench'));
    }

}
