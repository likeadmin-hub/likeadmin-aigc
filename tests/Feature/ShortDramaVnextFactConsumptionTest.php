<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\FactConsumption;
use app\common\service\app\aigc_short_drama\vnext\NarrativeLedger;
use app\common\service\app\aigc_short_drama\vnext\SeriesContext;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaVnextFactConsumptionTest extends TestCase
{
    private function fixture(): array
    {
        $context=['revision_id'=>'fact_r1','episode_id'=>'e1','entity_scope'=>'consumption','entities'=>[
            ['ref'=>'c1','entity_id'=>'hero','kind'=>'character','name'=>'陈默'],['ref'=>'c2','entity_id'=>'other','kind'=>'character','name'=>'林青'],
            ['ref'=>'l1','entity_id'=>'room','kind'=>'location','name'=>'书房']]];
        $doc=ContentDocument::normalize(['title'=>'状态','scenes'=>[['location'=>'l1','units'=>[['type'=>'action','characters'=>['c1','c2'],'text'=>'陈默受伤，林青穿着黑衣。😀']]]]],$context);
        $changes=[['kind'=>'world','subject_id'=>'hero','predicate'=>'injury','value'=>'受伤','observer_id'=>null,'certainty'=>'confirmed','evidence'=>[['unit'=>'u1','start'=>0,'end'=>4,'quote'=>'陈默受伤']]],
            ['kind'=>'world','subject_id'=>'other','predicate'=>'clothing','value'=>'黑衣','observer_id'=>null,'certainty'=>'confirmed','evidence'=>[['unit'=>'u1','start'=>5,'end'=>12,'quote'=>'林青穿着黑衣。']]]];
        $ledger=NarrativeLedger::evaluate($doc,$changes);return [$doc,$ledger,$changes];
    }
    public function testConsumedFactsPinFullValuesObserversAndEvidenceWhileUnprovidedFactsMayDiffer(): void
    {
        [$doc,$ledger,$changes]=$this->fixture();$index=FactConsumption::index($doc,$ledger);$receipt=FactConsumption::capture($index,['hero','room']);
        self::assertCount(1,$receipt['facts']);self::assertTrue(FactConsumption::matches($receipt,$index));
        $next=ContentDocument::rebind($doc,[],'fact_r2');$changes[1]['value']='黑衣仍未换';$newLedger=NarrativeLedger::evaluate($next,[$changes[1]],$ledger);
        self::assertTrue(FactConsumption::matches($receipt,FactConsumption::index($next,$newLedger)));
        $newLedger=NarrativeLedger::evaluate($next,[$changes[0]],$ledger);
        self::assertFalse(FactConsumption::matches($receipt,FactConsumption::index($next,$newLedger)),'Same claim with new provenance is still a changed consumed fact');
        $next['entities'][1]['description']='换了人物外观';$next=ContentDocument::rebind($next,[],'fact_r3');$newLedger=NarrativeLedger::evaluate($next,[],$ledger);
        self::assertFalse(FactConsumption::matches($receipt,FactConsumption::index($next,$newLedger)),'Every registered identity remains a dependency');
    }
    public function testWorldAndObserverFactsCannotDisappearFromSelection(): void
    {
        [$doc,$ledger]=$this->fixture();$fact=reset($ledger['state']);$world=$fact;$world['fact_id']='world_fact';$world['subject_id']='world';
        $belief=$fact;$belief['fact_id']='belief_fact';$belief['kind']='belief';$belief['subject_id']='other';$belief['observer_id']='hero';
        $ledger['state']['world']=$world;$ledger['state']['belief']=$belief;$ledger['content_hash']=NarrativeLedger::digest($ledger);
        $receipt=FactConsumption::capture(FactConsumption::index($doc,$ledger),['hero']);
        self::assertCount(3,$receipt['facts']);self::assertArrayHasKey('world_fact',$receipt['facts']);self::assertArrayHasKey('belief_fact',$receipt['facts']);
    }
    public function testMissingLegacyProofRemainsConservativeAndMalformedProofCannotBypassEqualVersions(): void
    {
        self::assertFalse(FactConsumption::applicable(['dependencies'=>['1'=>['revision_id'=>'old']]],['1'=>['revision_id'=>'new']],FactConsumption::emptyIndex()));
        $this->expectExceptionCode(409);FactConsumption::applicable(['dependencies'=>[],'fact_consumption'=>['schema_version'=>2]],[],FactConsumption::emptyIndex());
    }
    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDatabasePublicationAcceptsUnchangedConsumptionAndBlocksChangedIdentityWithoutRewritingEpisode(): void
    {
        if(getenv('SHORT_DRAMA_STORY_DB_TESTS')!=='1')self::markTestSkipped('Opt in to local DB fixtures');
        (new \think\App())->initialize();$scope=['tenant_id'=>2000000820,'user_id'=>7];$run='consumption_'.bin2hex(random_bytes(8));Db::startTrans();
        try {
            $project=Db::name('aigc_short_drama_project')->insertGetId($scope+['title'=>$run,'multi_episode'=>1,'episode_count'=>3,'last_task_id'=>$run]);
            $story=['title'=>'调查','type_judgement'=>'悬疑','core_theme'=>'真相','story_outline'=>'陈默在书房调查。','subjects'=>[['id'=>'hero','name'=>'陈默','description'=>'调查员']], 'locations'=>[['id'=>'room','name'=>'书房','description'=>'书房']]];
            $plan=$story+['episodes'=>array_map(static fn($n)=>['episode_number'=>$n,'title'=>'调查'.$n,'story_outline'=>'陈默继续调查。','conflict_point'=>'线索未明','ending_hook'=>'继续查找'],[1,2,3])];
            $policy=['enabled'=>true,'series_enabled'=>true,'fact_dependencies_enabled'=>true,'tenant_ids'=>[$scope['tenant_id']],'models'=>['model'=>['lease_seconds'=>150,'stage_timeout_seconds'=>100,'deadline_seconds'=>1800]]];
            $request=['prompt'=>'陈默调查','confirmed_story_snapshot'=>$story,'confirmed_outline_snapshot'=>$plan,'model_selections'=>['script_plan'=>['id'=>'model']]];
            $snapshot=SeriesContext::capture($scope['tenant_id'],$scope['user_id'],$project,$run,$request,$plan,$policy);self::assertSame(1,$snapshot['fact_dependency_version']);
            $episodes=[];$results=[];$current=[];$contexts=[];
            foreach([1,2,3] as $n) {
                $episode=$scope+['project_id'=>$project,'outline_task_id'=>$run,'episode_number'=>$n,'outline_json'=>json_encode($plan['episodes'][$n-1])];
                $context=SeriesContext::prepare($snapshot,$episode,$plan)['context'];$context['revision_id']=$run.'_r'.$n;$contexts[$n]=$context;
                $hero=$context['entities'][0];$room=$context['entities'][1];
                $doc=ContentDocument::normalize(['title'=>'调查'.$n,'scenes'=>[['location'=>$room['ref'],'units'=>[
                    ['type'=>'action','characters'=>[$hero['ref']],'text'=>'陈默受伤。'],['type'=>'dialogue','speaker'=>$hero['ref'],'text'=>'第'.$n.'句对白😀。']]]]],$context);
                $changes=$n===1 ? [['kind'=>'world','subject_id'=>$hero['entity_id'],'predicate'=>'injury','value'=>'受伤','observer_id'=>null,'certainty'=>'confirmed',
                    'evidence'=>[['unit'=>'u1','start'=>0,'end'=>4,'quote'=>'陈默受伤']]]] : [];
                $ledger=NarrativeLedger::evaluate($doc,$changes,$context['previous_narrative']);$result=['canonical_document'=>$doc,'narrative_ledger'=>$ledger];$results[$n]=$result;
                $child=Db::name('aigc_short_drama_project')->insertGetId($scope+['title'=>$run.'_'.$n]);$meta=SeriesContext::metadata($result);
                $version=Db::name('aigc_short_drama_plan_version')->insertGetId($scope+['project_id'=>$child,'task_id'=>$run.'_'.$n,'plan_json'=>json_encode($result),'continuity_json'=>json_encode($meta)]);
                Db::name('aigc_short_drama_project')->where($scope+['id'=>$child])->update(['current_version_id'=>$version]);
                $episode+=['production_project_id'=>$child,'status'=>'success','completed_once'=>1];Db::name('aigc_short_drama_episode_task')->insert($episode);$episodes[]=$episode;$current[$child]=$meta;
            }
            $doc=$results[2]['canonical_document'];$unit=$doc['scenes'][0]['units'][1];
            $changed=ContentDocument::patch($doc,[['unit_id'=>$unit['unit_id'],'before_hash'=>$unit['text_hash'],'text'=>$unit['text'].'无事实变化的完整对白。']],$run.'_revised2');
            $changedLedger=NarrativeLedger::evaluate($changed,[],$results[1]['narrative_ledger']);$changedResult=['canonical_document'=>$changed,'narrative_ledger'=>$changedLedger];
            $child=$episodes[1]['production_project_id'];$meta=SeriesContext::metadata($changedResult);
            $version=Db::name('aigc_short_drama_plan_version')->insertGetId($scope+['project_id'=>$child,'task_id'=>$run.'_revised2','plan_json'=>json_encode($changedResult),'continuity_json'=>json_encode($meta)]);
            Db::name('aigc_short_drama_project')->where($scope+['id'=>$child])->update(['current_version_id'=>$version]);$current[$child]=$meta;
            self::assertFalse(SeriesContext::dependencyStatus($episodes,$current)[2]['needs_review']);
            SeriesContext::assertDependencies($contexts[3]);self::assertCount(3,SeriesContext::publishedBefore($scope['tenant_id'],$scope['user_id'],$project,4)['dependencies']);
            self::assertSame($results[3]['canonical_document']['content_hash'],$current[$episodes[2]['production_project_id']]['canonical']['document_hash']);
            $changed['entities'][0]['description']='受伤后的外观';$changed=ContentDocument::rebind($changed,$changed['dependency_versions'],$run.'_appearance2');
            $changedResult=['canonical_document'=>$changed,'narrative_ledger'=>NarrativeLedger::evaluate($changed,[],$results[1]['narrative_ledger'])];$meta=SeriesContext::metadata($changedResult);
            $version=Db::name('aigc_short_drama_plan_version')->insertGetId($scope+['project_id'=>$child,'task_id'=>$run.'_appearance2','plan_json'=>json_encode($changedResult),'continuity_json'=>json_encode($meta)]);
            Db::name('aigc_short_drama_project')->where($scope+['id'=>$child])->update(['current_version_id'=>$version]);$current[$child]=$meta;
            self::assertTrue(SeriesContext::dependencyStatus($episodes,$current)[2]['needs_review']);
            $this->expectExceptionCode(409);SeriesContext::assertDependencies($contexts[3]);
        }finally{Db::rollback();}
    }
}
