<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\FileService;
use app\common\service\storage\StorageConfigService;
use app\common\service\storage\StorageSignedUrlService;
use think\facade\Db;

// This test uses Qiniu's SDK signing locally with inert credentials and an
// invalid fixture domain. It opens no network connection and cannot read a
// real bucket; the behavior under test is server-side re-signing from a stable
// URI, rather than reusing an expired browser URL.
Db::execute('CREATE TABLE IF NOT EXISTS `la_tenant` (`id` int unsigned NOT NULL, `allow_custom_storage` tinyint NOT NULL DEFAULT 0, `allow_local_storage` tinyint NOT NULL DEFAULT 1, PRIMARY KEY (`id`)) ENGINE=InnoDB');
Db::execute('CREATE TABLE IF NOT EXISTS `la_tenant_config` (`id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `type` varchar(40) NOT NULL, `name` varchar(80) NOT NULL, `value` longtext, PRIMARY KEY (`id`), UNIQUE KEY `uniq_config` (`tenant_id`,`type`,`name`)) ENGINE=InnoDB');

$tenant=91003;
Db::name('tenant')->where('id',$tenant)->delete();
Db::name('tenant')->insert(['id'=>$tenant,'allow_custom_storage'=>1,'allow_local_storage'=>1]);
foreach ([
    'enable'=>1,
    'default'=>'qiniu',
    'qiniu'=>['bucket'=>'private-fixture','access_key'=>'fixture-access','secret_key'=>'fixture-secret','domain'=>'https://private-fixture.invalid','private_access'=>true],
] as $name=>$value) {
    Db::name('tenant_config')->where(['tenant_id'=>$tenant,'type'=>'storage','name'=>$name])->delete();
    Db::name('tenant_config')->insert(['tenant_id'=>$tenant,'type'=>'storage','name'=>$name,'value'=>json_encode($value,JSON_UNESCAPED_SLASHES)]);
}
StorageConfigService::clearCache($tenant);

$uri='uploads/aigc_video/private-fixture.mp4';
$first=StorageSignedUrlService::resolve($uri,'tenant','qiniu','https://private-fixture.invalid',$tenant);
agentCheck(is_string($first) && str_starts_with($first,'https://private-fixture.invalid/'.$uri.'?e=') && str_contains($first,'&token='),'M09 private Qiniu URI resolves to a signed browser URL without network I/O');
parse_str((string)parse_url((string)$first,PHP_URL_QUERY),$firstQuery);
sleep(1);
$second=StorageSignedUrlService::resolve($uri,'tenant','qiniu','https://private-fixture.invalid',$tenant);
parse_str((string)parse_url((string)$second,PHP_URL_QUERY),$secondQuery);
agentCheck((int)($secondQuery['e']??0)>(int)($firstQuery['e']??0) && $first!==$second,'M09 refresh creates a new private signature from the stable stored URI');

request()->tenantId=$tenant;
$viaFile=FileService::getFileUrlByStorage($uri,'tenant','qiniu','https://private-fixture.invalid');
agentCheck(str_starts_with($viaFile,'https://private-fixture.invalid/'.$uri.'?e=') && str_contains($viaFile,'&token='),'M09 canvas result resolver uses signed storage delivery rather than a raw object URL');

Db::name('tenant_config')->where(['tenant_id'=>$tenant,'type'=>'storage','name'=>'qiniu'])->update(['value'=>json_encode(['bucket'=>'private-fixture','access_key'=>'fixture-access','secret_key'=>'fixture-secret','domain'=>'https://private-fixture.invalid','private_access'=>false],JSON_UNESCAPED_SLASHES)]);
StorageConfigService::clearCache($tenant);
agentCheck(StorageSignedUrlService::resolve($uri,'tenant','qiniu','https://private-fixture.invalid',$tenant)===null,'public storage remains on the established direct-URL path');
echo "NOT_RUN real private bucket HTTP fetch; signing uses the real SDK with inert local fixture credentials only\n";
