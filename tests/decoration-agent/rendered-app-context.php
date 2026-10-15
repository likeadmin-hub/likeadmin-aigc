<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
use app\common\service\decorate\agent\{DecorationAgentCatalog as C,DecorationAgentDesignContext as D,DecorationAgentService as S,DecorationAgentOperations as O};
use app\common\service\decorate\DecorateDataSourceService as Sources;
use think\facade\Db;
$n=0;function displayCheck(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;}
$widget=C::create('app-collection','apps');$widget['content']['data_mode']='source';$widget['content']['source_key']='app_entries';
$rows=[];for($i=0;$i<26;$i++)$rows[]=['app_code'=>'app'.$i,'entry_key'=>'main','title'=>'App '.$i];$original=[$widget,$rows];
displayCheck(count(D::visibleAppEntries($widget,$rows))===8,'actual default limit8 is not confused with source total26');
$widget['content']['limit']=2;$rows[0]['is_show']='0';
displayCheck(array_column(D::visibleAppEntries($widget,$rows),'app_code')===['app1','app2'],'same hide-then-slice order as both renderers');
$widget['content']['limit']=0;displayCheck(count(D::visibleAppEntries($widget,$rows))===8,'legacy zero uses renderer fallback8');
$widget['content']['limit']='0';displayCheck(D::visibleAppEntries($widget,$rows)===[],'truthy string zero matches JavaScript slice0 instead of PHP falsy fallback');
$widget['content']['limit']=true;displayCheck(count(D::visibleAppEntries($widget,$rows))===1,'legacy true coerces to one as in the renderer');
$widget['content']['limit']=1;displayCheck(D::visibleAppEntries($widget,$rows)[0]===$rows[1],'original authoritative row identity is retained');
$widget['content']['data_mode']='manual';displayCheck(D::visibleAppEntries($widget,$rows)===[],'manual rows are not app source entries');
$widget['content']['data_mode']='hybrid';displayCheck(count(D::visibleAppEntries($widget,$rows))===1,'hybrid uses live source when nonempty');
$widget['content']['source_key']='ai_tools';displayCheck(D::visibleAppEntries($widget,$rows)===[],'different binding cannot borrow app entries');
$widget['content']['source_key']='';displayCheck(D::visibleAppEntries($widget,$rows)===[],'explicit empty source stays empty');
displayCheck(count($original[1])===26&&!isset($original[0]['content']['source_graphics']),'projection never populates overrides or truncates actual source');
$normalize=new ReflectionMethod(Sources::class,'normalizeWidgetRuntime');$reference=new ReflectionMethod(Sources::class,'widgetSourceRef');
foreach([
    ['source_key'=>'app_entries','source'=>['key'=>'other','params'=>['terminal'=>'pc']]],
    ['source'=>['key'=>'','params'=>['terminal'=>'pc']]],
    ['source'=>['key'=>'app_entries','params'=>['terminal'=>'uniapp']]],
    ['source_key'=>'','source'=>['key'=>'app_entries','params'=>['terminal'=>'pc']]],
    ['source_key'=>'app_entries','source_params'=>['terminal'=>'uniapp'],'source'=>['key'=>'app_entries','params'=>['terminal'=>'pc']]],
    ['source_key'=>'app_entries','source_params'=>null,'source'=>['key'=>'app_entries','params'=>['terminal'=>'pc']]],
] as $content){
    $legacy=['name'=>'app-collection','content'=>$content];$before=$legacy;$binding=D::appSourceBinding($legacy);
    $runtime=$reference->invoke(null,$normalize->invoke(null,$legacy));
    displayCheck(($runtime['key']??'')===$binding['key'],'binding key agrees with actual server normalizer');
    displayCheck($binding['params']===($runtime['params']??[]),'canonical and promoted legacy parameter authority agrees with actual runtime');
    displayCheck($legacy===$before,'normalization facts do not rewrite legacy payload');
    $legacy['content']['source_graphics']=[['app_code'=>'app0','entry_key'=>'main','mode'=>'image','image'=>'']];
    if($binding['key']==='app_entries')displayCheck(O::sourceGraphicTarget($legacy,'content.source_graphics.0.image',[$original[1][0]])['source_binding']===$binding,'paid target freezes the same canonical key/mode/params as runtime');
}
Db::execute('START TRANSACTION READ ONLY');try{
    request()->tenantId=1;$widget=$original[0];$widget['content']['source_params']=['terminal'=>'uniapp'];
    $actual=Sources::resolve('app_entries',['terminal'=>'uniapp'],1,['terminal'=>'uniapp']);
    $expected=D::visibleAppEntries($widget,$actual);$method=new ReflectionMethod(S::class,'applicationEntryContext');
    $facts=$method->invoke(null,1,['data'=>[$widget]]);
    displayCheck($facts[0]['visible_count']===count($expected)&&count($facts[0]['entries'])===count($expected),'real server model projection uses current displayed count, not catalog total');
    displayCheck(array_column($facts[0]['entries'],'app_code')===array_column($expected,'app_code'),'real projected tuples keep the exact source order');
    $legacy=$widget;unset($legacy['content']['source_params']);$legacy['content']['source']=['key'=>'other','params'=>['terminal'=>'pc']];
    displayCheck($method->invoke(null,1,['data'=>[$legacy]])[0]['entries']===$facts[0]['entries'],'canonical key without params cannot revive a stale PC source for model or paid targets');
    foreach($facts[0]['entries'] as $row)displayCheck(array_diff(array_keys($row),['app_code','entry_key','name','title'])===[],'model facts contain no storage URL or private user data');
}finally{Db::execute('ROLLBACK');}
echo "rendered-app-context: $n checks passed\n";
