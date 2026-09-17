<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaDialogueContract as Contract;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;

class ShortDramaDialogueContractTest extends TestCase
{
    public function testMissingOrInconsistentSpeakerIsNotSilentlyTreatedAsNarration(): void
    {
        foreach ([[], ['voice_role' => ''], ['voice_role' => '顾言', 'speech_type' => 'narration']] as $fields) {
            $checked = Contract::prepare(['subjects' => [['id' => 's1', 'name' => '顾言']], 'storyboard' => [$fields + ['dialogue' => '全部买下。']]]);
            self::assertCount(1, $checked['issues']);
            self::assertSame(1, Contract::review([], $checked['issues'])['review_report']['blocking_count']);
        }
    }

    public function testExplicitOffscreenSpeakerDoesNotRequireVisualSubject(): void
    {
        $checked = Contract::prepare(['subjects' => [['id' => 's1', 'name' => '陈默']], 'storyboard' => [[
            'voice_role' => '兽医',
            'speech_type' => 'character',
            'dialogue' => '它的器官已经衰竭，时间不多了。',
            'subject_ref_ids' => ['s1'],
        ]]]);

        self::assertSame([], $checked['issues']);
        self::assertSame('兽医', $checked['payload']['storyboard'][0]['voice_role']);
        self::assertSame('character', $checked['payload']['storyboard'][0]['speech_type']);
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

    public function testExplicitDialoguePrefixesAreNormalizedWithoutGuessingFromTheShot(): void
    {
        $checked = Contract::prepare(['subjects' => [
            ['id' => 's1', 'name' => '七公'],
            ['id' => 's2', 'name' => '陈伯'],
        ], 'storyboard' => [
            ['dialogue' => '七公：我有七日时间……'],
            ['dialogue' => '旁白：雪夜的雨没有停。'],
            ['dialogue' => '雨声淹没了巷口。'],
        ]]);

        self::assertCount(1, $checked['issues']);
        self::assertSame('七公', $checked['payload']['storyboard'][0]['voice_role']);
        self::assertSame('character', $checked['payload']['storyboard'][0]['speech_type']);
        self::assertSame('', $checked['payload']['storyboard'][1]['voice_role']);
        self::assertSame('narration', $checked['payload']['storyboard'][1]['speech_type']);
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

    public function testSpeakerRepairOnlyUpdatesTheAllowListedFailedShot(): void
    {
        $plan = ['subjects' => [['id' => 's1', 'name' => '顾言']], 'storyboard' => [
            ['shot_id' => '1', 'dialogue' => '全部买下。', 'visual_description' => '顾言站在会场中央'],
            ['shot_id' => '2', 'dialogue' => '不要离开。', 'visual_description' => '顾言追向门口'],
        ]];
        $checked = Contract::prepare($plan);
        self::assertTrue(Contract::hasOnlySpeakerBlockingIssues(Contract::review([], $checked['issues'])['review_report']));
        $targets = Contract::repairTargets($plan, [array_merge($checked['issues'][0], ['path' => 'storyboard.0.voice_role'])]);
        $repaired = Contract::applySpeakerRepairs($plan, ['dialogue_repairs' => [
            ['shot_id' => '1', 'voice_role' => '顾言', 'speech_type' => 'character'],
            ['shot_id' => '2', 'voice_role' => '陌生人', 'speech_type' => 'character'],
        ]], $targets);
        self::assertSame('顾言', $repaired['storyboard'][0]['voice_role']);
        self::assertSame('character', $repaired['storyboard'][0]['speech_type']);
        self::assertArrayNotHasKey('voice_role', $repaired['storyboard'][1]);
    }

    public function testSpeakerRepairCanPreserveOnlyTheExplicitOffscreenRole(): void
    {
        $plan = ['subjects' => [['id' => 's1', 'name' => '陈默']], 'storyboard' => [
            ['shot_id' => '1', 'dialogue' => '它的器官已经衰竭。', 'voice_role' => '兽医', 'visual_description' => '陈默站在诊室门口'],
        ]];
        $targets = [[
            'shot_id' => '1',
            'declared_voice_role' => '兽医',
        ]];
        $repaired = Contract::applySpeakerRepairs($plan, ['dialogue_repairs' => [
            ['shot_id' => '1', 'voice_role' => '兽医', 'speech_type' => 'character'],
        ]], $targets);

        self::assertSame('兽医', $repaired['storyboard'][0]['voice_role']);
        self::assertSame('character', $repaired['storyboard'][0]['speech_type']);
    }
}
