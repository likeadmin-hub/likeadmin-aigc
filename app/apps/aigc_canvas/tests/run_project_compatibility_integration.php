<?php
require dirname(__DIR__,4).'/vendor/autoload.php';
use think\{App};
use think\facade\{Config,Db};
use app\common\service\app\aigc_canvas\{CanvasProjectCompatibility as Compat,CanvasHistoryImportService as History};
use app\common\model\app\aigc_canvas\AigcCanvasProject;
(new App())->initialize();
$config=Config::get('database');$original=$config['connections']['mysql']['prefix'];$prefix='itcanvas_'.getmypid().'_';
$tables=['aigc_canvas_project','aigc_canvas_agent_thread','aigc_canvas_agent_message','aigc_llm_session','aigc_llm_message'];$checks=0;
$check=static function($ok,$why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};
try {
    foreach($tables as $table) Db::execute("CREATE TABLE `{$prefix}{$table}` LIKE `{$original}{$table}`");
    $config['connections']['mysql']['prefix']=$prefix;Config::set($config,'database');
    Db::connect(null,true);
    if (Db::connect()->getConfig('prefix')!==$prefix) throw new RuntimeException('Isolated connection not active');
    \app\common\service\database\SqlMigrationExecutor::execute(file_get_contents(__DIR__.'/../migrations/zz_20261017_project_compat_backup.sql'),$prefix);
    $tables[]='aigc_canvas_project_compat_backup';
    $project=AigcCanvasProject::create(['tenant_id'=>701,'user_id'=>801,'name'=>'legacy fixture','nodes_json'=>[['id'=>'old','type'=>'script','data'=>['content'=>'保留文本']]],'edges_json'=>[],'viewport_json'=>['x'=>1,'y'=>2,'zoom'=>1],'status'=>1,'create_time'=>100,'update_time'=>100,'delete_time'=>0]);
    $row=Compat::upgrade($project->toArray());$check($row['viewport_json']['pages'][0]['nodeIds']===['old'],'project nodes not indexed');
    $check((int)$row['update_time']===100,'migration changed project ordering timestamp');
    $check(Compat::upgrade($row)['viewport_json']['agentRevision']===$row['viewport_json']['agentRevision'],'repeat upgrade changed revision');
    $check((int)Db::name('aigc_canvas_project_compat_backup')->count()===1,'original snapshot missing or duplicated');
    $backup=json_decode(Db::name('aigc_canvas_project_compat_backup')->where('project_id',$project['id'])->value('snapshot_json'),true);
    $check($backup['nodes_json'][0]['type']==='script','original snapshot was rewritten');
    $thread=Db::name('aigc_canvas_agent_thread')->insertGetId(['tenant_id'=>701,'user_id'=>801,'project_id'=>$project['id'],'title'=>'历史会话','status'=>'active','summary'=>'','meta_json'=>'{}','create_time'=>100,'update_time'=>102,'delete_time'=>0]);
    foreach([['user','旧请求','success'],['assistant','旧回复','running']] as $item) Db::name('aigc_canvas_agent_message')->insert(['tenant_id'=>701,'user_id'=>801,'project_id'=>$project['id'],'thread_id'=>$thread,'role'=>$item[0],'content'=>$item[1],'content_json'=>'{}','meta_json'=>'{}','status'=>$item[2],'error'=>'','create_time'=>101,'update_time'=>102,'delete_time'=>0]);
    History::importProject(701,801,$row);History::importProject(701,801,$row);
    $check((int)Db::name('aigc_llm_session')->count()===1,'thread imported twice');
    $session=Db::name('aigc_llm_session')->where('tenant_id',701)->find();$check($session['workspace_key']==='canvas:'.$project['id'].':canvas_1','scope incorrect');
    $messages=Db::name('aigc_llm_message')->order('seq')->select()->toArray();
    $check(count($messages)===2 && $messages[1]['status']==='stopped','history status incorrect');
    $check((int)$messages[1]['parent_user_message_id']===(int)$messages[0]['id'],'message parent lost');
    $check((int)$session['last_message_at']===102,'history timestamp overwritten');
    Db::name('aigc_llm_session')->where('id',$session['id'])->update(['delete_time'=>999]);History::importProject(701,801,$row);
    $check((int)Db::name('aigc_llm_session')->count()===1,'deleted conversation resurrected');
    try {History::importProject(702,801,$row);throw new RuntimeException('cross tenant allowed');}catch(InvalidArgumentException){$checks++;}
    $check((int)Db::name('aigc_canvas_agent_message')->count()===2,'old history removed');
    echo "Project/history integration: $checks checks passed; isolated tables only, no paid calls\n";
} finally {
    foreach(array_reverse($tables) as $table)Db::execute("DROP TABLE IF EXISTS `{$prefix}{$table}`");
}
