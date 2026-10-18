<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_micro_video\MicroVideoCommerceTemplates as Catalog;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchRecords as Records;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchTemplates as Templates;
use app\common\service\app\aigc_micro_video\MicroVideoStore as Store;

/** Local test transaction only; never submits provider requests or invokes runtime workers. */
final class MicroVideoCommerceTemplatesWorkflowTest extends TestCase
{
    private int $tenant=987654312;
    private bool $transaction=false;

    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();
        $db=config('database.connections.mysql');
        if($db['hostname']!=='127.0.0.1'||$db['database']!=='z_cn')self::markTestSkipped('Local transactional acceptance only');
        Db::startTrans();$this->transaction=true;
        Records::table('version')->where('tenant_id',$this->tenant)->delete();
        Records::table()->where('tenant_id',$this->tenant)->delete();
    }

    protected function tearDown(): void
    {if($this->transaction)Db::rollback();}

    private function directions(): array
    {return [['id'=>901,'code'=>'free','name'=>'Free fixture','status'=>1,'prompt'=>'Preserved free direction rule','continuity_prompt'=>'Preserved continuity rule','description'=>'Existing direction']];}

    private function rows(): array
    {return Records::table()->where('tenant_id',$this->tenant)->order('id')->select()->toArray();}

    private function commerceRows(): array
    {return array_values(array_filter($this->rows(),static fn($row)=>str_starts_with(Store::json($row['body_json'])['seed_key']??'','commerce_v1_')));}

    private function protectedState(): array
    {
        return [
            'directions'=>Store::table('direction')->order('id')->select()->toArray(),
            'tasks'=>Store::table('task')->order('id')->select()->toArray(),
            'consumption'=>Db::name('ai_consumption_log')->count(),
            'generation_jobs'=>Records::table('job')->count(),
            'users'=>Db::name('user')->field('id,user_money')->order('id')->select()->toArray(),
            'tenants'=>Db::name('tenant')->field('id,point_balance')->order('id')->select()->toArray(),
            'registry'=>Db::name('app')->order('id')->select()->toArray(),
            'other_records'=>Records::table()->where('tenant_id','<>',$this->tenant)->order('id')->select()->toArray(),
        ];
    }

    public function testSeedIsTenantScopedIdempotentPreservesDisabledDeletedAndCustomRecordsWithoutAnyCharge(): void
    {
        $protected=$this->protectedState();
        $custom=Records::save($this->tenant,19,['kind'=>'template','title'=>'My untouched template','body'=>['script'=>'custom']]);
        Templates::seed($this->tenant,$this->directions());
        self::assertCount(31,$this->commerceRows());
        self::assertCount(40,$this->rows()); // 31 commerce + 8 prior starters + custom.
        $seeded=$this->commerceRows();$first=Records::dto($seeded[0]);
        $http=(new \app\Request())->withPost(['id'=>$first['id'],'revision'=>1,'kind'=>'template','title'=>'Tenant edit remains disabled','visibility'=>'tenant','status'=>0,'body'=>$first['body']])->post();
        $edited=Records::save($this->tenant,0,$http,true);
        self::assertIsInt($edited['body']['default_duration']);self::assertIsInt($edited['body']['segments'][0]['duration']);self::assertIsInt($edited['body']['segments'][0]['sort']);
        Records::delete($this->tenant,0,['id'=>(int)$seeded[1]['id'],'revision'=>1,'kind'=>'template'],true);
        $before=$this->rows();$versions=Records::table('version')->where('tenant_id',$this->tenant)->order('id')->select()->toArray();
        Templates::seed($this->tenant,$this->directions());Templates::seed($this->tenant,$this->directions());
        self::assertSame($before,$this->rows());
        self::assertSame($versions,Records::table('version')->where('tenant_id',$this->tenant)->order('id')->select()->toArray());
        self::assertSame('custom',Records::dto(Records::owned($this->tenant,19,'template',$custom['id']))['body']['script']);
        foreach($this->commerceRows() as $row){$body=Records::dto($row)['body'];self::assertSame(901,$body['direction_id']);self::assertSame('Preserved free direction rule',$body['direction_rules']);self::assertIsInt($body['default_duration']);self::assertSame([$body['default_duration']],$body['durations']);self::assertTrue($body['roles'][0]['fixed_identity']);self::assertSame(0,(int)$row['user_id']);self::assertTrue($body['protected_prompt']);self::assertArrayNotHasKey('internal_prompt',$body);self::assertNotEmpty(Store::json($row['body_json'])['internal_prompt']);}
        self::assertSame($protected,$this->protectedState());
    }

    public function testFailedVersionInsertRollsBackEntireCommerceBatchAndRetryCreatesNoDuplicates(): void
    {
        $fail=true;$inserted=0;$tenant=$this->tenant;
        Db::event('after_insert',static function($query)use(&$fail,&$inserted,$tenant){
            $options=$query->getOptions();$data=$options['data']??[];
            if($fail&&($data['tenant_id']??0)===$tenant&&str_ends_with((string)($options['table']??''),'aigc_micro_video_workbench_version')&&str_contains((string)($data['body_json']??''),'commerce_v1_')&&++$inserted===2){$fail=false;throw new \RuntimeException('Injected commerce version failure');}
        });
        try{Templates::seed($this->tenant,$this->directions());self::fail('Injected failure did not occur');}catch(\RuntimeException $error){self::assertSame('Injected commerce version failure',$error->getMessage());}
        self::assertCount(0,$this->commerceRows());
        self::assertSame(0,Records::table('version')->where('tenant_id',$this->tenant)->whereLike('body_json','%commerce_v1_%')->count());
        Templates::seed($this->tenant,$this->directions());Templates::seed($this->tenant,$this->directions());
        self::assertCount(31,$this->commerceRows());
        self::assertSame(31,Records::table('version')->where('tenant_id',$this->tenant)->whereLike('body_json','%commerce_v1_%')->count());
    }

    public function testDisabledMissingDirectionsUseFreeCreationWithoutChangingDirections(): void
    {
        Templates::seed($this->tenant,[['id'=>901,'code'=>'free','name'=>'Disabled','status'=>0,'prompt'=>'Disabled rule','continuity_prompt'=>'Disabled continuity','description'=>'fixture']]);
        foreach($this->commerceRows() as $row){$body=Records::dto($row)['body'];self::assertSame(0,$body['direction_id']);self::assertSame(1,(int)$row['status']);self::assertNotEmpty($body['direction_rules']);}
    }
}
