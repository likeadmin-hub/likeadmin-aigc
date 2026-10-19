<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use app\common\service\decorate\agent\DecorationAgentOperations as O;
use app\common\service\decorate\agent\DecorationAgentCatalog as C;
$n=0;function checkCreation($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function rejectsCreation(callable $fn,$label){try{$fn();throw new RuntimeException('accepted invalid creation: '.$label);}catch(InvalidArgumentException $e){checkCreation(true,$label);}}
$fixtures=json_decode(file_get_contents(__DIR__.'/../fixtures/decoration-creation-sources.json'),true,512,JSON_THROW_ON_ERROR);
$allowedLinks=[['path'=>'/packages/pages/membership/membership'],['path'=>'/apps/aigc_image/pages/index/index','query'=>[]],['path'=>'/apps/aigc_image/pages/tasks/tasks','query'=>[]],['path'=>'/apps/aigc_image/pages/results/results','query'=>[]],['path'=>'/pages/customer_service/customer_service','query'=>[]]];
$noop=new ReflectionMethod(\app\common\service\decorate\agent\DecorationAgentService::class,'duplicateSourceBinding');$noop->setAccessible(true);
foreach($fixtures['cases'] as $fixture){
    $page=['data'=>[],'meta'=>[],'name'=>'fixture'];$ctx=['candidate'=>$page,'refs'=>[]];$charged=0;$skipped=0;
    O::validateCreationBindings($fixture['operations']);
    foreach($fixture['operations'] as $operation){
        if($noop->invoke(null,$ctx,$operation,['scope'=>'page'])){$skipped++;continue;}
        $charged++;$op=$operation;
        foreach(['widget_id','parent_id'] as $target)if(isset($op[$target])&&str_starts_with($op[$target],'@'))$op[$target]=$ctx['refs'][substr($op[$target],1)];
        $result=O::apply($ctx['candidate'],[$op],['scope'=>'page','allowed_links'=>$allowedLinks]);$ctx['candidate']=$result['candidate'];
        if($op['type']==='add_component'&&!empty($op['ref'])){$change=end($result['changes']);$ctx['refs'][$op['ref']]=$change['widget_id'];}
    }
    $adds=array_values(array_filter($fixture['operations'],fn($op)=>$op['type']==='add_component'));
    checkCreation(count($ctx['candidate']['data'])===count($adds),$fixture['case'].' creates whole recorded structure');
    checkCreation($page['data']===[],'creation does not mutate original');
    checkCreation($skipped===($fixture['case']==='inspiration-learning'?2:3)&&$charged===count($adds)+1,'full sequence charges only additions and real source enrichment');
    foreach($adds as $i=>$op){$widget=$ctx['candidate']['data'][$i];checkCreation($widget['name']===$op['name']&&str_starts_with($widget['id'],'agent_'),'server assigns ID');$content=$op['content']??[];foreach(['data_mode','source_key'] as $field)if(array_key_exists($field,$content))checkCreation($widget['content'][$field]===$content[$field],'source declaration applied through bind validation');foreach($content['source_params']??[] as $key=>$value)checkCreation($widget['content']['source_params'][$key]===$value,'inline source parameter retained');}
    if($fixture['case']==='inspiration-learning')checkCreation($ctx['candidate']['data'][3]['content']['source_params']['sort']==='new','actual following bind safely enriches tutorial sorting');
}
$page=['data'=>[],'meta'=>[],'name'=>'fixture'];
$op=['type'=>'add_component','name'=>'tutorial-carousel','fields'=>['content.data_mode'=>'hybrid','content.source_key'=>'article','content.source_params'=>['limit'=>6]]];$result=O::apply($page,[$op],['scope'=>'page']);$widget=$result['candidate']['data'][0];$bind=['type'=>'bind_source','widget_id'=>$widget['id'],'source_key'=>'article','mode'=>'hybrid','params'=>['limit'=>6]];
checkCreation(O::isUnchangedBinding($result['candidate'],$bind,['scope'=>'page']),'explicit same following bind validated as no-op');$different=$bind;$different['params']['limit']=8;checkCreation(!O::isUnchangedBinding($result['candidate'],$different,['scope'=>'page']),'different bind remains real operation');
foreach([['source_key'=>'evil','data_mode'=>'source'],['source_key'=>'article','source_params'=>['limit'=>51]],['source_key'=>'membership_summary','source_params'=>['limit'=>6]],['field_map'=>['title'=>'evil']],['source_params'=>'bad']] as $content)rejectsCreation(fn()=>O::apply($page,[['type'=>'add_component','name'=>'news','content'=>$content]],['scope'=>'page']),'creation retains source/field guards');
rejectsCreation(fn()=>O::apply($result['candidate'],[['type'=>'update_component','widget_id'=>$widget['id'],'fields'=>['content.source_key'=>'article']]],['scope'=>'page']),'update source direct write still denied');
$invalid=$bind;$invalid['params']['unknown']=1;rejectsCreation(fn()=>O::isUnchangedBinding($result['candidate'],$invalid,['scope'=>'page']),'no-op check validates every source param');
rejectsCreation(fn()=>O::isUnchangedBinding($result['candidate'],$bind,['scope'=>'selected','selected_id'=>'other']),'no-op preserves selected scope');
rejectsCreation(fn()=>O::isUnchangedBinding($result['candidate'],$bind,['scope'=>'invalid']),'no-op rejects unknown scope just like apply');
$replacement=O::apply($page,[['type'=>'replace_page','components'=>[['name'=>'news','content'=>['source_key'=>'article','data_mode'=>'source','source_params'=>['limit'=>4]]]]]],['scope'=>'page']);checkCreation($replacement['candidate']['data'][0]['content']['source_params']['limit']===4,'replace creation shares guarded source normalization');
$inline=['type'=>'add_component','name'=>'tutorial-carousel','ref'=>'tutorials','content'=>['source_key'=>'article','data_mode'=>'hybrid','source_params'=>['limit'=>6]]];$following=$bind;$following['widget_id']='@tutorials';O::validateCreationBindings([$inline,$following]);checkCreation(true,'matching explicit following declaration accepted without an extra synthesized operation');
$conflict=$following;$conflict['params']['limit']=8;rejectsCreation(fn()=>O::validateCreationBindings([$inline,$conflict]),'conflicting explicit following source declaration rejected before edits');
$conflict=$following;$conflict['source_key']='image_cases';rejectsCreation(fn()=>O::validateCreationBindings([$inline,$conflict]),'explicit contradictory source rejected');
$conflict=$following;$conflict['mode']='source';rejectsCreation(fn()=>O::validateCreationBindings([$inline,$conflict]),'explicit contradictory mode rejected');
$conflict=$inline;$conflict['fields']=['content.source_key'=>'image_cases'];rejectsCreation(fn()=>O::apply($page,[$conflict],['scope'=>'page']),'conflicting content and fields source declarations rejected');
$noop=new ReflectionMethod(\app\common\service\decorate\agent\DecorationAgentService::class,'duplicateSourceBinding');$noop->setAccessible(true);$context=['candidate'=>$result['candidate'],'refs'=>['tutorials'=>$widget['id']]];
checkCreation($noop->invoke(null,$context,$following,['scope'=>'page']),'worker proves aliased duplicate before consuming tool budget');$different['widget_id']='@tutorials';checkCreation(!$noop->invoke(null,$context,$different,['scope'=>'page']),'worker retains real bind changes');
$manual=O::apply($page,[['type'=>'add_component','name'=>'case-feed','content'=>['data_mode'=>'manual']]],['scope'=>'page']);checkCreation($manual['candidate']['data'][0]['content']['data_mode']==='manual'&&$manual['candidate']['data'][0]['content']['source_key']==='image_cases','manual declaration keeps compatible dynamic default source without dropping mode');
$conflict=$inline;unset($conflict['ref']);$conflict['fields']=['content.source_key'=>'image_cases'];rejectsCreation(fn()=>O::validateCreationBindings([['type'=>'configure_page','fields'=>['title'=>'must not mutate']],$conflict]),'no-ref creation conflict detected in preflight before earlier page changes');
$literal='<div style="color:red">欢迎开始创作</div>';
$plain=O::apply($page,[['type'=>'add_component','name'=>'rich-text','content'=>['text'=>$literal]]],['scope'=>'page']);
checkCreation($plain['candidate']['data'][0]['content']['text']===$literal&&($plain['candidate']['data'][0]['content']['html']??'')==='','literal text remains text and is never promoted into HTML');
$safe='<h2>欢迎开始创作</h2><p>从文字、图片和视频创作开始。</p>';
$rich=O::apply($page,[['type'=>'add_component','name'=>'rich-text','content'=>['html'=>$safe],'styles'=>['color'=>'#334155']]],['scope'=>'page']);
checkCreation($rich['candidate']['data'][0]['content']['html']===$safe&&$rich['candidate']['data'][0]['styles']['color']==='#334155','exact safe formatted example uses HTML field and approved styles');
rejectsCreation(fn()=>O::apply($page,[['type'=>'add_component','name'=>'rich-text','content'=>['html'=>$literal]]],['scope'=>'page']),'inline CSS HTML remains forbidden');
// Real run45 failure shape: manual mode was incorrectly placed in update fields.
$existing=['data'=>[C::create('creation-entry-grid','existing_entry'),C::create('news','untouched')],'meta'=>[],'name'=>'fixture'];
$rows=[['title'=>'图片创作','description'=>'进入图片创作工具','link'=>['path'=>'/apps/aigc_image/pages/index/index','query'=>[]]]];
$manualBind=['type'=>'bind_source','widget_id'=>'existing_entry','mode'=>'manual','source_key'=>'','params'=>[]];
$manualData=['type'=>'update_component','widget_id'=>'existing_entry','fields'=>['content.data'=>$rows]];
$selected=['scope'=>'selected','selected_id'=>'existing_entry','allowed_links'=>$allowedLinks];
rejectsCreation(fn()=>O::apply($existing,[['type'=>'update_component','widget_id'=>'existing_entry','fields'=>['content.data_mode'=>'manual','content.data'=>$rows]]],$selected),'run45 combined direct manual-mode update remains forbidden');
$twoStep=O::apply($existing,[$manualBind,$manualData],$selected)['candidate'];
checkCreation($twoStep['data'][0]['content']['data_mode']==='manual'&&$twoStep['data'][0]['content']['source_key']===''&&$twoStep['data'][0]['content']['source_params']===[]&&$twoStep['data'][0]['content']['data'][0]['link']===$rows[0]['link'],'existing-widget bind then data update installs only the trusted real entry');
checkCreation($twoStep['data'][1]===$existing['data'][1]&&$existing['data'][0]['content']['data']===[],'manual conversion preserves outside selected scope and original snapshot');
$untrusted=$manualData;$untrusted['fields']['content.data'][0]['link']=['path'=>'https://invented.invalid'];rejectsCreation(fn()=>O::apply($existing,[$manualBind,$untrusted],$selected),'manual entry still enforces link allowlist');
rejectsCreation(fn()=>O::apply($existing,[$manualBind,$manualData],['scope'=>'selected','selected_id'=>'untouched','allowed_links'=>$allowedLinks]),'manual conversion still enforces selected ownership');
echo "creation sources: $n checks passed\n";
