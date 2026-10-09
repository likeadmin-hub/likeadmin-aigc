<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/short_drama_account_fixture.php';

use Tests\Fixtures\ShortDramaAccountFixture as Fixture;
use think\facade\Db;

if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1' || !preg_match('/^account_[a-f0-9]{16}$/D',$argv[1]??'')) exit(2);
(new \think\App())->initialize();
$prefix=$argv[1]; $user=(int)($argv[2]??0); $key=$argv[3]??''; $mode=$argv[4]??'';
if ($user<1 || !preg_match('/^[0-7]$/D',$key) || !in_array($mode,['reserve','submit','prepared_pause'],true)
    || !Db::name('tenant')->where(['id'=>2000000816,'sn'=>$prefix])->count()
    || !Db::name('user')->where(['id'=>$user,'tenant_id'=>2000000816,'nickname'=>$prefix])->count()) exit(2);
Db::execute('SET SESSION innodb_lock_wait_timeout = 3');
try {
    $context=Fixture::reserve(2000000816,$user,$prefix.'_'.$key);
    if ($mode==='prepared_pause') {
        fwrite(STDOUT,'prepared_saved:'.(int)$context['consumption']['id']."\n");fflush(STDOUT);
        fgets(STDIN); // The test kills this process before it can write the submit marker.
        Fixture::call('markAccountSubmission',$context);
    }
    if ($mode==='submit') Fixture::call('markAccountSubmission',$context);
    fwrite(STDOUT,$mode.':'.(int)$context['consumption']['id']."\n");
} catch (\RuntimeException $error) {
    if (!in_array($error->getCode(),[409,422],true)) throw $error;
    fwrite(STDOUT,'rejected:'.$error->getCode()."\n");
}
