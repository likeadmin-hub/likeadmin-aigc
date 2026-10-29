<?php
namespace Tests\Feature;
use app\common\service\app\aigc_micro_video\MicroVideoCatalog;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
final class MicroVideoSummaryCatalogTest extends TestCase
{
    protected function setUp(): void { (new \think\App(dirname(__DIR__,2).'/'))->initialize();$db=config('database.connections.mysql');if($db['hostname']!=='127.0.0.1'||!in_array($db['database'],['x_cn','z_cn'],true))self::markTestSkipped('Local database only');Db::startTrans(); }
    protected function tearDown(): void {Db::rollback();}
    public function testSummariesPreserveSelectableModelsAndDefaultRetainsCompleteSpecs(): void
    {
        $full=MicroVideoCatalog::models(1,false,'reference');
        $summaries=MicroVideoCatalog::models(1,false,'reference',null,true);
        self::assertNotEmpty($full);
        $shape=static fn($m)=>array_intersect_key($m,array_flip(['id','label','supports_first_last_frame','generation_modes']));
        self::assertSame(array_map($shape,$full),array_map($shape,$summaries));
        foreach($summaries as $row){self::assertFalse($row['specs_complete']);self::assertNotEmpty($row['specs']);}
        $config=MicroVideoSettings::get(1);$default=(string)$config['default_model_id'];
        $detail=MicroVideoSettings::detail(1,true,true);
        self::assertSame($config,$detail['config']);
        if($default!==''&&in_array($default,array_column($full,'id'),true)){
            $byId=array_column($detail['available_models'],null,'id');$fullById=array_column($full,null,'id');
            self::assertTrue($byId[$default]['specs_complete']);self::assertSame($fullById[$default]['specs'],$byId[$default]['specs']);
        }
    }
}
