<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);});
use app\common\service\decorate\agent\{DecorationAgentCatalog as C,DecorationAgentDesignContext as D,DecorationAgentDecision as Decision,DecorationAgentOperations as O};
$checks=0;
function identityCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
foreach(C::summary()['components'] as $definition){
    $detail=D::contract($definition['name']);
    identityCheck(($detail['write_constraints']['component_id']['owner']??null)==='server',$definition['name'].': component ID ownership is discoverable');
    foreach($definition['lists'] as $field=>$contract){
        identityCheck(($detail['write_constraints']['list_row_ids'][$field]['owner']??null)==='server',$definition['name'].'.'.$field.': full row ID policy is discoverable');
        identityCheck(($detail['write_constraints']['list_row_ids'][$field]['existing_update']??null)==='edit_list:index',$definition['name'].'.'.$field.': existing rows use index');
    }
    identityCheck($detail['lists']===$definition['lists']&&$detail['defaults']===$definition['defaults'],'raw schema and defaults remain complete');
}
$compact=array_column(D::catalog(),null,'name');
identityCheck(($compact['banner']['lists']['data']['server_owned_id']??false)===true&&($compact['creation-entry-grid']['lists']['data']['server_owned_id']??false)===true,'automatic component summary warns about row IDs');
identityCheck(str_contains(D::guide()['identities']??'','edit_list')&&str_contains(Decision::prompt(),'所有列表行'),'automatic guide and decision prompt explain row identity');
identityCheck(str_contains(D::guide()['theme_defaults']??'','不会自动')&&str_contains(Decision::prompt(),'显式默认文字色'),'guide and prompt distinguish explicit factory colors from page inheritance');
$base=['data'=>[],'meta'=>[]];$scope=['scope'=>'page','approved_urls'=>['/resource/image/tenantapi/default/banner001.png']];
foreach(['banner'=>['image'=>'/resource/image/tenantapi/default/banner001.png'],'creation-entry-grid'=>['title'=>'图片创作','icon'=>'photo']] as $name=>$row){
    $invalid=['type'=>'add_component','name'=>$name,'content'=>['data'=>[['id'=>'model-owned']+$row]]];
    try{O::apply($base,[$invalid],$scope);throw new RuntimeException('model row ID accepted');}catch(InvalidArgumentException $error){identityCheck($error->getMessage()==='Row IDs are assigned by server','real failed provider shape remains rejected: '.$name);}
    $valid=O::apply($base,[['type'=>'add_component','name'=>$name,'ref'=>'example','content'=>['data'=>[$row]]]],$scope)['candidate'];
    identityCheck(count($valid['data'])===1&&array_intersect_key($valid['data'][0]['content']['data'][0],$row)===$row,'same display overrides without copied row ID are accepted: '.$name);
    $id=$valid['data'][0]['id'];$updated=O::apply($valid,[['type'=>'edit_list','widget_id'=>$id,'field'=>'data','action'=>'update','index'=>0,'fields'=>$row]],$scope)['candidate'];
    identityCheck($updated['data'][0]['id']===$id,'existing-row edit uses index and preserves component identity: '.$name);
    try{O::apply($valid,[['type'=>'update_component','widget_id'=>$id,'fields'=>['content.data'=>[['id'=>'copied-source-id']+$row]]]],$scope);throw new RuntimeException('copied row ID accepted');}catch(InvalidArgumentException $error){identityCheck($error->getMessage()==='Row IDs are assigned by server','whole-list replacement rejects copied IDs: '.$name);}
    $added=O::apply($valid,[['type'=>'edit_list','widget_id'=>$id,'field'=>'data','action'=>'add','item'=>$row]],$scope)['candidate'];
    $rowId=$added['data'][0]['content']['data'][1]['id']??'';
    identityCheck(is_string($rowId)&&$rowId!==''&&$rowId!=='model-owned','edit_list addition assigns a row ID on server: '.$name);
    $edited=O::apply($added,[['type'=>'edit_list','widget_id'=>$id,'field'=>'data','action'=>'update','index'=>1,'fields'=>$row]],$scope)['candidate'];
    identityCheck($edited['data'][0]['content']['data'][1]['id']===$rowId,'index update preserves server-owned row identity: '.$name);
}
echo "identity guidance: $checks checks passed (no DB/provider calls)\n";
