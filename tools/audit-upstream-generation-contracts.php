<?php
/** Read platform metadata through the existing GET detail APIs. Never syncs products/prices, uploads or submits tasks. */
require dirname(__DIR__).'/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();
use app\common\model\power\PowerMarketProduct as Product;
use app\common\model\update\UpdateSource;
use app\common\service\power\MarketGenerationCatalogService as Catalogue;
use app\common\service\power\MarketGenerationContractReview as Review;
use app\common\service\power\MarketGenerationNativeContract as Native;
use app\common\service\power\MarketGenerationSourceIdentity as Identity;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use app\common\service\power\PowerMarketService as Market;
use think\facade\Db;

$args=getopt('',['tenant-id:']);$tenant=(string)($args['tenant-id'] ?? '');
if (!preg_match('/^[1-9][0-9]*$/D',$tenant)) {fwrite(STDERR,"Usage: php tools/audit-upstream-generation-contracts.php --tenant-id=TENANT_ID\n");exit(1);}
// getSource can repair old schemas; refuse before entering that path in a read-only audit.
$table=(new UpdateSource())->db()->getTable();$columns=Db::query('SHOW COLUMNS FROM `'.str_replace('`','``',$table).'`');
$fields=array_column($columns,'Field');
foreach (['online_base_url','online_license_key','dev_mode','ssl_verify'] as $key) {
    if (!in_array($key,$fields,true)) {fwrite(STDERR,"Source configuration schema requires separate maintenance; no changes made\n");exit(1);}
}
foreach ($columns as $column) if ($column['Field']==='dev_mode' && (string)$column['Default']!=='0') {
    fwrite(STDERR,"Source configuration schema requires separate maintenance; no changes made\n");exit(1);
}
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try {
    $report=['tenant_id'=>(int)$tenant,'observed_at'=>date(DATE_ATOM),
        'scope'=>'Platform GET detail versus saved machine metadata for missing/changed local native contracts; no sync, task, quote, upload or point writes',
        'source'=>'configured market developerDocTree/developerDocDetail GET APIs; same resolver as production synchronization','models'=>[]];
    foreach (['image','video'] as $type) foreach (Catalogue::options((int)$tenant,$type)['channels'] as $option) {
        if (($option['resource_type'] ?? '') !== 'model') continue;
        $product=Product::where('id',(int)$option['market_product_id'])->find()->toArray();
        $metadata=(new ReflectionMethod($type==='image'?Images::class:Videos::class,'metadata'))->invoke(null,$product);
        if (Native::modes($metadata) !== []) continue;
        $identity=['generation_type'=>$type,'model_code'=>$product['upstream_model_code'],'channel_code'=>$product['upstream_channel_code']];
        $upstream=(array)($metadata['upstream_metadata'] ?? []);
        // Reuse exact model-ID/document binding and its bounded, credential-safe GET transport.
        $detail=(new ReflectionMethod(Market::class,'developerDocDetailForModel'))->invoke(null,$upstream);
        $pricing=(array)($detail['model_pricing'] ?? []);
        $modelId=Identity::modelId($upstream);
        $returnedCode=(string)($pricing['model_code'] ?? '');
        $returnedId=(int)($pricing['model_id'] ?? 0);
        $bound=$returnedCode === $identity['model_code'] && $modelId>0 && $returnedId === $modelId;
        $row=$identity+['local_native_snapshot'=>'missing_or_changed','platform_identity_verified'=>$bound];
        $row+=Review::platformData($metadata,$bound ? $detail : []);
        $slug=(string)($detail['doc']['slug'] ?? '');
        if ($slug !== '') $row['published_doc_slug']=$slug;
        if ($modelId===0) $row['read_state']='missing_model_document_binding';
        elseif ($detail === []) $row['read_state']='unavailable_or_unpublished';
        elseif (!$bound) $row['read_state']='identity_mismatch';
        else $row['read_state']='read';
        $report['models'][]=$row;
        fwrite(STDERR,'Reviewed '.count($report['models']).' model declarations'.PHP_EOL);
    }
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} finally { Db::rollback(); }
