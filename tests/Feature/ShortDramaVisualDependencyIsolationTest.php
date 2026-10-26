<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaVisualDependencies as Dependencies;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaVisualDependencyIsolationTest extends TestCase
{
    private bool $transaction=false;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Transactional local DB test');
        (new \think\App())->initialize(); Db::startTrans(); $this->transaction=true;
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }
    public function testInheritanceIsScopedAndNeverRewritesOldAssetsOrTasks(): void
    {
        $scope=['tenant_id'=>2000000719,'user_id'=>7,'project_id'=>2000000719];
        $task='test_visual_' . bin2hex(random_bytes(8));
        $baseline=Dependencies::capture(['subjects'=>[['id'=>'p','visual_prompt'=>'蓝衣']]],['subject_ref_ids'=>['p']]);
        $json=json_encode(['visual_dependencies'=>$baseline]);
        Db::name('aigc_short_drama_generation_task')->insert($scope+['task_id'=>$task,'task_type'=>'shot_image','request_json'=>$json]);
        $asset=Db::name('aigc_short_drama_asset')->insertGetId($scope+['task_id'=>$task,'asset_type'=>'shot_image','uri'=>'test/old.png']);
        $foreign=Db::name('aigc_short_drama_asset')->insertGetId(array_replace($scope,['tenant_id'=>2000000720])+['task_id'=>$task,'asset_type'=>'shot_image','uri'=>'test/foreign.png']);
        $method=new \ReflectionMethod(AigcShortDramaService::class,'inheritVisualDependencies'); $method->setAccessible(true);
        $empty=Dependencies::capture([],[]);
        self::assertSame($empty,$method->invoke(null, $scope['tenant_id'],7,$scope['project_id'],$empty,[$foreign]));
        self::assertSame($empty,$method->invoke(null, $scope['tenant_id'],8,$scope['project_id'],$empty,[$asset]));
        self::assertSame($empty,$method->invoke(null, $scope['tenant_id'],7,$scope['project_id']+1,$empty,[$asset]));
        $result=$method->invoke(null,$scope['tenant_id'],7,$scope['project_id'],$empty,[$asset]);
        self::assertSame($baseline['subjects'],$result['subjects']);
        self::assertSame($json,Db::name('aigc_short_drama_generation_task')->where('task_id',$task)->value('request_json'));
        self::assertSame('test/old.png',Db::name('aigc_short_drama_asset')->where('id',$asset)->value('uri'));
    }
    public function testVisualEditPreservesLegacyMaterialsAndExposesMatchingCatalog(): void
    {
        $scope=['tenant_id'=>2000000719,'user_id'=>7];
        $task='test_visual_plan_' . bin2hex(random_bytes(8));
        $project=Db::name('aigc_short_drama_project')->insertGetId($scope+['last_task_id'=>$task,'title'=>'兼容性测试']);
        $plan=['title'=>'旧项目','subjects'=>[['id'=>'p','name'=>'主角','description'=>'蓝色外套','visual_prompt'=>'蓝色外套']],
            'locations'=>[['id'=>'s','name'=>'街道','description'=>'白天街道','visual_prompt'=>'白天街道']],
            'storyboard'=>[['shot_id'=>'1','visual_description'=>'街头相遇','subject_ref_ids'=>['p'],'scene_ref_id'=>'s','dialogue'=>'原台词','recommended_duration_seconds'=>5]]];
        Db::name('aigc_short_drama_script_task')->insert($scope+['project_id'=>$project,'task_id'=>$task,'status'=>'success','request_json'=>'{"quality_review_enabled":false}','result_json'=>json_encode($plan)]);
        Db::name('aigc_short_drama_plan_version')->insert($scope+['project_id'=>$project,'task_id'=>$task,'is_current'=>1,'version_no'=>1,'plan_json'=>json_encode($plan)]);
        $asset=Db::name('aigc_short_drama_asset')->insertGetId($scope+['project_id'=>$project,'task_id'=>'legacy_image','shot_id'=>'1','asset_type'=>'shot_image','uri'=>'test/legacy.png']);
        Db::name('aigc_short_drama_storyboard')->insert($scope+['project_id'=>$project,'task_id'=>$task,'shot_id'=>'1','subject_ref_ids'=>'["p"]','scene_ref_id'=>'s','dialogue'=>'原台词','selected_image_asset_id'=>$asset,'recommended_duration_seconds'=>5]);
        $before=Db::name('aigc_short_drama_asset')->where('id',$asset)->find();
        $plan['subjects'][0]['visual_prompt']='红色外套';
        $result=AigcShortDramaService::saveVisualPlan($scope['tenant_id'],7,['project_id'=>$project,'task_id'=>$task,'subjects'=>$plan['subjects'],'locations'=>$plan['locations']]);
        $raw=new \ReflectionMethod(AigcShortDramaService::class,'currentProjectPlanRaw'); $raw->setAccessible(true);
        self::assertSame(Dependencies::catalog($raw->invoke(null,$scope['tenant_id'],7,$project)), $result['visual_dependency_catalog']);
        self::assertSame($before,Db::name('aigc_short_drama_asset')->where('id',$asset)->find());
        $shot=Db::name('aigc_short_drama_storyboard')->where('task_id',$task)->find();
        self::assertSame((int)$asset,(int)$shot['selected_image_asset_id']);
        self::assertSame('原台词',$shot['dialogue']);
        self::assertSame(5,(int)$shot['recommended_duration_seconds']);
    }

}
