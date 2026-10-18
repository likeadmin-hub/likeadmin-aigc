<?php
// Real persistence and Agent graph boundary, isolated tables, no provider calls.
require dirname(__DIR__,4).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\{Config,Db};
use app\common\service\app\aigc_canvas\{AigcCanvasService as Canvas,CanvasConversationTools as Tools};
use app\common\service\app\aigc_llm\agent\{AgentWorkspaceScope as Scope,AgentStore};
$config=Config::get('database');$original=$config['connections']['mysql']['prefix'];$prefix='itcsave_'.getmypid().'_';
$tables=['aigc_canvas_project','aigc_canvas_run','aigc_canvas_asset','aigc_canvas_project_compat_backup','aigc_llm_agent_event'];
$checks=0;$check=static function(bool $ok,string $why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};
try {
    foreach($tables as $table)Db::execute("CREATE TABLE `{$prefix}{$table}` LIKE `{$original}{$table}`");
    $config['connections']['mysql']['prefix']=$prefix;Config::set($config,'database');Db::connect(null,true);
    $check(Db::connect()->getConfig('prefix')===$prefix,'Isolated connection not active');
    // Exercise native source/target clients, legacy data and other tenant/user owners.
    foreach([[701,801,false],[701,802,true],[702,801,true]] as [$tenant,$user,$legacy]){
        $native=[['id'=>'a','type'=>'text','position'=>['x'=>0,'y'=>0],'metadata'=>['prompt'=>'original','content'=>'original output']],['id'=>'b','type'=>'image','position'=>['x'=>1,'y'=>1],'metadata'=>['url'=>'/retained.png','storage_scope'=>'tenant','storage_engine'=>'local']]];
        $edges=[['id'=>'edge','source'=>'a','target'=>'b','custom'=>['keep'=>true]]];
        $project=Canvas::createProject($tenant,$user,['name'=>'save fixture','nodes'=>$native,'edges'=>$edges,'viewport'=>['pages'=>[['id'=>'canvas_1','name'=>'Original','nodeIds'=>['a','b']]],'currentCanvasId'=>'canvas_1']]);
        if($legacy){
            Db::name('aigc_canvas_project')->where('id',$project['id'])->update(['nodes_json'=>AgentStore::encode([['id'=>'a','type'=>'script','data'=>['prompt'=>'original','outputContent'=>'original output']],$native[1]]),'edges_json'=>AgentStore::encode($edges),'viewport_json'=>'{}','update_time'=>100]);
        }
        $project=Canvas::projectDetail($tenant,$user,$project['id']);
        $revision=(int)$project['viewport']['agentRevision'];
        $backup=Db::name('aigc_canvas_project_compat_backup')->where('project_id',$project['id'])->value('snapshot_json');
        $check($legacy ? $revision===1 && is_string($backup) : $revision===0 && $backup===null,'Native project was migrated or legacy original was not backed up');
        for($i=1;$i<=3;$i++){
            $project['nodes'][0]['metadata']['prompt']='save '.$i;
            $wire=['id'=>$project['id'],'name'=>'save '.$i,'nodes'=>$project['nodes'],'edges'=>$edges,'viewport'=>$project['viewport']];
            $saved=Canvas::saveProject($tenant,$user,$wire);
            $scope=Scope::canvas($tenant,$user,['project_id'=>$project['id'],'page_id'=>'canvas_1']);
            $run=['id'=>9000+$project['id'],'tenant_id'=>$tenant,'user_id'=>$user,'preferences'=>AgentStore::encode(['_workspace'=>$scope])];
            Tools::operate($run,['action'=>'read_page']);
            $project=Canvas::projectDetail($tenant,$user,$project['id']);
            $check((int)$saved['viewport']['agentRevision']===$revision && (int)$project['viewport']['agentRevision']===$revision,'A save/read changed Agent revision');
            $check($project['nodes'][0]['metadata']['prompt']==='save '.$i && $project['nodes'][0]['metadata']['content']==='original output','Saved prompt or original output lost');
            $check($project['nodes'][1]['metadata']['url']==='/retained.png' && $project['edges'][0]['custom']===['keep'=>true],'Media/storage or connection metadata lost');
            $check(Db::name('aigc_canvas_project_compat_backup')->where('project_id',$project['id'])->value('snapshot_json')===$backup,'Repeated save replaced original backup');
            // Reuse the browser snapshot captured before Agent reads, not a freshly loaded revision.
            $wire['viewport'] = $saved['viewport'];
            $project = Canvas::saveProject($tenant,$user,$wire);
            $check((int)$project['viewport']['saveRevision'] === (int)$saved['viewport']['saveRevision'] + 1, 'Own sequential save did not advance revision');
        }
        // Transport retries must acknowledge one exact write, including historical projects.
        $retryWire = ['id'=>$project['id'],'name'=>'retry fixture','nodes'=>$project['nodes'],'edges'=>$project['edges'],'viewport'=>$project['viewport'],'save_request_id'=>'retry_test_'.str_repeat((string)$project['id'],16)];
        $once=Canvas::saveProject($tenant,$user,$retryWire);
        $twice=Canvas::saveProject($tenant,$user,$retryWire);
        $check($once['viewport']['saveRevision']===$twice['viewport']['saveRevision'],'Lost-response retry saved twice');
        $changed=$retryWire;$changed['name']='changed payload';$rejected=false;
        try{Canvas::saveProject($tenant,$user,$changed);}catch(Exception $e){$rejected=str_contains($e->getMessage(),'内容已变化');}
        $check($rejected,'Reused request id accepted different content');
        $other=$once;unset($other['save_request_id']);$other['name']='another editor';
        $project=Canvas::saveProject($tenant,$user,$other);$rejected=false;
        try{Canvas::saveProject($tenant,$user,$retryWire);}catch(Exception $e){$rejected=str_contains($e->getMessage(),'其他标签页');}
        $check($rejected,'Old receipt rebased over another editor');
        $check(Canvas::projectDetail($tenant,$user,$project['id'])['name']==='another editor','Retry undid another editor');
        // Two tabs share a snapshot, including inactive pages. Only one may commit it.
        $project['viewport']['pages']=[['id'=>'canvas_1','name'=>'Original','nodeIds'=>['a']],['id'=>'second','name'=>'Second','nodeIds'=>['b']]];
        $project=Canvas::saveProject($tenant,$user,$project);
        $tabA=$project;$tabB=$project;
        $tabA['nodes'][0]['metadata']['prompt']='tab A saved';
        $first=Canvas::saveProject($tenant,$user,$tabA);
        $tabB['nodes'][1]['metadata']['prompt']='tab B stale';
        $tabB['viewport']['currentCanvasId']='second';
        $rejected=false;try{Canvas::saveProject($tenant,$user,$tabB);}catch(Exception $e){$rejected=str_contains($e->getMessage(),'其他标签页');}
        $check($rejected,'Second tab overwrote first tab on another page');
        $latest=Canvas::projectDetail($tenant,$user,$project['id']);
        $check($latest['nodes'][0]['metadata']['prompt']==='tab A saved' && ($latest['nodes'][1]['metadata']['prompt']??'')!=='tab B stale','Rejected snapshot changed stored pages');
        $check($latest['viewport']['saveRevision']===$first['viewport']['saveRevision'],'Read-only detail advanced save revision');
        $legacyClient=$latest;unset($legacyClient['viewport']['saveRevision']);
        $rejected=false;try{Canvas::saveProject($tenant,$user,$legacyClient);}catch(Exception $e){$rejected=str_contains($e->getMessage(),'其他标签页');}
        $check($rejected,'Client without revision overwrote a versioned project');
        $latest['nodes'][1]['metadata']['prompt']='tab B after refresh';
        $project=Canvas::saveProject($tenant,$user,$latest);
        $check($project['nodes'][0]['metadata']['prompt']==='tab A saved' && $project['nodes'][1]['metadata']['prompt']==='tab B after refresh','Reload/save lost another page');
        Canvas::renameProject($tenant,$user,$project['id'],'renamed elsewhere');
        $rejected=false;try{Canvas::saveProject($tenant,$user,$project);}catch(Exception $e){$rejected=str_contains($e->getMessage(),'其他标签页');}
        $check($rejected,'Stale snapshot undid a project-list rename');
        $wire=Canvas::projectDetail($tenant,$user,$project['id']);
        // Real Agent mutations must still reject stale browser writes.
        Tools::operate($run,['action'=>'update_prompt','node_id'=>'a','content'=>'Agent edit']);
        $rejected=false;try{Canvas::saveProject($tenant,$user,$wire);}catch(Exception $e){$rejected=str_contains($e->getMessage(),'Agent 已更新');}
        $check($rejected,'Stale browser overwrote a real Agent edit');
        $latest=Canvas::projectDetail($tenant,$user,$project['id']);
        $check($latest['nodes'][0]['metadata']['prompt']==='Agent edit' && (int)$latest['viewport']['agentRevision']===$revision+1,'Agent edit/revision lost');
    }
    echo "Continuous save integration: $checks checks passed; native/legacy projects, tenant/user owners, original backups and real Agent conflict protection; no provider calls\n";
}finally{foreach(array_reverse($tables) as $table)Db::execute("DROP TABLE IF EXISTS `{$prefix}{$table}`");}
