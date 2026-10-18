<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\WorkspaceAssetService as W;
$db = config('database.connections.mysql');
if (PHP_SAPI !== 'cli' || $db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn','z_cn'], true)) throw new RuntimeException('Local rollback only');
$sources = (new ReflectionClass(W::class))->getConstant('SOURCES');
$owner = ['tenant_id'=>1999999753,'user_id'=>1999999753];
$checks = 0;
Db::startTrans();
try {
    foreach ($sources as $source => $app) {
        if (in_array($source,['canvas','short_drama','fitting'],true)) continue; // Dedicated asset/shared-result contracts.
        $task = Db::name($app.'_task')->insertGetId($owner+['status'=>'success','create_time'=>time(),'delete_time'=>0]);
        $columns = Db::name($app.'_result')->getTableFields();
        $field = array_values(array_intersect(['video_uri','image_uri','audio_uri'], $columns))[0] ?? '';
        if (!$field) throw new RuntimeException('Unmapped output column: '.$source);
        $url = 'https://fixtures.invalid/'.$source.'.'.($field === 'image_uri' ? 'png' : ($field === 'video_uri' ? 'mp4' : 'mp3'));
        $result = Db::name($app.'_result')->insertGetId($owner+['task_id'=>$task,$field=>$url,'create_time'=>time(),'delete_time'=>0]);
        $ref = $source.':result:'.$result;
        W::update($owner['tenant_id'],$owner['user_id'],['refs'=>[$ref],'favorite'=>1]);
        $items = array_values(array_filter(W::read($owner['tenant_id'],$owner['user_id'])['supplemental'],fn($item)=>$item['ref']===$ref));
        if (count($items)!==1 || $items[0]['source']!==$source || $items[0]['raw'][str_replace('_uri','_url',$field)]!==$url) throw new RuntimeException('Source/output mismatch: '.$source);
        try { W::update($owner['tenant_id']+1,$owner['user_id'],['refs'=>[$ref],'favorite'=>1]); throw new RuntimeException('Cross-tenant source accepted: '.$source); }
        catch (InvalidArgumentException $e) {}
        $checks++;
    }
    echo "PASS $checks independent tool result tables, URLs, favorite identities and tenant boundaries; rolled back\n";
} finally { Db::rollback(); }
