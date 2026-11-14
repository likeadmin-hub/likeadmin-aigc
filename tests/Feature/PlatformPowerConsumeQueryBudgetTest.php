<?php
namespace Tests\Feature;

use app\platformapi\lists\tenant\TenantPowerConsumeLists;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class PlatformPowerConsumeQueryBudgetTest extends TestCase
{
    public function testPageMatchesTheOriginalJoinAndCountDoesNotJoinUserBills(): void
    {
        (new \think\App(dirname(__DIR__, 2).'/'))->initialize();
        $db = config('database.connections.mysql');
        if ($db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn','z_cn'], true)) self::markTestSkipped('Local read-only verification only');
        request()->tenantId=1;
        $connections=config('database.connections');$connections['mysql']['trigger_sql']=true;config(['connections'=>$connections],'database');Db::connect('mysql',true);
        $list=(new \ReflectionClass(TenantPowerConsumeLists::class))->newInstanceWithoutConstructor();
        $list->request=request();$list->limitOffset=0;$list->limitLength=15;
        (new \ReflectionProperty($list,'params'))->setValue($list,[]);
        $build=new \ReflectionMethod($list,'baseQuery');$fields=new \ReflectionMethod($list,'fields');$format=new \ReflectionMethod($list,'formatRows');
        $expectedQuery=$build->invoke($list);
        $expectedCount=(clone $expectedQuery)->group('tpl.id')->count('tpl.id');
        $expectedRows=$expectedQuery->field($fields->invoke(null))->group('tpl.id')->order('tpl.id','desc')->limit(15)->select()->toArray();
        self::assertSame($format->invoke(null,$expectedRows),$list->lists());
        $queries=[];Db::listen(static function($sql)use(&$queries){$queries[]=$sql;});
        $actualCount=$list->count();
        self::assertSame((int)$expectedCount,$actualCount, json_encode($queries));
        self::assertNotEmpty($queries);
        foreach($queries as $sql)self::assertStringNotContainsString('user_account_log',$sql,'Default count must not join user bills');
        $last=Db::name('tenant_point_log')->where('tenant_id',1)->order('id','desc')->value('source_sn');
        if ($last) {
            (new \ReflectionProperty($list,'params'))->setValue($list,['source_sn'=>$last]);
            $rows=$build->invoke($list)->field($fields->invoke(null))->group('tpl.id')->order('tpl.id','desc')->limit(15)->select()->toArray();
            self::assertSame($format->invoke(null,$rows),$list->lists());
        }
    }
}
