<?php
namespace Tests\Feature;

use app\tenantapi\lists\power\TenantPowerConsumeLogLists;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class TenantPowerConsumeLogQueryBudgetTest extends TestCase
{
    public function testPageMatchesTheOriginalJoinAndCountDoesNotJoinUserBills(): void
    {
        (new \think\App(dirname(__DIR__, 2).'/'))->initialize();
        $db = config('database.connections.mysql');
        if ($db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn','z_cn'], true)) self::markTestSkipped('Local read-only verification only');
        request()->tenantId=1;
        $connections=config('database.connections');$connections['mysql']['trigger_sql']=true;config(['connections'=>$connections],'database');Db::connect('mysql',true);
        $list=(new \ReflectionClass(TenantPowerConsumeLogLists::class))->newInstanceWithoutConstructor();
        $list->request=request();$list->limitOffset=0;$list->limitLength=15;
        (new \ReflectionProperty($list,'adminInfo'))->setValue($list,['tenant_id'=>1]);
        (new \ReflectionProperty($list,'params'))->setValue($list,[]);
        $build=new \ReflectionMethod($list,'buildQuery');$fields=new \ReflectionMethod($list,'fields');$format=new \ReflectionMethod($list,'formatRows');
        $expectedQuery=$build->invoke(null,1,[]);
        $expectedCount=(clone $expectedQuery)->group('tpl.id')->count('tpl.id');
        $expectedRows=self::legacyRows(static fn()=>$expectedQuery->field($fields->invoke(null))->group('tpl.id')->order('tpl.id','desc')->limit(15)->select()->toArray());
        self::assertSame($format->invoke(null,$expectedRows),$list->lists());
        $queries=[];Db::listen(static function($sql)use(&$queries){$queries[]=$sql;});
        $actualCount=$list->count();
        self::assertSame((int)$expectedCount,$actualCount, json_encode($queries));
        self::assertNotEmpty($queries);
        foreach($queries as $sql)self::assertStringNotContainsString('JOIN',$sql,'Default count should read only the tenant ledger');
        $last=Db::name('tenant_point_log')->where('tenant_id',1)->order('id','desc')->value('source_sn');
        if ($last) {
            (new \ReflectionProperty($list,'params'))->setValue($list,['source_sn'=>$last]);
            $rows=self::legacyRows(static fn()=>$build->invoke(null,1,['source_sn'=>$last])->field($fields->invoke(null))->group('tpl.id')->order('tpl.id','desc')->limit(15)->select()->toArray());
            self::assertSame($format->invoke(null,$rows),$list->lists());
        }
    }
    private static function legacyRows(callable $read): array
    {
        // The comparison query predates ONLY_FULL_GROUP_BY. Production reads
        // below must still execute in the caller's original strict SQL mode.
        $mode=(string)Db::query('SELECT @@SESSION.sql_mode AS mode')[0]['mode'];
        Db::execute('SET SESSION sql_mode=?',[implode(',',array_diff(explode(',',$mode),['ONLY_FULL_GROUP_BY']))]);
        try{return $read();}finally{Db::execute('SET SESSION sql_mode=?',[$mode]);}
    }
}
