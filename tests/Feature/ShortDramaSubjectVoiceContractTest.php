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

    public function testSourceWorkbenchSupportsCloningAndBlocksPendingVoices(): void
    {
        // Generated filenames are not a source delivery contract. Verify the neighboring frontend checkout.
        $web = dirname(__DIR__, 3) . '/web/pc';
        if (!is_file($web . '/components/short-drama/VisualCreationWorkbench.vue')) self::markTestSkipped('Frontend checkout required for cross-repository source contract');
        $api = (string)file_get_contents($web . '/api/short_drama.ts');
        $workbench = (string)file_get_contents($web . '/components/short-drama/VisualCreationWorkbench.vue');
        foreach (['save', 'trim'] as $action) self::assertStringContainsString('/app.aigc_short_drama.voice/' . $action, $api);
        self::assertStringContainsString(':disabled="voice.status !== \'ready\'"', $workbench);
        self::assertStringContainsString(':disabled="!pendingVoice || pendingVoice.status !== \'ready\'"', $workbench);
        self::assertStringContainsString('class="voice-clone-btn"', $workbench);
        self::assertStringContainsString('class="sound-btn"', $workbench);
        self::assertStringContainsString('if (!file || voiceCloneBusy.value) return', $workbench);
        self::assertStringContainsString('await uploadVoiceFile({ file })', $workbench);
        self::assertStringContainsString("await trimShortDramaVoice({ file, data: { start: '0', duration: '10' } })", $workbench);
        self::assertStringContainsString("String((promptResult as any)?.value ?? promptResult ?? '').trim()", $workbench);
        self::assertStringContainsString('Math.min(10, Math.ceil(duration))', $workbench);
        self::assertStringContainsString('await saveShortDramaVoice({ name, audio_uri: audioUri, duration: cloneDuration })', $workbench);
    }
}
