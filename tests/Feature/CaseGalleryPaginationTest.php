<?php
namespace Tests\Feature;

use app\common\model\app\AppCase;
use app\common\service\app\AppCaseService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class CaseGalleryPaginationTest extends TestCase
{
    public function testTwentyItemPagesFiltersSearchAndLegacyLimitRemainTenantScoped(): void
    {
        if(getenv('AIGC_CASE_PAGINATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $tenant=1999999421;$apps=['aigc_image','aigc_video'];
        Db::startTrans();
        try {
            for($i=1;$i<=48;$i++) {
                AppCase::create(['tenant_id'=>$i===48?$tenant+1:$tenant,'app_code'=>$i<=28?'aigc_image':'aigc_video',
                    'title'=>$i===45?'末页灵感关键字':'分页作品 '.$i,'prompt'=>$i===44?'测试 literal_% 搜索':'提示词 '.$i,
                    'media_type'=>$i<=28?'image':'video','cover_uri'=>'https://fixture.invalid/cover.png',
                    'media_uri'=>'https://fixture.invalid/result.'.($i<=28?'png':'mp4'),
                    'reference_images'=>[],'config_json'=>[],'sort'=>100-$i,
                    'status'=>$i===46?0:1,'delete_time'=>$i===47?time():0,'create_time'=>time(),'update_time'=>time()]);
            }
            $fetch=static fn(array $params)=>AppCaseService::listsByAppCodes($tenant,$apps,$params,true);
            $pages=[];
            foreach([1=>20,2=>20,3=>5,4=>0] as $page=>$size) {
                $result=$fetch(['page_no'=>$page,'page_size'=>20]);
                self::assertCount($size,$result['lists']);self::assertSame(45,$result['count']);
                $pages=array_merge($pages,array_column($result['lists'],'id'));
            }
            self::assertCount(45,array_unique($pages));
            $videos=$fetch(['media_type'=>'video','page_no'=>1,'page_size'=>20]);
            self::assertSame(17,$videos['count']);self::assertSame(['video'],array_values(array_unique(array_column($videos['lists'],'media_type'))));
            self::assertSame(28,$fetch(['app_code'=>'aigc_image','page_no'=>1,'page_size'=>20])['count']);
            $search=$fetch(['keyword'=>'末页灵感','page_no'=>1,'page_size'=>20]);
            self::assertSame(1,$search['count']);self::assertSame('末页灵感关键字',$search['lists'][0]['title']);
            self::assertSame(1,$fetch(['keyword'=>'literal_%','page_no'=>1,'page_size'=>20])['count']);
            self::assertSame(0,$fetch(['keyword'=>'不存在的作品','page_no'=>1,'page_size'=>20])['count']);
            self::assertCount(45,$fetch(['limit'=>100]));
        } finally { Db::rollback(); }
        self::assertSame(0,AppCase::where('tenant_id',$tenant)->count());
    }
}
