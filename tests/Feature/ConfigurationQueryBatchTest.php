<?php
namespace Tests\Feature;

use app\common\service\app\aigc_product_suite\AigcProductSuiteService;
use app\common\service\app\aigc_short_drama\ShortDramaSkillService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ConfigurationQueryBatchTest extends TestCase
{
    private function local(): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();
        $db=config('database.connections.mysql');
        if($db['hostname']!=='127.0.0.1'||!in_array($db['database'],['x_cn','z_cn'],true))self::markTestSkipped('Local transaction fixture only');
        request()->tenantId=1;request()->source=\app\common\enum\AdminTerminalEnum::TENANT;
        $connections=config('database.connections');$connections['mysql']['trigger_sql']=true;config(['connections'=>$connections],'database');Db::connect('mysql',true);
    }

    public function testProductSuiteAssemblesEachCatalogueOnce(): void
    {
        $this->local();$rules=0;
        Db::listen(static function($sql)use(&$rules){if(str_starts_with($sql,'SELECT')&&str_contains($sql,'`la_power_market_price_rule`'))$rules++;});
        Db::startTrans();
        try{$config=AigcProductSuiteService::config(1);self::assertNotEmpty($config['option_config']);self::assertLessThanOrEqual(2,$rules);self::assertNull(request()->marketCatalogReadScope);}
        finally{Db::rollback();}
    }

    public function testBuiltinBatchPreservesPublishedVersionsAndRepairsMissingSnapshots(): void
    {
        $this->local();$method=new \ReflectionMethod(ShortDramaSkillService::class,'syncBuiltinSkills');$method->setAccessible(true);
        $reads=0;$writes=0;Db::listen(static function($sql)use(&$reads,&$writes){if(str_starts_with($sql,'SELECT'))$reads++;if(preg_match('/^(INSERT|UPDATE|DELETE)/',$sql))$writes++;});
        Db::startTrans();
        try{
            $method->invoke(null);
            $query=static fn()=>Db::name('aigc_short_drama_skill')->where(['tenant_id'=>0,'delete_time'=>0])->order('id')->select()->toArray();
            $before=$query();self::assertGreaterThan(2,count($before));$reads=0;$writes=0;
            $method->invoke(null);self::assertLessThanOrEqual(6,$reads,'Builtins must use bulk reads');self::assertSame(0,$writes);self::assertSame($before,$query());
            $first=$before[0];$second=$before[1];
            Db::name('aigc_short_drama_skill')->where('id',$first['id'])->update(['description'=>'local stale definition fixture']);
            Db::name('aigc_short_drama_skill_version')->where(['tenant_id'=>0,'skill_id'=>$second['id'],'version'=>$second['published_version']])->delete();
            $method->invoke(null);
            $updated=Db::name('aigc_short_drama_skill')->where('id',$first['id'])->find();
            self::assertSame((string)$first['description'],(string)$updated['description']);self::assertSame((int)$first['version']+1,(int)$updated['version']);
            foreach([$updated,$second]as$skill){$snapshot=Db::name('aigc_short_drama_skill_version')->where(['tenant_id'=>0,'skill_id'=>$skill['id'],'version'=>$skill['published_version']])->find();self::assertNotEmpty($snapshot);$payload=json_decode($snapshot['snapshot_json'],true);self::assertSame((string)$skill['skill_key'],(string)$payload['skill_key']);}
        }finally{Db::rollback();}
    }
}
