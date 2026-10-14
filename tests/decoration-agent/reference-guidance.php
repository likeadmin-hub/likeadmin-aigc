<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);});
use app\common\service\decorate\agent\{DecorationAgentCatalog as C,DecorationAgentDesignContext as D,DecorationAgentDecision as Decision,DecorationAgentOperations as O};
$checks=0;
function referenceCheck(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$guide=D::guide();$prompt=Decision::prompt();
foreach(C::summary()['components'] as $definition){
    $alias=D::contract($definition['name'])['write_constraints']['component_id']['alias_support']??[];
    referenceCheck(($alias['operation']??null)==='add_component'&&($alias['target']??null)==='operation root component'&&($alias['unsupported']??[])===['replace_page.components','children'],'detail contract exposes exact ref support: '.$definition['name']);
}
referenceCheck(str_contains($guide['references']??'','component_index')&&str_contains($prompt,'replace_page.components'),'automatic context and prompt guide bulk replacement to real IDs');
$meta=C::pageMeta()['defaults'];$base=['data'=>[],'meta'=>[$meta]];
$replaced=O::apply($base,[['type'=>'replace_page','components'=>[['name'=>'creation-entry-grid','ref'=>'unsupported-bulk-alias','content'=>['columns'=>2]]]]],['scope'=>'page'])['candidate'];
$index=O::index($replaced['data']);$id=array_key_first($index);
referenceCheck($id!=='unsupported-bulk-alias'&&!isset($index['@unsupported-bulk-alias']),'bulk replacement does not create a usable component alias');
$bound=O::apply($replaced,[['type'=>'bind_source','widget_id'=>$id,'source_key'=>'app_entries','mode'=>'source','params'=>['terminal'=>'uniapp']]],['scope'=>'page'])['candidate'];
referenceCheck($bound['data'][0]['id']===$id&&$bound['data'][0]['content']['source_key']==='app_entries','binding after bulk replacement uses actual component index ID');
echo "reference guidance: $checks checks passed (no DB/provider calls)\n";
