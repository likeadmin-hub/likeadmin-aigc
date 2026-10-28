<?php
require __DIR__.'/lifecycle.php';
use think\facade\Db;
use app\common\service\app\aigc_copywriting\CopywritingStore as Store;
use app\common\service\app\aigc_copywriting\CopywritingPageService as Page;
use app\common\service\app\aigc_copywriting\CopywritingTaskService as Task;
use app\common\service\app\aigc_copywriting\CopywritingModelService as Models;
use app\common\service\decorate\DecorateDataSourceService as Sources;
Db::startTrans();
try {
    $tool=Store::table('tool')->where(['tenant_id'=>$tenant,'status'=>1,'delete_time'=>0])->find();
    $page=Store::table('page')->where(['tenant_id'=>$tenant,'tool_id'=>$tool['id']])->find();
    $draft=Page::detail($tenant,(int)$page['id']);
    $draft['document']['page']['styles']['base']['paddingLeft']=['value'=>22,'unit'=>'px'];
    $saved=Page::saveDraft($tenant,1,(int)$page['id'],(int)$page['draft_revision'],$draft['document']);
    check($saved['draft_revision']===$page['draft_revision']+1,'draft revision increments');
    try {Page::saveDraft($tenant,1,(int)$page['id'],(int)$page['draft_revision'],$draft['document']);throw new RuntimeException('stale save allowed');} catch (RuntimeException $e) {check($e->getCode()===409,'stale revision conflict');}
    try {Page::publish($tenant,1,(int)$page['id'],(int)$saved['draft_revision'],[]);throw new RuntimeException('empty target publish');} catch (InvalidArgumentException $e) {}
    $published=Page::publish($tenant,1,(int)$page['id'],(int)$saved['draft_revision'],['pc','h5','mp-weixin']);
    check($published['version_id']!==$page['published_version_id'],'immutable version created');
    $preview=Page::previewToken($tenant,(int)$page['id'],(int)$saved['draft_revision']);
    check(!isset(Page::preview($tenant,$preview['token'])['document']['prompt']),'preview hides prompt');
    try {Page::preview($tenant+99999,$preview['token']);throw new RuntimeException('cross tenant preview allowed');} catch (InvalidArgumentException $e) {}
    $models=Models::options($tenant);check(count($models)>0,'text model fixture required');
    $values=[];foreach($draft['document']['fields'] as $f){$values[$f['key']]=(($f['default']??'')!==''?$f['default']:null)??($f['options'][0]['value']??($f['type']==='switch'?false:'这是智能文案集成验证的示例文本，仅用于测试。'));}
    $key='test_'.bin2hex(random_bytes(16));
    $request=['tool_id'=>$tool['id'],'version_id'=>$published['version_id'],'values'=>$values,'model_selection'=>$models[0]['id'],'idempotency_key'=>$key];
    $before=(int)Db::name('ai_consumption_log')->count();
    $created=Task::create($tenant,999999,$request);
    $again=Task::create($tenant,999999,array_reverse($request,true));
    check($created['id']===$again['id'],'same request key returns original task');
    check((int)Db::name('ai_consumption_log')->count()===$before,'create does not bill or call upstream');
    check(!isset($created['prompt_snapshot'])&&!isset($created['model_snapshot'])&&!isset($created['model']['input']),'private snapshots hidden');
    $request['values'][array_key_first($values)]='不同输入';
    try {Task::create($tenant,999999,$request);throw new RuntimeException('key reuse accepted');} catch (RuntimeException $e) {check($e->getCode()===409,'changed request conflict');}
    try {Task::detail($tenant,999998,(int)$created['id']);throw new RuntimeException('cross user read allowed');} catch (InvalidArgumentException $e) {}
    try {Task::detail($tenant+99999,999999,(int)$created['id']);throw new RuntimeException('cross tenant read allowed');} catch (InvalidArgumentException $e) {}
    check(Task::requestHash(['values'=>['b'=>false,'a'=>0]])===Task::requestHash(['values'=>['a'=>0,'b'=>false]]),'canonical request hash');
    $items=Sources::resolve('copywriting_tools',['limit'=>12],$tenant,['terminal'=>'mobile']);
    check(count($items)===12,'decoration source includes twelve published tools');
    check((int)$items[0]['link']['query']['tenant_id']===$tenant,'decoration links keep tenant');
    echo "copywriting publishing, idempotency and ownership PASS (rolled back; no provider calls)\n";
} finally {Db::rollback();}
