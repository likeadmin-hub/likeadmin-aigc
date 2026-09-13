<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaStoryDraft;
use app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow;
use PHPUnit\Framework\TestCase;

class ShortDramaStoryDraftTest extends TestCase
{
    public function testIncompleteEditsAreRetainedWithoutAllowingConfirmation(): void
    {
        $original = ['title' => '原始剧名', 'subjects' => [['id' => 's1', 'name' => '甲']]];
        $draft = ShortDramaStoryDraft::merge($original, ['title' => '', '_prompt_snapshot' => ['fake'], 'storyboard' => [['fake']]], 'story');
        self::assertSame('', $draft['title']);
        self::assertSame('原始剧名', $original['title']);
        self::assertArrayNotHasKey('_prompt_snapshot', $draft);
        self::assertArrayNotHasKey('storyboard', $draft);
        self::assertNotEmpty(ShortDramaStoryWorkflow::issues($draft, 'story', 10));
    }

    public function testOnlyNewVariantUsesDraftAndOriginalRemainsIntact(): void
    {
        $request = ['multi_episode' => true, 'episode_count' => 10, '_story_draft' => ['version' => 2, 'result' => ['title' => '编辑']]];
        $original = ['title' => '模型'];
        self::assertSame($original, ShortDramaStoryDraft::effective($request, $original));
        $request['workflow_variant'] = ShortDramaStoryWorkflow::VARIANT;
        self::assertSame('编辑', ShortDramaStoryDraft::effective($request, $original)['title']);
        ShortDramaStoryDraft::assertVersion($request, ['draft_version' => 2]);
        $this->expectException(\InvalidArgumentException::class);
        ShortDramaStoryDraft::assertVersion($request, ['draft_version' => 1]);
    }

    public function testStableIdsCannotBeReplaced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ShortDramaStoryDraft::merge(['subjects' => [['id' => 's1']]], ['subjects' => [['id' => 's2']]], 'story');
    }

    public function testAllFourOutlineFieldsAreEditableButNumbersAreNot(): void
    {
        $base = ['episodes' => [['episode_number' => 1, 'title' => '旧', 'story_outline' => '旧', 'conflict_point' => '旧', 'ending_hook' => '旧']]];
        $edited = ['episodes' => [['episode_number' => 1, 'title' => '新', 'story_outline' => '剧情', 'conflict_point' => '冲突', 'ending_hook' => '结局']]];
        self::assertSame($edited, ShortDramaStoryDraft::merge($base, $edited, 'episodes'));
        $edited['episodes'][0]['episode_number'] = 2;
        $this->expectException(\InvalidArgumentException::class);
        ShortDramaStoryDraft::merge($base, $edited, 'episodes');
    }

    public function testHttpStringNumbersPreserveIdentityButInvalidNumbersAreRejected(): void
    {
        $base = ['episodes' => [['episode_number' => 1, 'title' => '旧'], ['episode_number' => 2, 'title' => '不变']]];
        $input = ['episodes' => [['episode_number' => '1', 'title' => '新'], ['episode_number' => '2']]];
        $saved = ShortDramaStoryDraft::merge($base, $input, 'episodes');
        self::assertSame(1, $saved['episodes'][0]['episode_number']);
        self::assertSame('新', $saved['episodes'][0]['title']);
        self::assertSame($base['episodes'][1], $saved['episodes'][1]);
        foreach ([null, true, 1.0, '1.0', '1abc', '01', '1e0', 0, 501, '999999999999', '2'] as $bad) {
            $input['episodes'][0]['episode_number'] = $bad;
            try { ShortDramaStoryDraft::merge($base, $input, 'episodes'); self::fail('Invalid identity accepted'); }
            catch (\InvalidArgumentException $error) { self::assertSame('大纲集号不一致', $error->getMessage()); }
        }
    }
}
