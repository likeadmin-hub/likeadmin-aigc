<?php
/** Regenerate reviewed native contracts from an audit export and the authoritative provider contract checkout.
 * Usage: php build-generation-contracts.php audit-runtime.json upstream-server output.json
 * No database, credential, pricing, upload or provider request is used.
 */
if ($argc !== 4) throw new InvalidArgumentException('Expected audit JSON, upstream server directory, output JSON');
$upstream = rtrim($argv[2], '/');
spl_autoload_register(static function ($class) use ($upstream): void {
    $prefix='app\\common\\service\\ai\\official\\';
    if(str_starts_with($class,$prefix))require_once $upstream.'/app/common/service/ai/official/'.substr($class,strlen($prefix)).'.php';
});
use app\common\service\ai\official\OfficialCatalogService as C;
use app\common\service\ai\official\OfficialGenerationGroupService as G;
use app\common\service\ai\official\OfficialRequestService as R;
use app\common\service\ai\official\OfficialMaterialContractService as M;
function canonical($value){if(!is_array($value))return $value;foreach($value as &$child)$child=canonical($child);unset($child);if(!array_is_list($value))ksort($value);return $value;}
function clean(array $schema):array{
    // Distribution contains business types/constraints only, never supplier examples, source IDs or HTTP transport.
    foreach(['example','examples','$id','$schema','description','title','x-kie'] as $key)unset($schema[$key]);
    foreach($schema as $key=>&$value)if(is_array($value)){if(in_array($key,['properties','$defs','definitions'],true)){foreach($value as &$child)if(is_array($child))$child=clean($child);unset($child);}else $value=clean($value);}unset($value);
    return $schema;
}
function admission(array $profile):array {
    $schema=clean(M::inputSchema($profile));$rules=M::rules($profile);$source=$profile['source_model'];
    // The upstream gateway deliberately rejects custom pixel dimensions even where
    // a supplier schema still mentions them. Mirror the executable gateway contract.
    unset($schema['properties']['width'],$schema['properties']['height']);
    if(str_contains($source,'seedance') && isset($schema['properties']['duration'])) {
        $schema['properties']['duration']['minimum']=1;
        if(isset($schema['properties']['duration']['enum']))$schema['properties']['duration']['enum']=array_values(array_filter($schema['properties']['duration']['enum'],static fn($value)=>(float)$value>0));
    }
    if($source==='grok-imagine/text-to-video')$schema['properties']['duration']=array_replace($schema['properties']['duration'],['minimum'=>6,'maximum'=>30]);
    if(str_starts_with($source,'kling-3.0-omni/'))$rules[]=['kind'=>'kling3_shots','message'=>'自定义分镜需要提供分镜，不能与智能分镜同时开启，分镜总时长不能超过15秒'];
    return ['schema'=>$schema,'rules'=>$rules];
}
$profiles=[];foreach(C::catalog()['models'] as $p)$profiles[C::modelCode(33,$p['source_model'])]=$p;
$contracts=[];foreach(json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)['rows'] as $row){
 $product=$row['product'];$meta=$product['source_payload']['market_metadata'];$profile=$profiles[$product['upstream_model_code']]??null;
 if(!$profile)continue;
 $public=$meta['upstream_metadata']['model_code']??'';if($public==='')continue;
 $modes=[];$group=G::forSource($profile['source_model']);
 if($group){foreach($group['modes'] as $mode=>$def){$native=R::profile($def['source_model']);$modes[$mode]=admission($native);}}
 else $modes['']=admission($profile);
 $fingerprint=hash('sha256',json_encode(canonical([$meta['params_schema']??[],$meta['upstream_metadata']['material_capabilities']??[]]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
 $contracts[$public]=['fingerprint'=>$fingerprint,'modes'=>$modes];
}
ksort($contracts);file_put_contents($argv[3],json_encode(['revision'=>'2026-10-25','models'=>$contracts],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'Exported '.count($contracts)." native model contracts\n";
