<?php
namespace Tests\Feature;

use app\common\model\app\AppCase;
use app\common\service\app\AppCaseService;
use app\common\service\case_gallery\{CaseGalleryService as Gallery,CasePaletteService};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class CaseGallerySeparationTest extends TestCase
{
    private function rejects(callable $operation): void
    {
        try { $operation(); } catch (\Exception $e) { self::assertNotSame('', $e->getMessage()); return; }
        self::fail('The work gallery accepted a tool template');
    }

    public function testWorkGalleryCannotDiscoverOrManageToolTemplates(): void
    {
        if(getenv('AIGC_CASE_PAGINATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $tenant=1999999422;$tools=['aigc_product_video','aigc_motion_sync','aigc_story_commerce','aigc_hairstyle'];
        Db::startTrans();
        try {
            $add=static function(string $app,string $media,int $owner,int $status=1): array {
                return AppCase::create(['tenant_id'=>$owner,'app_code'=>$app,'title'=>'gallery-separation-'.$app,'prompt'=>'作品案例',
                    'media_type'=>$media,'cover_uri'=>'https://fixture.invalid/cover.png','media_uri'=>'https://fixture.invalid/work.'.($media==='image'?'png':'mp4'),
                    'reference_images'=>[],'config_json'=>[],'sort'=>9999,'status'=>$status,'delete_time'=>0,'create_time'=>time(),'update_time'=>time()])->toArray();
            };
            foreach(Gallery::APP_CODES as $app)$add($app,$app==='aigc_image'?'image':'video',$tenant);
            $disabled=$add('aigc_image','image',$tenant,0);$add('aigc_video','video',$tenant+1);
            $templateRows=[];
            foreach($tools as $tool){
                $templateRows[$tool]=$add($tool,$tool==='aigc_hairstyle'?'image':'video',$tenant);
                Db::name('tenant_app')->insert(['tenant_id'=>$tenant,'app_code'=>$tool,'buy_status'=>'paid','shelf_status'=>'on','enable_status'=>'enabled']);
            }
            $before=AppCase::where('tenant_id',$tenant)->whereIn('app_code',$tools)->order('id')->select()->toArray();
            self::assertSame(Gallery::APP_CODES,array_column(Gallery::appOptions($tenant),'app_code'));
            foreach([false,true] as $public){
                $result=Gallery::lists($tenant,['page_no'=>1,'page_size'=>2],$public);
                self::assertSame($public?4:5,$result['count']);self::assertCount(2,$result['lists']);
                $all=Gallery::lists($tenant,['limit'=>100],$public);
                self::assertCount($public?4:5,$all);
                foreach($all as $row){self::assertContains($row['app_code'],Gallery::APP_CODES);self::assertContains($row['media_type'],['image','video']);self::assertSame($tenant,(int)$row['tenant_id']);}
            }
            self::assertSame(1,Gallery::lists($tenant,['app_code'=>'aigc_image','status'=>0,'page_no'=>1])['count']);
            self::assertSame(3,Gallery::lists($tenant,['media_type'=>'video','page_no'=>1],true)['count']);
            self::assertSame(1,Gallery::lists($tenant,['keyword'=>'aigc_video','page_no'=>1],true)['count']);
            self::assertSame($disabled['id'],Gallery::detail($tenant,(int)$disabled['id'])['id']);
            foreach($templateRows as $tool=>$row){
                $id=(int)$row['id'];
                $this->rejects(fn()=>Gallery::lists($tenant,['app_code'=>$tool],true));
                $this->rejects(fn()=>Gallery::listsByAppCodes($tenant,[$tool],[],true));
                $this->rejects(fn()=>Gallery::listsByAppCodes($tenant,['aigc_image'],['app_code'=>$tool],true));
                $this->rejects(fn()=>Gallery::detail($tenant,$id));
                $this->rejects(fn()=>Gallery::detailByAppCodes($tenant,[$tool],$id));
                $this->rejects(fn()=>Gallery::save($tenant,['id'=>$id,'app_code'=>$tool,'title'=>'must not change']));
                $this->rejects(fn()=>Gallery::save($tenant,['id'=>$id,'app_code'=>'aigc_image','title'=>'must not reclassify']));
                $this->rejects(fn()=>Gallery::saveByAppCodes($tenant,[$tool],['id'=>$id,'title'=>'must not change'],$tool));
                $this->rejects(fn()=>Gallery::fromTask($tenant,999999,['app_code'=>$tool]));
                $this->rejects(fn()=>Gallery::setStatus($tenant,$id,0));
                $this->rejects(fn()=>Gallery::setStatusByAppCodes($tenant,[$tool],$id,0));
                $this->rejects(fn()=>Gallery::delete($tenant,$id));
                $this->rejects(fn()=>Gallery::deleteByAppCodes($tenant,[$tool],$id));
                self::assertSame('',CasePaletteService::color($tenant,$id));
            }
            // The shared app-case storage still serves each tool's own template APIs.
            self::assertCount(1,AppCaseService::lists($tenant,'aigc_product_video'));
            self::assertCount(1,AppCaseService::lists($tenant,'aigc_story_commerce'));
            self::assertSame($before,AppCase::where('tenant_id',$tenant)->whereIn('app_code',$tools)->order('id')->select()->toArray());
        } finally { Db::rollback(); }
        self::assertSame(0,AppCase::where('tenant_id',$tenant)->count());
    }
}
