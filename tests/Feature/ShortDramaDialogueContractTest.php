<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaDialogueContract as Contract;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;

class ShortDramaDialogueContractTest extends TestCase
{
    public function testMissingSpeakerIsNotSilentlyTreatedAsNarration(): void
    {
        foreach ([[], ['voice_role' => ''], ['voice_role' => '不存在的人'], ['voice_role' => '顾言', 'speech_type' => 'narration']] as $fields) {
            $checked = Contract::prepare(['subjects' => [['id' => 's1', 'name' => '顾言']], 'storyboard' => [$fields + ['dialogue' => '全部买下。']]]);
            self::assertCount(1, $checked['issues']);
            self::assertSame(1, Contract::review([], $checked['issues'])['review_report']['blocking_count']);
        }
    }

    public function testExplicitNarrationSilenceAndOffscreenCharacterArePreserved(): void
    {
        $checked = Contract::prepare(['subjects' => [['id' => 's1', 'name' => '顾言']], 'storyboard' => [
            ['voice_role' => '', 'speech_type' => 'narration', 'dialogue' => '这一天改变了一切'],
            ['voice_role' => '', 'speech_type' => 'none', 'dialogue' => ''],
            ['speaker' => 's1', 'dialogue' => '全部买下。', 'subject_ref_ids' => []],
        ]]);
        self::assertSame([], $checked['issues']);
        self::assertSame('', $checked['payload']['storyboard'][0]['voice_role']);
        self::assertSame('顾言', $checked['payload']['storyboard'][2]['voice_role']);
    }

    public function testNestedEpisodeShotsKeepSpeakerThroughNormalization(): void
    {
        $checked = Contract::prepare(['subjects' => [['id' => 's1', 'name' => '顾言']], 'episodes' => [['scenes' => [['shots' => [
            ['shot_id' => '1', 'visual_description' => '顾言走向台前', 'speaker_name' => '顾言', 'dialogue' => '全部买下。'],
        ]]]]]]);
        self::assertSame([], $checked['issues']);
        $shot = $checked['payload']['episodes'][0]['scenes'][0]['shots'][0];
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'normalizeGeneratedStoryboard');
        $method->setAccessible(true);
        self::assertSame('顾言', $method->invoke(null, [$shot])[0]['voice_role']);
    }
}
