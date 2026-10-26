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
}
