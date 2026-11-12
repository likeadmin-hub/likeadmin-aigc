<?php
namespace Tests\Feature;

use app\common\service\app\AppAvailabilityConfigService;
use PHPUnit\Framework\TestCase;
use think\facade\{Config,Db};

final class AppAvailabilityCollationTest extends TestCase
{
    public function testLegacySwitchCollationsDoNotBlockSharedToolDiscovery(): void
    {
        if(getenv('AIGC_VIDEO_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local database fixture required');
        (new \think\App())->initialize();$original=config('database');$cfg=$original;
        self::assertSame('127.0.0.1',$cfg['connections']['mysql']['hostname']);
        self::assertContains($cfg['connections']['mysql']['database'],['x_cn','z_cn']);
        $prefix='availability_'.bin2hex(random_bytes(5)).'_';$pdo=Db::connect()->getPdo();$tables=[];
        try {
            foreach(['aigc_image'=>'utf8mb4_unicode_ci','aigc_video'=>'utf8mb4_general_ci'] as $code=>$collation){
                $table=$prefix.$code.'_config';$pdo->exec('CREATE TABLE `'.$table.'` (tenant_id INT, status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE '.$collation.') ENGINE=InnoDB');$tables[]=$table;
                $pdo->exec('INSERT INTO `'.$table.'` VALUES (0,\'1\'),(7,\'1\')');
            }
            $table=$prefix.'tenant_app_config';$pdo->exec('CREATE TABLE `'.$table.'` (tenant_id INT, app_code VARCHAR(100), status INT) ENGINE=InnoDB');$tables[]=$table;
            $cfg['connections']['mysql']['prefix']=$prefix;Config::set($cfg,'database');Db::connect(null,true);
            self::assertSame(['aigc_image','aigc_video'],AppAvailabilityConfigService::filterAvailable(7,['aigc_image','aigc_video']));
            $pdo->exec('UPDATE `'.$prefix.'aigc_image_config` SET status=\'0\' WHERE tenant_id=7');
            self::assertSame(['aigc_video'],AppAvailabilityConfigService::filterAvailable(7,['aigc_image','aigc_video']));
            $pdo->exec('INSERT INTO `'.$prefix.'tenant_app_config` VALUES (0,\'aigc_video\',0)');
            self::assertSame([],AppAvailabilityConfigService::filterAvailable(7,['aigc_image','aigc_video']));
        } finally {
            Config::set($original,'database');Db::connect(null,true);
            foreach(array_reverse($tables) as $table)$pdo->exec('DROP TABLE `'.$table.'`');
        }
    }
}
