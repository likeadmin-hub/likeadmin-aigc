<?php
/** Whole JSON instances only; --pure does not initialize an app or access a database. */
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\open_platform\{OpenContracts as C,OpenVerification as V,OpenPolicy as P,OpenStore as S,OpenManagement as M,OpenCatalog as Catalog,OpenDocuments as D};
use think\facade\Db;
function checkExampleType(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function exampleJson($value): string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
/** The decoder used before typed response instances were restored. */
function originalDigest(array $row): string {
    $config=$row['config'];
    if(is_string($config)){$typed=json_decode($config);$config=json_decode($config,true);if(isset($typed->example)&&$typed->example instanceof \stdClass)$config['example']=(array)$typed->example;}
    unset($config['verification']);
    return P::fingerprint(['kind'=>$row['kind'],'method'=>$row['method'],'path'=>$row['path'],'product_id'=>(int)($row['product_id']??0),'config'=>$config]);
}
function checkInstances(array $config,array $expected): void {
    foreach(['responses','query_responses'] as $field)foreach($expected[$field] as $status=>$response)foreach($response['content'] as $type=>$media){
        $actual=$config[$field][$status]['content'][$type];
        if(array_key_exists('example',$media))checkExampleType(array_key_exists('example',$actual)&&exampleJson($actual['example'])===exampleJson($media['example']),$field.' '.$status.' '.$type.' single example changed');
        foreach($media['examples']??[] as $name=>$example)checkExampleType(array_key_exists('value',$actual['examples'][$name])&&exampleJson($actual['examples'][$name]['value'])===exampleJson($example['value']),$field.' '.$status.' '.$type.' '.$name.' value changed');
    }
}
$values=json_decode('{"object":{},"list":[],"nested":{"object":{},"list":[],"rows":[{},[],false,0,null,0.0]},"numeric_object":{"0":"zero"},"false":false,"zero":0,"null":null,"fraction":0.0,"string":"0"}');
$examples=[];foreach(get_object_vars($values) as $name=>$value)$examples[$name]=['summary'=>'Synthetic JSON type fixture','value'=>$value];
$responses=[];
foreach(['200'=>$values->nested,'201'=>null,'202'=>false,'203'=>0,'204'=>0.0] as $status=>$value)$responses[$status]=['description'=>'Synthetic response','content'=>['application/json'=>['schema'=>[],'example'=>$value,'examples'=>$examples],'application/problem+json'=>['example'=>[],'examples'=>['object'=>['value'=>new \stdClass()]]]]];
$config=['executor'=>'json','role'=>'submit','billable'=>false,'input'=>['type'=>'object','properties'=>['nested'=>['type'=>'object']]],'example'=>['nested'=>new \stdClass()],'responses'=>$responses,'query_responses'=>$responses];
$input=['kind'=>'market','code'=>'typed_examples_fixture','product_id'=>0,'method'=>'POST','path'=>'/api/v1/apps/typed_example_fixture/output','config'=>$config];
$row=C::validate($input);$legacyDigest=originalDigest($row);$decoded=C::decodeConfig($row['config']);checkInstances($decoded,$config);
checkExampleType($decoded['example']['nested'] instanceof \stdClass,'Request instance compatibility lost');
checkExampleType(V::digest($row)===$legacyDigest&&V::digest(array_replace($row,['config'=>$decoded]))===$legacyDigest,'Restored examples changed historical serialized/detail fingerprint');
checkExampleType(V::digest(array_replace($row,['config'=>C::legacyConfig($row['config'])]))===$legacyDigest,'Historical array fingerprint changed');
$decoded['verification']=['request_id'=>'synthetic-serialization-only'];
$encoded=C::encodeConfig($decoded);checkInstances(C::decodeConfig($encoded),$config);
checkExampleType(str_contains($encoded,'"fraction":{"summary":"Synthetic JSON type fixture","value":0.0}')&&V::digest(array_replace($row,['config'=>$encoded]))===$legacyDigest,'Publish serialization lost float zero or changed verification-independent fingerprint');
$raw=C::encodeConfig($input);
$request=new class($raw){public function __construct(private string $raw){}public function post(): array{return json_decode($this->raw,true);}public function getInput(): string{return $this->raw;}};
$restored=C::requestInput($request);checkInstances($restored['config'],$config);checkInstances(C::decodeConfig(C::validate($restored)['config']),$config);
$alreadyList=C::decodeConfig('{"responses":{"200":{"content":{"application/json":{"example":[],"examples":{"existing":{"value":[]}}}}}}}');
checkExampleType($alreadyList['responses'][200]['content']['application/json']['example']===[]&&$alreadyList['responses'][200]['content']['application/json']['examples']['existing']['value']===[],'Decoder guessed an object for an existing list');
foreach(['{"responses":[]}','{"responses":{"200":{"content":[]}}}','{"responses":{"200":{"content":{"application/json":null}}}}'] as $malformed)checkExampleType(is_array(C::decodeConfig($malformed)),'Decoder changed malformed-map tolerance');
if(in_array('--pure',$argv,true)){echo "PASS typed response/query instances, null/false/zero/float zero, raw request graft and unchanged historical fingerprints; no DB/transport\n";exit;}
(new think\App())->initialize();
$db=config('database.connections.mysql');if($db['hostname']!=='127.0.0.1'||$db['database']!=='z_cn')throw new RuntimeException('Local transactional fixture only');
$published=S::table('contract')->where('status','published')->order('id')->select()->toArray();$before=[];
foreach($published as $existing){$before[$existing['id']]=[hash('sha256',$existing['config']),originalDigest($existing)];checkExampleType(V::digest($existing)===$before[$existing['id']][1]&&V::digest(C::detail((int)$existing['id']))===$before[$existing['id']][1],'Existing published receipt fingerprint changed');}
Db::startTrans();try{
    $input['code']='typed_examples_'.bin2hex(random_bytes(5));$saved=C::save($input);checkInstances($saved['config'],$config);$id=(int)$saved['id'];$stored=S::table('contract')->where('id',$id)->find();$hash=hash('sha256',$stored['config']);
    checkExampleType($saved['status']==='draft'&&V::digest($saved)===originalDigest($stored),'Draft detail changed historical fingerprint');
    $saved=C::save($saved);checkInstances(C::detail($id)['config'],$config);checkExampleType(hash('sha256',S::table('contract')->where('id',$id)->value('config'))===$hash&&$saved['version']===1,'Save/detail roundtrip changed stored bytes or version');
    // Isolated reader fixture only: no publish method, documents, receipt, settings or transport.
    S::table('contract')->where('id',$id)->update(['status'=>'published']);
    $tenantRow=null;foreach(M::contractsForTenant(1) as $candidate)if((int)$candidate['id']===$id)$tenantRow=$candidate;
    checkExampleType($tenantRow!==null,'Synthetic public contract missing from management');checkInstances(C::decodeConfig($tenantRow['config']),$config);
    $catalogRow=null;foreach(Catalog::forUser(1,1) as $candidate)if(($candidate['code']??'')===$input['code'])$catalogRow=$candidate;
    checkExampleType($catalogRow!==null,'Synthetic contract missing from catalog');checkInstances(['responses'=>$catalogRow['responses'],'query_responses'=>$config['query_responses']],$config);
    $paths=(array)D::openapi(1)['paths'];$operation=$paths[$input['path']]['post'];checkInstances(['responses'=>$operation['responses'],'query_responses'=>$config['query_responses']],$config);
    checkExampleType(hash('sha256',S::table('contract')->where('id',$id)->value('config'))===$hash,'Public views mutated the stored contract');
}finally{Db::rollback();}
checkExampleType(!S::table('contract')->where('id',$id)->find(),'Synthetic contract escaped rollback');
foreach($before as $existingId=>$expected){$existing=S::table('contract')->where('id',$existingId)->find();checkExampleType($existing&&hash('sha256',$existing['config'])===$expected[0]&&V::digest($existing)===$expected[1],'Existing published raw config or digest changed');}
echo "PASS typed response examples through save/detail/management/catalog/OpenAPI, stable legacy receipt fingerprints and raw stored bytes; isolated rollback, no publication/transport\n";
