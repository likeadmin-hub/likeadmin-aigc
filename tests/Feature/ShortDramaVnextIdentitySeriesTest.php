<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\NarrativeLedger;
use app\common\service\app\aigc_short_drama\vnext\SeriesContext;
use app\common\service\app\aigc_short_drama\vnext\StructuralRevision;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaVnextIdentitySeriesTest extends TestCase
{
    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDatabasePublishedIdentityCorrectionSurvivesTwoSubsequentEpisodePreparations(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to local DB fixtures');
        (new \think\App())->initialize();$scope=['tenant_id'=>2000000814,'user_id'=>7];$task='identity_series_'.bin2hex(random_bytes(8));
        Db::startTrans();
        try {
            $project=Db::name('aigc_short_drama_project')->insertGetId($scope+['title'=>'修订身份','multi_episode'=>1,'episode_count'=>3,'last_task_id'=>$task]);
            $story=['title'=>'修订身份','type_judgement'=>'悬疑','core_theme'=>'真相','story_outline'=>'陈默在书房调查。',
                'subjects'=>[['id'=>'hero','name'=>'陈默','description'=>'调查员','gender'=>'男','age'=>40]],'locations'=>[['id'=>'room','name'=>'书房','description'=>'书房']]];
            $plan=$story+['episodes'=>array_map(static fn($number)=>['episode_number'=>$number,'title'=>'调查'.$number,'story_outline'=>'陈默在书房调查线索。',
                'conflict_point'=>'线索未明','ending_hook'=>'继续调查'],[1,2,3])];
            $request=['prompt'=>'陈默调查线索。','confirmed_story_snapshot'=>$story,'confirmed_outline_snapshot'=>$plan,'model_selections'=>['script_plan'=>['id'=>'frozen_model']]];
            $snapshot=SeriesContext::capture($scope['tenant_id'],$scope['user_id'],$project,$task,$request,$plan,
                ['enabled'=>true,'series_enabled'=>true,'tenant_ids'=>[$scope['tenant_id']], 'models'=>['frozen_model'=>['stage_timeout_seconds'=>100,'lease_seconds'=>150,'deadline_seconds'=>1800]]]);
            self::assertNotNull($snapshot);$receipt=null;
            foreach([1,2,3] as $number) {
                $episode=$scope+['project_id'=>$project,'outline_task_id'=>$task,'episode_number'=>$number,'outline_json'=>json_encode($plan['episodes'][$number-1])];
                $context=SeriesContext::prepare($snapshot,$episode,$plan)['context'];$context['revision_id']='identity_episode_'.$number;
                $hero=array_values(array_filter($context['entities'],static fn($entity)=>$entity['kind']==='character'))[0];
                $room=array_values(array_filter($context['entities'],static fn($entity)=>$entity['kind']==='location'))[0];
                if($number>1) {self::assertSame('女',$hero['identity_facts']['gender']);self::assertSame(28,$hero['identity_facts']['age']);self::assertSame($receipt,$context['identity_revision_receipts'][$hero['entity_id']]);}
                $doc=ContentDocument::normalize(['title'=>'调查'.$number,'scenes'=>[['location'=>$room['ref'],'units'=>[['type'=>'action','characters'=>[$hero['ref']],'text'=>'陈默继续调查，原文保留😀。']]]]],$context);
                if($number===1) {
                    $revisionTask=$task.'_revision';$input=ApplicationWorkflow::revisionInput(['context'=>$context],['document'=>$doc],[],$doc['revision_id'],$revisionTask,
                        $scope+['created_at'=>time(),'parent_task_id'=>$task,'task_id'=>$revisionTask],['entities'=>[['entity_id'=>$hero['entity_id'],
                            'before_hash'=>StructuralRevision::targets($doc)['entities'][0]['before_hash'],'identity_facts'=>['gender'=>'女','age'=>28]]]]);
                    $doc=$input['context']['canonical_input'];$receipt=$doc['identity_revision_receipts'][$hero['entity_id']];
                }
                if($number===3) {self::assertSame($receipt,$doc['identity_revision_receipts'][$hero['entity_id']]);break;}
                $result=['canonical_document'=>$doc,'narrative_ledger'=>NarrativeLedger::evaluate($doc,[],$context['previous_narrative'])];
                $child=Db::name('aigc_short_drama_project')->insertGetId($scope+['title'=>'本集'.$number]);
                $version=Db::name('aigc_short_drama_plan_version')->insertGetId($scope+['project_id'=>$child,'task_id'=>$task.'_episode_'.$number,
                    'plan_json'=>json_encode($result),'continuity_json'=>json_encode(SeriesContext::metadata($result))]);
                Db::name('aigc_short_drama_project')->where($scope+['id'=>$child])->update(['current_version_id'=>$version]);
                Db::name('aigc_short_drama_episode_task')->insert($episode+['production_project_id'=>$child,'status'=>'success','completed_once'=>1]);
            }
            self::assertCount(2,SeriesContext::publishedBefore($scope['tenant_id'],$scope['user_id'],$project,3)['dependencies']);
        } finally {Db::rollback();}
    }
}
