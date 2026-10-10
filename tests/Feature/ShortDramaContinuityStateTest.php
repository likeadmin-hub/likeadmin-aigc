<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaContinuityState as State;
use PHPUnit\Framework\TestCase;

class ShortDramaContinuityStateTest extends TestCase
{
    public function testMissingRewordedAndInventedBeforeValuesCannotChangeTheStoredReference(): void
    {
        foreach ([null, 'null（未出现）', '工作台', '不相干地点'] as $before) {
            $input = ['changes'=>[
                ['entity_id'=>'book','field'=>'location','before'=>$before,'after'=>'抽屉'],
                ['entity_id'=>'book','field'=>'location','before'=>$before,'after'=>'桌面'],
                ['entity_id'=>'person','field'=>'mood','before'=>$before,'after'=>'平静']]];
            $prepared = State::prepare($input, ['continuity'=>['state'=>['book:location'=>'loc_shop（工作台上，收音机旁）']]]);
            self::assertSame(['loc_shop（工作台上，收音机旁）','抽屉',null], array_column($prepared['changes'], 'before'));
            self::assertSame(['抽屉','桌面','平静'], array_column($prepared['changes'], 'after'));
            self::assertSame($before, $input['changes'][0]['before']);
        }
    }
    public function testStateProposalIsNotACommitAndPreservesUnsupportedFactForVerification(): void
    {
        $context = ['continuity'=>['state'=>['a:status'=>'死亡']]];
        $proposal = ['changes'=>[['entity_id'=>'a','field'=>'status','after'=>'活着','evidence_refs'=>[['shot_id'=>'s1','field'=>'visual_description']]]]];
        $result = State::prepare($proposal, $context);
        self::assertSame('死亡', $result['changes'][0]['before']);
        self::assertSame('活着', $result['changes'][0]['after']);
        self::assertSame('死亡', $context['continuity']['state']['a:status']);
        self::assertSame($proposal['changes'][0]['evidence_refs'], $result['changes'][0]['evidence_refs']);
    }
}
