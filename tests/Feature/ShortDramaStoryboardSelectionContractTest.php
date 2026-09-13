<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ShortDramaStoryboardSelectionContractTest extends TestCase
{
    public function testCompletedGenerationOnlySetsAnEmptyStoryboardSelection(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/common/service/app/aigc_short_drama/AigcShortDramaService.php');
        $method = $this->methodSource($source, 'selectGeneratedStoryboardAsset');

        self::assertStringContainsString('->where($field, 0)->update($updateData)', $method);
    }

    public function testWorkbenchSerializesAndProtectsExplicitRecordSelections(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/web/pc/components/short-drama/StoryboardCreationWorkbench.vue');

        self::assertStringContainsString('storyboardSelectionQueues', $source);
        self::assertStringContainsString('applyStoryboardSelectionOverrides', $source);
        self::assertStringContainsString('Serialize writes per shot and media type', $source);
        self::assertStringContainsString('completed task only adds a record to history', $source);
        self::assertStringNotContainsString('updateLocalShotSelection(task.shot_id, targetAsset)', $source);
        self::assertStringContainsString("return previewAssetForShot(shotId)\n        || selectedAssetForShot(shotId, 'shot_video')", $source);
    }

    private function methodSource(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method);
        self::assertNotFalse($start, 'Expected method was not found.');

        $nextMethod = strpos($source, "\n    private static function ", $start + 1);
        return substr($source, $start, $nextMethod === false ? null : $nextMethod - $start);
    }
}
