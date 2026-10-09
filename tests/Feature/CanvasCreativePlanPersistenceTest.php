<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationCreativePlan as Plan;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationCreativePolicy as Policy;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Exercises the real confirmation transaction without model calls; all fixtures roll back. */
class CanvasCreativePlanPersistenceTest extends TestCase
{
    public function testConfirmationPersistsExactSettingsAndRejectsIncompleteOrStaleCards(): void
    {
        if (getenv('SHORT_DRAMA_DB_TESTS')!=='1') $this->markTestSkipped('Transactional MySQL test');
        (new \think\App())->initialize();
        $tenant=random_int(1500000000,1900000000);$user=$tenant;
        Db::startTrans();
        try {
            Db::name('aigc_short_drama_config')->insert(['tenant_id'=>$tenant,'config_json'=>'{"canvas_agent":{"enabled":true,"workflow":{"enabled":true}}}','status'=>1]);
            $canvas=Canvas::create($tenant,$user,['title'=>'Creative confirmation fixture'])['id'];
            $thread=Store::create($tenant,$user,$canvas,'creative-plan-test')['id'];
            $slots=Workflow::catalog()['slots'];
            foreach($slots as &$slot) if($slot['key']==='aspect_ratio') $slot['options']=['9:16','16:9'];
            unset($slot);
            $state=Plan::draft(['workflow_snapshot'=>['key'=>Workflow::KEY,'version'=>Workflow::VERSION,'series_core'=>true,
                'creative_policy_version'=>Policy::VERSION,'slot_schema'=>$slots,'stage_skill_versions'=>[]],
                'slot_values'=>[],'creative_settings'=>[],'creative_brief'=>'程序员大战孙悟空',
                'artifact_memory'=>[],'plan_hash'=>'','image_plan'=>[],'stage_plan'=>[],
                'plan_confirmation'=>['status'=>'not_required'],'state_revision'=>1],['candidates'=>[],'questions'=>[]]);
            Db::name(Store::PREFIX.'thread')->where('id',$thread)->update(['settings_json'=>json_encode(['workflow_state'=>$state])]);
            $view=Workflow::read($tenant,$user,$canvas,$thread);
            self::assertSame('intake_review',$view['card']['type']);
            self::assertTrue($view['card']['full_plan']);
            self::assertCount(5,$view['card']['candidates']);
            try { Workflow::answer($tenant,$user,$canvas,$thread,1,'intake_review','{"episode_count":"3集"}');self::fail('Partial plan accepted'); }
            catch(\RuntimeException $e) { self::assertSame('INVALID_WORKFLOW_ANSWER',$e->getMessage()); }
            $values=['story_direction'=>'程序员穿越西游，与孙悟空合作','episode_count'=>'3集','episode_duration'=>'120秒','visual_style'=>'国风水墨','aspect_ratio'=>'16:9'];
            $view=Workflow::answer($tenant,$user,$canvas,$thread,1,'intake_review',json_encode($values,JSON_UNESCAPED_UNICODE));
            self::assertSame('story',$view['workflow']['stage_state']['key']);
            self::assertSame($values,$view['workflow']['slot_values']);
            self::assertSame('16:9',$view['workflow']['creative_settings']['aspect_ratio']);
            self::assertSame('国风水墨',$view['workflow']['creative_settings']['style_name']);
            $saved=json_decode(Db::name(Store::PREFIX.'thread')->where('id',$thread)->value('settings_json'),true)['workflow_state'];
            Plan::assertConfirmed($saved);
            self::assertSame('程序员大战孙悟空',$saved['creative_brief']);
            self::assertSame(0,Db::name('aigc_short_drama_script_task')->where('tenant_id',$tenant)->count());
            try { Workflow::answer($tenant,$user,$canvas,$thread,1,'intake_review',json_encode($values));self::fail('Stale card accepted'); }
            catch(\RuntimeException $e) { self::assertSame('WORKFLOW_VERSION_CONFLICT',$e->getMessage()); }
        } finally { Db::rollback(); }
    }
}
