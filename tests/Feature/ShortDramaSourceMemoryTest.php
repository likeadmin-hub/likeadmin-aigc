<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\{ShortDramaSourceMemory as Source, ShortDramaContinuity as Continuity, ShortDramaNarrativeMemory as Memory, ShortDramaPlanningBudget as Budget};
use PHPUnit\Framework\TestCase;

class ShortDramaSourceMemoryTest extends TestCase
{
    private function plan(int $n): array
    {
        return ['title'=>'第'.$n.'集','story_outline'=>'本集普通调查'.$n,'storyboard'=>[
            ['shot_id'=>'last','visual_description'=>'主角离开旧宅','dialogue'=>$n===20?'紫铜钥匙藏在灯塔密室门前，尚未交给任何人。':'明天继续寻找证据。']]];
    }

    /** @dataProvider scales */
    public function testSkippedAuditRemainsSearchableWithoutBecomingVerified(int $total): void
    {
        $history=[];
        for($i=1;$i<=$total;$i++) {
            $p=$this->plan($i);$p['_continuity']=['episode_number'=>$i,'summary'=>'普通调查'.$i,'digest'=>Continuity::fingerprint($p),
                'state'=>[],'open_hooks'=>[],'audit_status'=>'skipped'];
            $history[]=Source::attach($p,['_input_contract_version'=>11,'series_context'=>['current_episode'=>[]]])['_continuity'];
        }
        $before=$history;$context=Continuity::context($history,true,true);
        self::assertSame([],$context['history']); self::assertSame([],$context['state']);self::assertSame([],$context['open_hooks']);
        $messages=['system_prompt'=>'生成','content'=>json_encode(['current_episode'=>['story_outline'=>'紫铜钥匙打开灯塔密室'], 'continuity'=>$context],JSON_UNESCAPED_UNICODE)];
        $budget=Budget::fitMessages($messages,[],'script',4096,true);
        self::assertLessThan(15000,$budget['input_estimate']);
        $view=json_decode($messages['content'],true)['continuity'];
        self::assertNotEmpty($view['source_history']);
        if($total>=20) self::assertStringContainsString('紫铜钥匙',json_encode($view['source_history'],JSON_UNESCAPED_UNICODE));
        foreach($view['source_history']as$record)self::assertSame('source_text_not_audited',$record['verification']);
        self::assertSame($before,$history);
        self::assertArrayNotHasKey('source_history',Continuity::context($history,true));
    }
    public static function scales():array{return [[10],[50],[100],[500]];}

    public function testRepeatedMemoryProjectionPreservesAddressedExcerpts(): void
    {
        $plan=$this->plan(20);
        $source=['source_history'=>[20=>['audit_status'=>'skipped','source_memory'=>Source::capture($plan)]]];
        $first=Memory::continuity($source,'紫铜钥匙',10000);
        $second=Memory::continuity($first,'紫铜钥匙',10000);
        self::assertSame($first['source_history'],$second['source_history']);
        self::assertStringContainsString('紫铜钥匙',json_encode($second,JSON_UNESCAPED_UNICODE));
    }

    public function testLegacyRequestAndReceiptsRemainUntouched(): void
    {
        $p=$this->plan(20);$p['_continuity']=['state'=>['p:location'=>'旧宅'],'open_hooks'=>['key'=>'待找到钥匙'],'audit_status'=>'skipped'];
        self::assertSame($p,Source::attach($p,['_input_contract_version'=>10,'series_context'=>['a'=>1]]));
        $new=Source::attach($p,['_input_contract_version'=>11,'series_context'=>['a'=>1]]);
        self::assertSame($p['_continuity'],array_diff_key($new['_continuity'],['source_memory'=>true]));
        self::assertSame($p['storyboard'],$new['storyboard']);
    }

    public function testNewDependencyCanBeReconstructedAndDetectsEditedSource(): void
    {
        $p=$this->plan(20);$previous=['version'=>1,'episode_number'=>1,'summary'=>'摘要','digest'=>Continuity::fingerprint($p),
            'state'=>[],'open_hooks'=>[],'audit_status'=>'skipped','source_memory'=>Source::capture($p)];
        $context=Continuity::context([$previous],true,true);
        $next=['version'=>1,'episode_number'=>2,'digest'=>'next','previous_digest'=>$previous['digest'],'audit_status'=>'verified',
            'memory_dependency'=>['version'=>1,'history_available'=>true,'source_history_available'=>true,'query'=>'钥匙',
                'fingerprint'=>Memory::dependency($context,'钥匙')]];
        $rows=[['id'=>1,'production_project_id'=>1,'status'=>'success','continuity_json'=>json_encode($previous)],
            ['id'=>2,'production_project_id'=>2,'status'=>'success','continuity_json'=>json_encode($next)]];
        self::assertFalse(Continuity::dependencyStatus($rows,[])[1]['needs_review']);
        $previous['source_memory']['records']['ending_0_dialogue']['text']='钥匙已交给另一人';
        self::assertTrue(Continuity::dependencyStatus($rows,[1=>['ledger'=>$previous,'narrative_digest'=>$previous['digest']]])[1]['needs_review']);
    }

    public function testOutlineMemoryHasNoFutureEpisodesAndFitsBudget(): void
    {
        $episodes=[];for($i=1;$i<=500;$i++)$episodes[]=['episode_number'=>$i,'story_outline'=>$i===20?'紫铜钥匙藏在灯塔':'普通调查'.$i,'ending_hook'=>'继续调查'];
        $memory=Source::outline($episodes,[],100,'紫铜钥匙');
        self::assertStringContainsString('紫铜钥匙',json_encode($memory,JSON_UNESCAPED_UNICODE));
        foreach($memory['episodes']as$episode)self::assertLessThan(100,$episode['episode_number']);
        self::assertLessThan(4500,mb_strlen(json_encode($memory,JSON_UNESCAPED_UNICODE)));
    }
}
