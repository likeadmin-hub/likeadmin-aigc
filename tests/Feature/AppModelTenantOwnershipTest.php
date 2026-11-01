<?php
namespace Tests\Feature;

use app\common\enum\AdminTerminalEnum;
use app\common\model\user\User;
use app\common\model\app\aigc_short_drama\AigcShortDramaSkill;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** A core lookup precedes app writes in normal authenticated HTTP. All rows roll back. */
class AppModelTenantOwnershipTest extends TestCase
{
    public function testCoreTenantScopeCannotReassignExplicitAppOwnership(): void
    {
        if (getenv('SHORT_DRAMA_DB_TESTS') !== '1') self::markTestSkipped('Local transactional test only');
        (new \think\App())->initialize();
        self::assertSame('x_cn', Db::connect()->getConfig('database'));
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1','localhost']);
        $request=request();$oldSource=$request->source;$oldTenant=$request->tenantId;
        $tenant=1999999689;$key='ownership_'.bin2hex(random_bytes(8));
        Db::startTrans();
        try {
            self::assertSame(0, (int)Db::name('tenant')->where('id',$tenant)->count());
            Db::name('tenant')->insert(['id'=>$tenant,'sn'=>'ownership','name'=>'Rollback ownership','tactics'=>0]);
            $request->source=AdminTerminalEnum::USER;$request->tenantId=$tenant;
            User::where('id',0)->find(); // Populates the inherited core tenant-field state.
            $platform=AigcShortDramaSkill::create(['tenant_id'=>0,'skill_key'=>$key,'name'=>'Platform fixture','create_time'=>time(),'update_time'=>time()]);
            self::assertSame(0,(int)Db::name('aigc_short_drama_skill')->where('id',$platform['id'])->value('tenant_id'));
            $platform->save(['name'=>'Updated platform fixture']);
            self::assertSame(0,(int)Db::name('aigc_short_drama_skill')->where('id',$platform['id'])->value('tenant_id'));
            $owned=AigcShortDramaSkill::create(['tenant_id'=>$tenant,'skill_key'=>$key,'name'=>'Tenant fixture','create_time'=>time(),'update_time'=>time()]);
            self::assertSame($tenant,(int)$owned['tenant_id']);
            $legacy=AigcShortDramaSkill::create(['skill_key'=>$key.'_legacy','name'=>'Legacy caller','create_time'=>time(),'update_time'=>time()]);
            self::assertSame($tenant,(int)$legacy['tenant_id'],'App callers without an explicit owner retain their original fallback');
            $core=User::create(['tenant_id'=>0,'account'=>$key,'nickname'=>'Core fixture','create_time'=>time(),'update_time'=>time()]);
            self::assertSame($tenant,(int)$core['tenant_id'],'Core model automatic tenant enforcement is unchanged');
        } finally {
            Db::rollback();$request->source=$oldSource;$request->tenantId=$oldTenant;
        }
        self::assertSame(0,(int)Db::name('aigc_short_drama_skill')->where('skill_key',$key)->count());
        self::assertSame(0,(int)Db::name('tenant')->where('id',$tenant)->count());
    }
}
