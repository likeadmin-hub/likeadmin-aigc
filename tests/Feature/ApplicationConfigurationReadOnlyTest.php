<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\power\MarketCatalogReadScope;

final class ApplicationConfigurationReadOnlyTest extends TestCase
{
    public function testOpeningConfigurationNeverCreatesDefaultsOrWritesPrices(): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();
        $db=config('database.connections.mysql');
        if($db['hostname']!=='127.0.0.1'||!in_array($db['database'],['x_cn','z_cn'],true))self::markTestSkipped('Local read-only verification only');
        request()->tenantId=1;
        request()->source=\app\common\enum\AdminTerminalEnum::TENANT;
        $services=[
            \app\common\service\app\aigc_background_removal\AigcBackgroundRemovalService::class,
            \app\common\service\app\aigc_geo\AigcGeoService::class,
            \app\common\service\app\aigc_watermark_removal\AigcWatermarkRemovalService::class,
            \app\common\service\app\aigc_story_commerce\StoryCommerceService::class,
            \app\common\service\app\aigc_store_visit\StoreVisitService::class,
        ];
        Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
        try{
            foreach($services as $service){
                $config=$service::config(1);
                self::assertArrayHasKey('status',$config,$service);
                if(isset($config['video_models'])){
                    self::assertIsArray($config['planning_models']);
                    self::assertIsArray($config['video_models']);
                    if($config['default_video_model_id']!=='')self::assertContains($config['default_video_model_id'],array_column($config['video_models'],'id'));
                }
            }
        }finally{Db::rollback();}
    }
}
