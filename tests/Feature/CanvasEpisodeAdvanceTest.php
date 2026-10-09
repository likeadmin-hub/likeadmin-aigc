<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaContinuity;
use app\common\service\app\aigc_short_drama\ShortDramaStoryDraft;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasPages;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProduction;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeries;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationAssetReuse;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeriesVisuals;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class CanvasEpisodeAdvanceTest extends TestCase
{
    public function testDurableScriptAdvancesWithoutVideoAndLostResponseReusesTheOwnedPage(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS')!=='1') self::markTestSkipped('Transactional DB fixture');
        (new \think\App())->initialize();Db::startTrans();
        try {
            $tenant=2000000760;$user=7;$scope=['tenant_id'=>$tenant,'user_id'=>$user];$task='advance_'.bin2hex(random_bytes(8));
            Db::name('aigc_short_drama_config')->insert(['tenant_id'=>$tenant,'status'=>1,'config_json'=>'{"canvas_agent":{"enabled":true}}']);
            $project=Db::name('aigc_short_drama_project')->insertGetId($scope+['title'=>'Fixture','last_task_id'=>$task]);
            $plan=ShortDramaStoryDraft::effective([],['title'=>'取信','story_outline'=>'甲找到信',
                'subjects'=>[['id'=>'a','name'=>'甲','description'=>'黑外套']], 'locations'=>[['id'=>'room','name'=>'屋内']],
                'script_lines'=>['甲：终于找到了。'], 'storyboard'=>[['shot_id'=>'s1','scene_ref_id'=>'room','subject_ref_ids'=>['a'],'visual_description'=>'甲取信','dialogue'=>'甲：终于找到了。']]]);
            $plan['_continuity']=['version'=>1,'episode_number'=>1,'digest'=>ShortDramaContinuity::fingerprint($plan),'summary'=>'甲找到信'];
            $script=Db::name('aigc_short_drama_script_task')->insertGetId($scope+['project_id'=>$project,'task_id'=>$task,'status'=>'success','request_json'=>'{}','result_json'=>json_encode($plan)]);
            $nodes=[['id'=>'1','type'=>'video','metadata'=>['status'=>'failed','video_url'=>'','asset_id'=>0]]];
            $canvas=Db::name('aigc_short_drama_canvas')->insertGetId($scope+['title'=>'Fixture','nodes_json'=>json_encode($nodes),'edges_json'=>'[]','viewport_json'=>'{}']);
            $thread=ConversationStore::create($tenant,$user,$canvas,'fixture:'.$task,'创作');
            $state=['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,'version'=>ConversationSeries::VERSION,'series_core'=>true,'stage_skill_versions'=>[],'stages'=>[]],
                'series'=>['episode_number'=>1,'tasks'=>['episode_1'=>['task_id'=>$task,'project_id'=>$project]],'completed_episodes'=>[]],
                'stage_state'=>['key'=>'storyboard','status'=>'awaiting_plan_confirmation'],'slot_values'=>['episode_count'=>'3集'],
                'creative_settings'=>[],'artifact_memory'=>[],'image_plan'=>[],'stage_plan'=>[],'plan_hash'=>'','plan_confirmation'=>['status'=>'pending'],'state_revision'=>4];
            Db::name(ConversationStore::PREFIX.'thread')->where('id',$thread['id'])->update(['settings_json'=>json_encode(['workflow_state'=>$state])]);
            $billCount=Db::name('ai_consumption_log')->where($scope)->count();
            foreach ([['status'=>'running'],['status'=>'failed'],['status'=>'canceled']] as $change) {
                Db::name('aigc_short_drama_script_task')->where('id',$script)->update($change);
                $this->reject(static fn()=>ConversationWorkflow::answer($tenant,$user,$canvas,$thread['id'],4,'next_episode','continue'),'WORKFLOW_PREVIOUS_EPISODE_INCOMPLETE');
                self::assertSame(0,Db::name(ShortDramaCanvasPages::TABLE)->where($scope)->count());
            }
            Db::name('aigc_short_drama_script_task')->where('id',$script)->update(['status'=>'success']);
            $bad=$plan;$bad['script_lines'][]='未经复核的改动';
            Db::name('aigc_short_drama_script_task')->where('id',$script)->update(['result_json'=>json_encode($bad)]);
            $this->reject(static fn()=>ConversationProduction::assertEpisodeReady($tenant,$user,$state),'WORKFLOW_CONTINUITY_MISSING');
            Db::name('aigc_short_drama_script_task')->where('id',$script)->update(['result_json'=>json_encode($plan)]);
            Db::name('aigc_short_drama_project')->where('id',$project)->update(['last_task_id'=>'new-version']);
            $this->reject(static fn()=>ConversationProduction::assertEpisodeReady($tenant,$user,$state),'WORKFLOW_EPISODE_VERSION_CHANGED');
            Db::name('aigc_short_drama_project')->where('id',$project)->update(['last_task_id'=>$task]);
            $foreign=$state;$foreign['series']['tasks']['episode_1']['project_id']=$project+1;
            $this->reject(static fn()=>ConversationProduction::assertEpisodeReady($tenant,$user,$foreign),'WORKFLOW_EPISODE_VERSION_CHANGED');
            $this->reject(static fn()=>ConversationProduction::assertEpisodeReady($tenant+1,$user,$state),'WORKFLOW_PRODUCTION_NOT_FOUND');
            $this->reject(static fn()=>ConversationProduction::assertEpisodeReady($tenant,$user+1,$state),'WORKFLOW_PRODUCTION_NOT_FOUND');
            $this->reject(static fn()=>ConversationWorkflow::answer($tenant,$user,$canvas,$thread['id'],3,'next_episode','continue'),'WORKFLOW_VERSION_CONFLICT');
            $view=ConversationWorkflow::read($tenant,$user,$canvas,$thread['id']);
            self::assertSame(2,$view['workflow']['series']['next_episode']['episode_number']);
            self::assertSame('confirmation',$view['card']['type'],'Keep the current image confirmation beside the advance action');
            $first=ConversationWorkflow::answer($tenant,$user,$canvas,$thread['id'],4,'next_episode','continue');
            $second=ConversationWorkflow::answer($tenant,$user,$canvas,$thread['id'],4,'next_episode','continue');
            self::assertSame($first['next_page_id'],$second['next_page_id']);self::assertSame($canvas,$first['canvas_project_id']);
            self::assertSame(2,Db::name(ShortDramaCanvasPages::TABLE)->where($scope)->count());
            self::assertSame(json_encode($nodes),Db::name('aigc_short_drama_canvas')->where('id',$canvas)->value('nodes_json'));
            $nextThread=Db::name(ConversationStore::PREFIX.'thread')->where($scope+['canvas_id'=>$first['next_page_id']])->find();
            $next=json_decode($nextThread['settings_json'],true)['workflow_state'];
            self::assertSame(2,$next['series']['episode_number']);self::assertSame([1],$next['series']['script_ready_episodes']);
            self::assertSame([$canvas,$first['next_page_id']],$next['series']['page_ids']);
            self::assertSame([],$next['series']['completed_episodes']);self::assertSame('episode',$next['stage_state']['key']);
            // First episode assets can finish after the user enters episode two.
            $proposal=['artifact'=>'subject','key'=>ConversationSeriesVisuals::key('subject','a'),'prompt'=>'黑外套'];
            $settings=['image_model'=>['id'=>'fixture-image']];
            $signature=ConversationAssetReuse::signatures([$proposal],$next,$settings)[0];
            $asset=Db::name('aigc_short_drama_asset')->insertGetId($scope+['canvas_id'=>$canvas,'asset_type'=>'subject_image','uri'=>'uploads/fixture.png','status'=>'ready']);
            $pending=['id'=>'2','type'=>'image','metadata'=>['status'=>'pending','image'=>'','asset_id'=>$asset,'content_revision'=>1,'prompt'=>'黑外套',
                'workflow_asset_signature'=>$signature,'workflow_artifact'=>'subject','workflow_key'=>'assets:'.$proposal['key']]];
            Db::name('aigc_short_drama_canvas')->where('id',$canvas)->update(['nodes_json'=>json_encode(array_merge($nodes,[$pending]))]);
            $document=Db::name('aigc_short_drama_canvas')->where('id',$first['next_page_id'])->find();
            self::assertSame([],ConversationAssetReuse::find($tenant,$user,$document,$next,[$proposal],$settings));
            $pending['metadata']['status']='success';$pending['metadata']['image']='/uploads/fixture.png';
            Db::name('aigc_short_drama_canvas')->where('id',$canvas)->update(['nodes_json'=>json_encode(array_merge($nodes,[$pending]))]);
            $reuse=ConversationAssetReuse::find($tenant,$user,$document,$next,[$proposal],$settings);
            self::assertSame($asset,$reuse[0]['asset_id']);self::assertSame($canvas,$reuse[0]['canvas_id']);
            self::assertSame([],ConversationAssetReuse::find($tenant,$user,$document,$next,[$proposal],['image_model'=>['id'=>'other-model']]));
            $copied=ConversationAssetReuse::copyAsset($tenant,$user,$first['next_page_id'],$reuse[0]);
            self::assertNotSame($asset,$copied);self::assertSame($first['next_page_id'],(int)Db::name('aigc_short_drama_asset')->where('id',$copied)->value('canvas_id'));
            $other=Db::name('aigc_short_drama_canvas')->insertGetId($scope+['title'=>'Unrelated','nodes_json'=>json_encode([$pending]),'edges_json'=>'[]','viewport_json'=>'{}']);
            $isolated=$next;$isolated['series']['page_ids']=[$other];
            self::assertSame([],ConversationAssetReuse::find($tenant,$user,$document,$isolated,[$proposal],$settings),'An unrelated owned project cannot substitute for this series');
            self::assertSame($billCount,Db::name('ai_consumption_log')->where($scope)->count(),'Page navigation creates no paid generation');
            Db::name('aigc_short_drama_canvas')->where('id',$first['next_page_id'])->update(['delete_time'=>time()]);
            $this->reject(static fn()=>ConversationWorkflow::answer($tenant,$user,$canvas,$thread['id'],4,'next_episode','continue'),'WORKFLOW_EPISODE_PAGE_UNAVAILABLE');
        } finally { Db::rollback(); }
    }

    private function reject(callable $action,string $message): void
    {
        try { $action();self::fail('Invalid advance accepted'); }
        catch (\RuntimeException $error) { self::assertSame($message,$error->getMessage()); }
    }
}
