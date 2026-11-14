<?php
namespace Tests\Feature;

use app\common\service\database\{QueryPerformanceSchema as Schema,RuntimeMigrationPlan,SqlMigrationExecutor};
use app\common\service\point\AccountLogSourceLookup;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class QueryPerformanceSchemaTest extends TestCase
{
    public function testExistingEquivalentIndexesAndImplicitPrimaryKeysAreRecognized(): void
    {
        $wanted=['tenant_id','user_id','delete_time','id'];
        self::assertTrue(Schema::covered(['PRIMARY'=>['id'],'customer_index'=>['tenant_id','user_id','delete_time']],$wanted));
        self::assertFalse(Schema::covered(['PRIMARY'=>['id'],'customer_index'=>['tenant_id','user_id','delete_time']],$wanted,false));
        self::assertFalse(Schema::covered(['PRIMARY'=>['id'],'wrong_order'=>['tenant_id','delete_time','user_id']],$wanted));
        self::assertTrue(Schema::covered(['lookup'=>['tenant_id','source_sn_lookup']],['tenant_id','source_sn_lookup(64)']));
        self::assertFalse(Schema::covered(['lookup'=>['tenant_id','source_sn_lookup(32)']],['tenant_id','source_sn_lookup(64)']));
        self::assertFalse(Schema::covered(['lookup'=>['tenant_id','source_sn_lookup(64)']],['tenant_id','source_sn_lookup']));
    }

    public function testUpgradeAndFreshInstallationCarryTheReviewedIndexes(): void
    {
        $root=dirname(__DIR__,2);
        $sql=file_get_contents($root.'/upgrade/20261114_query_performance_indexes.sql');
        self::assertSame($sql,file_get_contents($root.'/public/upgrade/20261114_query_performance_indexes.sql'));
        $plan=RuntimeMigrationPlan::build($sql);
        self::assertSame($sql,$plan['prepare']);self::assertSame('',$plan['complete']);
        self::assertStringNotContainsString('DROP ',$sql);
        $install=file_get_contents($root.'/public/install/db/like.sql');
        foreach(SqlMigrationExecutor::split($sql) as $statement)self::assertStringContainsString($statement,$install);
        $tenant=file_get_contents($root.'/app/platformapi/db/tenant.sql');
        self::assertStringContainsString('`la_tenant_config_{tenantSn}` ADD INDEX `idx_tenant_config_key`',$tenant);
        self::assertStringContainsString('`la_user_account_log_{tenantSn}` ADD COLUMN `source_sn_lookup`',$tenant);
    }

    public function testMigrationPreservesUnicodeSourceIdentityAndIsIdempotentAcrossShards(): void
    {
        if(getenv('PERFORMANCE_INDEX_DB_TESTS')!=='local-existing')self::markTestSkipped('Explicit isolated local schema fixture only');
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();
        $db=config('database.connections.mysql');self::assertSame('127.0.0.1',$db['hostname']);self::assertContains($db['database'],['x_cn','z_cn']);
        $prefix='audit_qp_'.bin2hex(random_bytes(5)).'_';
        $tables=['tenant','config','tenant_config','tenant_config_synth001','user_account_log'];
        try{
            Db::execute('CREATE TABLE `'.$prefix.'tenant` (id int primary key,sn varchar(32)) ENGINE=InnoDB');
            Db::table($prefix.'tenant')->insert(['id'=>1,'sn'=>'synth001']);
            Db::execute('CREATE TABLE `'.$prefix.'config` (id int primary key,type varchar(30),name varchar(60)) ENGINE=InnoDB');
            foreach(['tenant_config','tenant_config_synth001']as$table)Db::execute('CREATE TABLE `'.$prefix.$table.'` (id int primary key,tenant_id int,type varchar(30),name varchar(60)) ENGINE=InnoDB');
            Db::execute('ALTER TABLE `'.$prefix.'tenant_config` ADD INDEX existing_customer_key(tenant_id,type,name)');
            Db::execute('CREATE TABLE `'.$prefix.'user_account_log` (id int primary key,tenant_id int,user_id int,source_sn varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,action tinyint,change_type smallint,delete_time int) ENGINE=InnoDB');
            Db::table($prefix.'user_account_log')->insert(['id'=>1,'tenant_id'=>1,'user_id'=>1,'source_sn'=>'straße','action'=>2,'change_type'=>500,'delete_time'=>0]);
            $first=Schema::apply($prefix);self::assertNotEmpty($first['actions']);self::assertSame([],$first['skipped']);
            self::assertNotEmpty(array_filter($first['actions'],static fn($a)=>$a['table']===$prefix.'tenant_config_synth001'));
            self::assertSame([],array_values(array_filter($first['actions'],static fn($a)=>$a['table']===$prefix.'tenant_config')));
            $second=Schema::apply($prefix);self::assertSame([],$second['actions']);self::assertSame([],$second['skipped']);
            $old=Db::query('SELECT id FROM `'.$prefix.'user_account_log` WHERE CONVERT(source_sn USING utf8mb4) COLLATE utf8mb4_unicode_ci=\'strasse\' COLLATE utf8mb4_unicode_ci');
            $new=Db::query('SELECT id FROM `'.$prefix.'user_account_log` WHERE source_sn_lookup=\'strasse\' COLLATE utf8mb4_unicode_ci');
            self::assertSame([['id'=>1]],$old);self::assertSame($old,$new);
            $meta=[];foreach(Db::query('SELECT COLUMN_NAME,COLLATION_NAME,CHARACTER_MAXIMUM_LENGTH,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$prefix.'user_account_log'])as$row)$meta[$row['COLUMN_NAME']]=$row;
            self::assertTrue(AccountLogSourceLookup::compatible($meta['source_sn'],$meta['source_sn_lookup']));
            self::assertSame(80,(int)$meta['source_sn_lookup']['CHARACTER_MAXIMUM_LENGTH']);
            $meta['source_sn_lookup']['GENERATION_EXPRESSION']='upper(source_sn)';self::assertFalse(AccountLogSourceLookup::compatible($meta['source_sn'],$meta['source_sn_lookup']));
        }finally{foreach(array_reverse($tables)as$table)Db::execute('DROP TABLE IF EXISTS `'.$prefix.$table.'`');}
    }
}
