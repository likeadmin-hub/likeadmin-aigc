<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use app\common\service\decorate\agent\{DecorationAgentCatalog as C,DecorationAgentDesignContext as D,DecorationAgentOperations as O,DecorationAgentService as S};
$n=0;function listCheck(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;}
$widget=C::create('app-collection','apps');$original=$widget;
$state=D::listState($widget);
listCheck($state['source_graphics']['persisted_rows']===0&&!$state['source_graphics']['configured'],'optional override list is absent despite live source entries');
listCheck($state['source_graphics']['next_insert_index']===0,'new overrides begin at actual saved index0');
listCheck($widget===$original,'model-facing state never backfills optional lists');
$page=['name'=>'List fixture','data'=>[$widget],'meta'=>[]];
try{O::apply($page,[['type'=>'edit_list','widget_id'=>'apps','field'=>'source_graphics','action'=>'update','index'=>0,'fields'=>['mode'=>'icon']]],['scope'=>'page']);throw new RuntimeException('updated nonexistent override');}
catch(InvalidArgumentException $e){listCheck(str_contains($e->getMessage(),'source_graphics')&&str_contains($e->getMessage(),'0 persisted rows')&&str_contains($e->getMessage(),'action=add'),'error identifies the real empty list and safe add action');}
$options=C::definition('app-collection')['lists']['source_graphics']['fields']['icon']['options'];$icon='';foreach($options as $v)if(($v['value']??'')!==''){$icon=$v['value'];break;}
$result=O::apply($page,[['type'=>'edit_list','widget_id'=>'apps','field'=>'source_graphics','action'=>'add','item'=>['app_code'=>'aigc_music','entry_key'=>'aigc_music','mode'=>'icon','icon'=>$icon,'image'=>'']]],['scope'=>'page']);
$saved=$result['candidate']['data'][0];$state=D::listState($saved);
listCheck($state['source_graphics']['persisted_rows']===1&&$state['source_graphics']['last_existing_index']===0,'add creates a real row usable by subsequent updates');
listCheck(!empty($saved['content']['source_graphics'][0]['id']),'row identity remains server assigned');
$result=O::apply($result['candidate'],[['type'=>'edit_list','widget_id'=>'apps','field'=>'source_graphics','action'=>'update','index'=>0,'fields'=>['mode'=>'source']]],['scope'=>'page']);
listCheck($result['candidate']['data'][0]['content']['source_graphics'][0]['mode']==='source','existing row update still works');
$method=new ReflectionMethod(S::class,'componentIndex');$index=$method->invoke(null,[$saved]);
listCheck($index[0]['lists']===$state,'real model component index includes exact persisted list state');
foreach(C::summary()['components'] as $d){$w=C::create($d['name'],'fixture');$before=$w;$state=D::listState($w);foreach($d['lists']??[] as $field=>$schema)listCheck($state[$field]['persisted_rows']===count($w['content'][$field]??[]),'all component lists describe stored rows: '.$d['name'].'.'.$field);listCheck($w===$before,'all catalog defaults stay untouched: '.$d['name']);}
echo "list-state: $n checks passed\n";
