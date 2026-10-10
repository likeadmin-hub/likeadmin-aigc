<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\{ConversationStore as Store,ConversationSeries as Series,ConversationScriptRecovery as Recovery,GraphService};
class CanvasScriptRecoveryTest extends TestCase
{
    public function testSavedReceiptRepairsAtomicallyAndPreservesEditsAndScope(): void {
        if(getenv('SHORT_DRAMA_DB_TESTS')!=='1') $this->markTestSkipped('Transactional MySQL test');
        (new \think\App())->initialize();$tenant=random_int(1500000000,1900000000);$user=$tenant;$json=static fn($v)=>json_encode($v,JSON_UNESCAPED_UNICODE);
        Db::startTrans();try {
            Db::name('aigc_short_drama_config')->insert(['tenant_id'=>$tenant,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);
            $canvas=Canvas::create($tenant,$user,['title'=>'Recovery fixture'])['id'];$thread=Store::create($tenant,$user,$canvas,'repair-test')['id'];
            $plan=['title'=>'测试','subjects'=>[],'locations'=>[],'storyboard'=>[],
                'character_profiles'=>[['id'=>'a','name'=>'角色']],
                'scene_script'=>[['scene_id'=>'office','dialogue'=>[['character'=>'角色','line'=>'正文对白']]]],
                'storyboard_script'=>[['shot_id'=>'s','dialogue'=>'原始对白','recommended_duration_seconds'=>7]]];
            $old=Series::text($plan,'episode',1,false);$task='repair-'.$tenant;
            Db::name('aigc_short_drama_script_task')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task,'status'=>'success','result_json'=>$json($plan)]);
            $state=['state_revision'=>1,'series'=>['episode_number'=>1,'tasks'=>['episode_1'=>['task_id'=>$task,'run_id'=>1]]],
                'artifact_memory'=>[['stage'=>'episode','key'=>'episode_1_1','node_id'=>'1','content'=>$old]]];
            Db::name(Store::PREFIX.'thread')->where('id',$thread)->update(['settings_json'=>$json(['workflow_state'=>$state])]);
            $node=['id'=>'1','metadata'=>['workflow_key'=>'episode:episode_1_1','prompt'=>$old,'content'=>$old]];
            Db::name(GraphService::TABLE)->where('id',$canvas)->update(['nodes_json'=>$json([$node])]);
            $mid=Db::name(Store::PREFIX.'message')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas,'thread_id'=>$thread,'run_id'=>1,'sequence'=>2,'role'=>'assistant','content_json'=>$json(['text'=>'原回复','stage_artifacts'=>[['title'=>'标题','content'=>$old]]]),'attachments_json'=>'[]','create_time'=>1]);
            try {Recovery::recover($tenant+1,$user,$canvas,$thread);self::fail('Cross tenant access accepted');} catch(\RuntimeException $e){self::assertSame('CONVERSATION_NOT_FOUND',$e->getMessage());}
            $node['metadata']['content']='用户修改';Db::name(GraphService::TABLE)->where('id',$canvas)->update(['nodes_json'=>$json([$node])]);
            try {Recovery::recover($tenant,$user,$canvas,$thread);self::fail('User edit overwritten');} catch(\RuntimeException $e){self::assertSame('WORKFLOW_PLAN_SOURCE_CHANGED',$e->getMessage());}
            self::assertSame($old,json_decode(Db::name(Store::PREFIX.'message')->where('id',$mid)->value('content_json'),true)['stage_artifacts'][0]['content']);
            $node['metadata']['content']=$old;Db::name(GraphService::TABLE)->where('id',$canvas)->update(['nodes_json'=>$json([$node])]);
            self::assertTrue(Recovery::recover($tenant,$user,$canvas,$thread)['changed']);
            self::assertFalse(Recovery::recover($tenant,$user,$canvas,$thread)['changed']);
            $message=Db::name(Store::PREFIX.'message')->where('id',$mid)->find();self::assertSame(2,(int)$message['sequence']);self::assertSame(1,(int)$message['create_time']);
            self::assertStringContainsString('正文对白',$message['content_json']);self::assertStringNotContainsString('原始对白',$message['content_json']);self::assertSame(0,Db::name(Store::PREFIX.'run')->where('thread_id',$thread)->count());
        }finally{Db::rollback();}
    }
}
