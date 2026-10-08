<?php
namespace Tests\Feature;

use app\common\model\app\aigc_pic_lipsync\AigcPicLipsyncTask;
use app\common\model\app\aigc_music\AigcMusicAsset;
use app\common\model\app\image_human\ImageHumanAvatar;
use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncAdminService as Admin;
use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncService as Service;
use app\common\service\app\AppRegistryService as Registry;
use app\common\service\app\AppAccessService as Access;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class PicLipsyncManagementTest extends TestCase
{
    private bool $transaction = false;
    protected function setUp(): void
    {
        if (getenv('PIC_LIPSYNC_MYSQL_TEST') !== '1') $this->markTestSkipped('Pinned local database opt-in required');
        (new \think\App())->initialize();
        self::assertSame('z_cn', Db::connect()->getConfig('database'));
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        Db::startTrans(); $this->transaction = true;
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }
    private function task(int $tenant, array $params = []): int
    {
        return (int)AigcPicLipsyncTask::create(array_merge(['tenant_id'=>$tenant,'user_id'=>77001,'title'=>'management fixture','status'=>'success','quality'=>'max','drive_mode'=>'text','actual_tenant_cost'=>2.25,'actual_user_price'=>3.5,'input_seconds'=>1.125,'delete_time'=>0],$params))['id'];
    }
    public function testTenantStatsUseActualAmountsAndKeepDeletedSettlements(): void
    {
        $this->task(987001);$this->task(987001,['delete_time'=>time()]);
        $this->task(987001,['status'=>'failed','actual_tenant_cost'=>50,'actual_user_price'=>70]);
        $this->task(987002,['actual_tenant_cost'=>999]);
        $stat=Admin::stat(987001);
        self::assertSame(2,$stat['task_total']);self::assertSame(1,$stat['task_success']);self::assertSame(1,$stat['task_failed']);
        self::assertSame(4.5,$stat['tenant_cost_points']);self::assertSame(7.0,$stat['user_charge_points']);
        self::assertSame(2.25,$stat['input_seconds']);
    }
    public function testTaskFiltersCannotCrossTenantBoundary(): void
    {
        $id=$this->task(987001);$this->task(987002);$this->task(987001,['quality'=>'fast','user_id'=>77002]);
        $list=Service::taskLists(987001,0,['quality'=>'max','drive_mode'=>'text','user_id'=>77001,'keyword'=>'fixture']);
        self::assertSame(1,$list['count']);self::assertSame($id,(int)$list['lists'][0]['id']);
        $this->expectExceptionMessage('任务不存在');Service::taskDetail(987002,$id);
    }
    public function testAudioDeletionIsTenantAndAssetTypeScoped(): void
    {
        $a=AigcMusicAsset::create(['tenant_id'=>987001,'user_id'=>77001,'asset_type'=>'pic_lipsync_audio','name'=>'audio fixture','uri'=>'uploads/fixture.wav','delete_time'=>0]);
        $other=AigcMusicAsset::create(['tenant_id'=>987001,'asset_type'=>'cover_source','name'=>'other fixture','uri'=>'uploads/other.wav','delete_time'=>0]);
        self::assertSame(1,Admin::audioLists(987001,[])['count']);self::assertSame(0,Admin::audioLists(987002,[])['count']);
        Admin::deleteAudio(987001,(int)$a['id']);self::assertSame(0,Admin::audioLists(987001,[])['count']);
        $this->expectExceptionMessage('驱动音频不存在');Admin::deleteAudio(987001,(int)$other['id']);
    }
    public function testPartialConfigWritePreservesStoredSettings(): void
    {
        Service::saveConfig(987001,['status'=>0,'config_json'=>['fixture'=>'keep']]);
        Service::saveConfig(987001,['display_config'=>[]]);
        $config=Db::name('aigc_pic_lipsync_config')->where('tenant_id',987001)->find();
        self::assertSame(0,(int)$config['status']);self::assertSame(['fixture'=>'keep'],json_decode($config['config_json'],true));
    }
    public function testDisableAndUnshelfBlockUseButPreserveManagement(): void
    {
        Db::name('app')->where('code',Service::APP_CODE)->update(['status'=>'installed','expire_policy'=>'block']);
        Db::name('tenant_app')->where(['tenant_id'=>987001,'app_code'=>Service::APP_CODE])->delete();
        Db::name('tenant_app')->insert(['tenant_id'=>987001,'app_code'=>Service::APP_CODE,'buy_status'=>'paid','enable_status'=>'enabled','shelf_status'=>'on','expire_time'=>time()+3600]);
        self::assertTrue(Access::tenantCanUse(987001,Service::APP_CODE));
        Db::name('tenant_app')->where(['tenant_id'=>987001,'app_code'=>Service::APP_CODE])->update(['shelf_status'=>'off']);
        self::assertFalse(Access::tenantCanUse(987001,Service::APP_CODE));self::assertTrue(Access::tenantCanManage(987001,Service::APP_CODE));
        Registry::setStatus(Service::APP_CODE,'disabled');self::assertFalse(Access::tenantCanManage(987001,Service::APP_CODE));
        Registry::setStatus(Service::APP_CODE,'installed');self::assertTrue(Access::tenantCanManage(987001,Service::APP_CODE));
    }
    public function testPublicReferenceSamplesSaveAndPublishWithoutCloneWork(): void
    {
        $before=(int)\app\common\model\ai\AiConsumptionLog::count();
        $sample=Admin::savePublicVoice(987001,['name'=>'reference fixture','audio_uri'=>'uploads/reference-fixture.wav']);
        self::assertSame('',$sample['provider_asset_id']);self::assertSame('ready',$sample['status']);
        self::assertSame(1,Admin::voiceLists(987001,[],'official')['count']);
        self::assertSame(0,Admin::voiceLists(987002,[],'official')['count']);
        $user=\app\common\model\app\aigc_digital_human\AigcDigitalHumanVoice::create(['tenant_id'=>987001,'user_id'=>77001,'source'=>'mine','name'=>'uncloned reference','audio_uri'=>'uploads/reference-fixture.wav','provider_asset_id'=>'','status'=>'pending','delete_time'=>0]);
        $published=Admin::publishUserVoice(987001,(int)$user['id']);
        self::assertSame('',$published['provider_asset_id']);self::assertSame('official',$published['source']);
        self::assertSame($before,(int)\app\common\model\ai\AiConsumptionLog::count());
    }
    public function testPublicReferenceEditingCannotCrossTenantBoundary(): void
    {
        $voice=Admin::savePublicVoice(987001,['audio_uri'=>'uploads/reference-fixture.wav']);
        $this->expectExceptionMessage('公共音色不存在');
        Admin::savePublicVoice(987002,['id'=>$voice['id'],'audio_uri'=>'uploads/reference-fixture.wav']);
    }
    public function testPlanCanOpenRenewAndKeepUnshelvedState(): void
    {
        $plan=\app\common\service\app\AppPlanService::savePlan(['app_code'=>Service::APP_CODE,'name'=>'management fixture plan','duration_months'=>1,'open_points'=>0,'renew_points'=>0,'status'=>1]);
        $opened=\app\common\service\app\AppPlanService::openOrRenew(987003,0,Service::APP_CODE,(int)$plan['id']);
        self::assertSame('open',$opened['order_type']);self::assertTrue(Access::tenantCanUse(987003,Service::APP_CODE));
        Db::name('tenant_app')->where(['tenant_id'=>987003,'app_code'=>Service::APP_CODE])->update(['shelf_status'=>'off']);
        $renewed=\app\common\service\app\AppPlanService::openOrRenew(987003,0,Service::APP_CODE,(int)$plan['id']);
        self::assertSame('renew',$renewed['order_type']);self::assertGreaterThan($opened['after_expire_time'],$renewed['after_expire_time']);
        self::assertFalse(Access::tenantCanUse(987003,Service::APP_CODE));self::assertTrue(Access::tenantCanManage(987003,Service::APP_CODE));
    }
    public function testSoftUninstallRetainsTasksAndClearLeavesSharedLibraries(): void
    {
        $id=$this->task(987001);
        $avatar=ImageHumanAvatar::create(['tenant_id'=>987001,'source'=>'mine','user_id'=>77001,'name'=>'shared fixture','image_uri'=>'uploads/fixture.jpg','delete_time'=>0]);
        Registry::uninstall(Service::APP_CODE,false);
        self::assertNotNull(AigcPicLipsyncTask::find($id));self::assertFalse(Registry::isInstalled(Service::APP_CODE));
        Registry::uninstall(Service::APP_CODE,true);
        self::assertNull(AigcPicLipsyncTask::find($id));self::assertNotNull(ImageHumanAvatar::find((int)$avatar['id']));
    }
}
