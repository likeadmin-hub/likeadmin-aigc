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
use app\common\service\app\UpstreamPricingService as Upstream;
use think\facade\Db;

$args=getopt('',['tenant-id:','catalogue','timeout:','known-documents','applications']);$tenant=(string)($args['tenant-id'] ?? '');
$known=array_key_exists('known-documents',$args);$applications=array_key_exists('applications',$args);
$catalogue=array_key_exists('catalogue',$args);$timeoutRaw=(string)($args['timeout'] ?? '60');
if (!preg_match('/^[1-9][0-9]*$/D',$tenant) || !preg_match('/^[1-9][0-9]*$/D',$timeoutRaw) || (int)$timeoutRaw>60) {
    fwrite(STDERR,"Usage: php tools/audit-upstream-generation-contracts.php --tenant-id=TENANT_ID [--catalogue] [--timeout=1..60] [--known-documents] [--applications]\n");exit(1);
}
$knownModels=[];$knownApplications=[];
if($known){
    $previous=json_decode(file_get_contents(dirname(__DIR__).'/tests/fixtures/market-platform-contract-flow-review-20261029.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($previous['models']as$m)if(($m['platform_identity_verified']??false)&&!empty($m['published_doc_slug']))
        $knownModels[$m['generation_type'].'|'.$m['model_code'].'|'.$m['channel_code']]=$m['published_doc_slug'];
    $guidance=json_decode(file_get_contents(dirname(__DIR__).'/tests/fixtures/market-legacy-published-guidance-review-20261029.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($guidance['application_entries']??[]as$a){parse_str(parse_url($a['url'],PHP_URL_QUERY)??'',$query);
        if(is_string($query['slug']??null))$knownApplications[$a['app_code'].'|'.$a['api_code']]=$query['slug'];}
}
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
        'source'=>$catalogue ? 'configured market GET /api/v1/models?type=image|video&detail=1; exact model/channel identity'
            : 'configured market developerDocDetail GET by exact saved upstream model/API ID first, then legacy tree/slug; exact response identity required',
        'known_document_fallback'=>$known?'previously verified published slugs; identity rechecked on every response':'disabled',
        'catalogue_reads'=>[],'models'=>[],'applications'=>[]];
    $remoteByIdentity=[];
    if ($catalogue) foreach (['image','video'] as $type) {
        try {
            $remote=Upstream::queryModels($type,true,(int)$timeoutRaw);
            $report['catalogue_reads'][$type]=['status'=>'read','rows'=>count($remote)];
            foreach ($remote as $model) {
                $key=$type.'|'.($model['model_code'] ?? $model['code'] ?? '').'|'.($model['channel_code'] ?? '');
                $remoteByIdentity[$key][]=$model;
            }
        } catch (\Throwable $e) { $report['catalogue_reads'][$type]=['status'=>'unavailable','error_type'=>get_class($e)]; }
        fwrite(STDERR,'Read '.$type.' catalogue: '.$report['catalogue_reads'][$type]['status'].PHP_EOL);
    }
    $options=[];
    foreach(['image','video']as$type)$options[$type]=Catalogue::options((int)$tenant,$type)['channels'];
    foreach (['image','video'] as $type) foreach ($options[$type] as $option) {
        if (($option['resource_type'] ?? '') !== 'model') continue;
        $product=Product::where('id',(int)$option['market_product_id'])->find()->toArray();
        $metadata=(new ReflectionMethod($type==='image'?Images::class:Videos::class,'metadata'))->invoke(null,$product);
        if (Native::modes($metadata) !== []) continue;
        $identity=['generation_type'=>$type,'model_code'=>$product['upstream_model_code'],'channel_code'=>$product['upstream_channel_code']];
        if ($catalogue) {
            $matches=$remoteByIdentity[$type.'|'.$identity['model_code'].'|'.$identity['channel_code']] ?? [];
            $bound=count($matches)===1;
            $row=$identity+['local_native_snapshot'=>'missing_or_changed','platform_identity_verified'=>$bound,
                'read_state'=>$bound ? 'read' : (count($matches)>1 ? 'ambiguous_identity' : 'missing_or_unavailable')];
            $row+=Review::catalogueData($metadata,$bound ? $matches[0] : []);
            $report['models'][]=$row;
            continue;
        }
        $upstream=(array)($metadata['upstream_metadata'] ?? []);
        // Reuse exact model-ID/document binding and its bounded, credential-safe GET transport.
        $lookup=null;
        $detail=(new ReflectionMethod(Market::class,'developerDocDetailForModel'))->invokeArgs(null,[$upstream,&$lookup]);
        $knownSlug=$knownModels[$type.'|'.$identity['model_code'].'|'.$identity['channel_code']]??'';
        if($detail===[]&&$knownSlug!==''&&Identity::hasConsistentModelIdentity($upstream))try{
            $candidate=Upstream::queryDeveloperDocDetail($knownSlug);
            if(Identity::matchesDetail($upstream,$candidate)){$detail=$candidate;$lookup='verified_prior_slug';}
            else $lookup='known_slug_identity_mismatch';
        }catch(\Throwable){$lookup='known_slug_unavailable';}
        $pricing=(array)($detail['model_pricing'] ?? []);
        $modelId=Identity::modelId($upstream);
        $returnedCode=(string)($pricing['model_code'] ?? '');
        $bound=$returnedCode === $identity['model_code'] && Identity::matchesDetail($upstream,$detail);
        $row=$identity+['local_native_snapshot'=>'missing_or_changed','platform_identity_verified'=>$bound,'lookup_strategy'=>$lookup,
            'upstream_model_id'=>$modelId ?: null,'document_id_source'=>$modelId>0?'saved_upstream_model_metadata':'unavailable'];
        $row+=Review::platformData($metadata,$bound ? $detail : []);
        $slug=(string)($detail['doc']['slug'] ?? '');
        if ($slug !== '') $row['published_doc_slug']=$slug;
        if ($modelId===0) $row['read_state']='missing_model_document_binding';
        elseif ($lookup==='conflicting_model_document_binding') $row['read_state']='conflicting_model_document_binding';
        elseif ($detail === []) $row['read_state']=str_contains((string)$lookup,'identity_mismatch')?'identity_mismatch':'unavailable_or_unpublished';
        elseif (!$bound) $row['read_state']='identity_mismatch';
        else $row['read_state']='read';
        $report['models'][]=$row;
        fwrite(STDERR,'Reviewed '.count($report['models']).' model declarations'.PHP_EOL);
    }
    if($applications){$seen=[];foreach(['image','video']as$type)foreach($options[$type]as$option){
        if(($option['resource_type']??'')!=='app_api')continue;
        $product=Product::find((int)$option['market_product_id'])->toArray();
        $identity=['app_code'=>$product['upstream_app_code'],'api_code'=>$product['upstream_api_code']];
        $key=$identity['app_code'].'|'.$identity['api_code'];if(isset($seen[$key]))continue;$seen[$key]=true;
        $metadata=(new ReflectionMethod($type==='image'?Images::class:Videos::class,'metadata'))->invoke(null,$product);
        // Only a saved upstream API row inside the same saved app/code context can supply an API ID.
        $savedApp=(array)($metadata['upstream_app_metadata']??[]);$savedApi=(array)($metadata['upstream_api_metadata']??[]);
        $apiId=Identity::savedAppApiId($metadata,$identity['app_code'],$identity['api_code']);
        $hasSavedId=isset($savedApi['ai_app_api_id'])||isset($savedApi['api_id'])||isset($savedApi['id']);
        $conflict=Identity::hasConflictingSavedAppApiContext($metadata,$identity['app_code'],$identity['api_code'])
            ||(($savedApp['code']??null)===$identity['app_code']&&($savedApi['code']??null)===$identity['api_code']&&$hasSavedId&&$apiId===0);
        $lookup='conflicting_saved_api_identity';$detail=[];
        if(!$conflict){
            if($apiId>0)$identity['api_id']=$apiId;
            $detail=(new ReflectionMethod(Market::class,'developerDocDetailForAppApi'))->invokeArgs(null,[$identity['app_code'],$identity['api_code'],$apiId,&$lookup]);
            if($detail===[]&&isset($knownApplications[$key]))try{
                $candidate=Upstream::queryDeveloperDocDetail($knownApplications[$key]);
                if(Identity::matchesApplicationDetail($identity,$candidate)){$detail=$candidate;$lookup='verified_prior_slug';}
                else $lookup='known_slug_identity_mismatch';
            }catch(\Throwable){$lookup='known_slug_unavailable';}
        }
        $bound=Identity::matchesApplicationDetail($identity,$detail);
        $report['applications'][]=$identity+['resource_type'=>'app_api','generation_type'=>$type,'platform_identity_verified'=>$bound,
            'lookup_strategy'=>$lookup,'upstream_api_id'=>$apiId ?: null,
            'document_id_source'=>$apiId>0?'exact_saved_application_api_metadata':'unavailable',
            'read_state'=>$bound?'read':($conflict?'conflicting_saved_api_identity':'unavailable_or_identity_mismatch')]+Review::applicationData($metadata,$bound?$detail:[]);
        fwrite(STDERR,'Reviewed '.count($report['applications']).' application API declarations'.PHP_EOL);
    }}
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} finally { Db::rollback(); }
