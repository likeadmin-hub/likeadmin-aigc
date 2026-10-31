<?php
/** Private HTTP fixture: real login/routes/runtime/billing/worker, loopback supplier only. No production task writes or external generation. */
if(PHP_SAPI==='cli-server'){$_SERVER['SCRIPT_FILENAME']=dirname(__DIR__).'/public/index.php';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['PHP_SELF']='/index.php';$_SERVER['PATH_INFO']=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);}
require dirname(__DIR__).'/vendor/autoload.php';
class IsolatedGenerationHttpApp extends think\App { public function getBasePath(): string { return dirname(__DIR__).'/app/'; } }
(new IsolatedGenerationHttpApp(dirname(__DIR__)))->initialize();
require __DIR__.'/Support/MarketWorkerFixture.php';
use think\facade\Db;
use app\common\service\ai\GenerationTaskResultService as View;
$original=config('database');
if(PHP_SAPI==='cli-server') {
    $prefix=(string)getenv('AIGC_HTTP_FIXTURE_PREFIX');$dir=(string)getenv('AIGC_HTTP_FIXTURE_DIR');
    MarketWorkerFixture::check(in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true),'Loopback only');
    MarketWorkerFixture::activate($original,$prefix,$dir);app()->env->set('database.prefix',$prefix);app()->setRuntimePath($dir.'/runtime/');
    if(getenv('AIGC_HTTP_FIXTURE_ROLE')==='supplier') {
        $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);header('Content-Type: application/json');
        if($path==='/health'){echo '{}';return;}
        if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer fixture-no-real-key'){http_response_code(403);echo '{}';return;}
        if($path==='/api/v1/tasks'&&$_SERVER['REQUEST_METHOD']==='POST') {
            $input=json_decode(file_get_contents('php://input'),true);file_put_contents($dir.'/submissions.jsonl',json_encode($input)."\n",FILE_APPEND|LOCK_EX);
            echo json_encode(['code'=>1,'data'=>['id'=>'fixture-task-'.count(file($dir.'/submissions.jsonl')),'status'=>'running']]);return;
        }
        if(str_starts_with((string)$path,'/api/v1/tasks/')) {
            $url='data:video/mp4;base64,'.base64_encode(file_get_contents($dir.'/synthetic.mp4'));
            echo json_encode(['code'=>1,'data'=>['id'=>basename($path),'status'=>'success','videos'=>[['url'=>$url,'duration'=>1,'width'=>32,'height'=>32]]]]);return;
        }
        http_response_code(404);echo json_encode(['error'=>'Unexpected fixture supplier path']);return;
    }
    // Normal production middleware, including password login, tenant and app access.
    pcntl_async_signals(true);pcntl_signal(SIGALRM,static function(){error_log('Fixture watchdog '.json_encode(['prefix'=>env('database.prefix'),'stack'=>array_map(static fn($f)=>($f['class']??'').'::'.($f['function']??''),debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,18))]));throw new RuntimeException('Private HTTP watchdog');});pcntl_alarm(15);
    $http=app()->http;$response=$http->run();pcntl_alarm(0);$response->send();$http->end($response);return;
}
if(($argv[1]??'')==='--worker') {
    MarketWorkerFixture::activate($original,$argv[2],$argv[3]);app()->env->set('database.prefix',$argv[2]);$command=new app\common\command\AiTaskWorker();
    exit($command->run(new think\console\Input(['--app='.$argv[4],'--lease=10','--sleep=1','--batch=1']),new think\console\Output('console')));
}
MarketWorkerFixture::check(getenv('AIGC_GENERATION_HTTP_TESTS')==='1','Explicit local fixture opt-in required');
$fixture=null;$processes=[];$report=['scope'=>'Private schemas and fake account; real HTTP login, generation and worker; loopback supplier replacement','external_generation_calls'=>0,'production_wallet_writes'=>0];
function callHttp(string $base,string $path,array $data=[],string $token='',string $method='POST'): array {
    $headers=['Content-Type: application/json'];if($token!=='')$headers[]='token: '.$token;
    $curl=curl_init($base.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers]);
    if($method==='POST'){curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($data));}
    $raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$response=json_decode((string)$raw,true);
    if(!is_array($response)){preg_match('/<h1>(.*?)<\/h1>/s',(string)$raw,$message);throw new RuntimeException('Non-JSON fixture response '.$path.' HTTP'.$status.': '.substr(strip_tags($message[1]??(string)$raw),0,600));}
    return $response;
}
function dataHttp(string $base,string $path,array $data=[],string $token='',string $method='POST'): array {
    $r=callHttp($base,$path,$data,$token,$method);if(($r['code']??0)!==1)throw new RuntimeException('HTTP '.$path.': '.($r['msg']??json_encode($r)));return $r['data']??[];
}
try {
    $names=['tenant','user','user_session','config','tenant_config','tenant_file','tenant_file_cate','file','file_cate','app','tenant_app','tenant_app_order','tenant_app_config','tenant_system_role_menu','membership_plan','membership_plan_app','update_source',
        'ai_app_task','ai_consumption_log','ai_consumption_event','ai_task_job','ai_task_result_asset','ai_task_audit_log','ai_model_pricing','tenant_points_log','user_account_log','power_market_product','power_market_sku','power_market_price_rule','tenant_power_market_sku_price','tenant_power_market_product',
        'aigc_image_channel','aigc_image_channel_spec','aigc_video_channel','aigc_video_channel_spec','aigc_llm_config'];
    foreach(Db::query('SHOW TABLES')as$row)foreach($row as$table)foreach(['aigc_','tenant_point_','user_point_']as$group){$base=$original['connections']['mysql']['prefix'];if(str_starts_with($table,$base.$group))$names[]=substr($table,strlen($base));}
    $fixture=new MarketWorkerFixture($original,array_unique($names));$tenant=$fixture->tenant;$user=$fixture->user;$dir=$fixture->dir;$prefix=$fixture->prefix;app()->env->set('database.prefix',$prefix);
    mkdir($dir.'/config',0700);file_put_contents($dir.'/config/install.lock','private-fixture');
    copy('/tmp/market-batch52-media/synthetic.mp4',$dir.'/synthetic.mp4');
    Db::name('user')->where('id',$user)->update(['password'=>create_password('Private-fixture-pass-2026',config('project.unique_identification'))]);
    Db::name('config')->insert(['type'=>'login','name'=>'login_way','value'=>'[1]']);
    foreach(['aigc_canvas','aigc_video','aigc_short_drama']as$code) {
        Db::name('app')->insert(['code'=>$code,'name'=>'Private fixture','status'=>'installed','is_builtin'=>1,'current_version'=>'0.0.0','install_time'=>time(),'update_time'=>time()]);
        Db::name('tenant_app')->insert(['tenant_id'=>$tenant,'app_code'=>$code,'buy_status'=>'paid','shelf_status'=>'on','enable_status'=>'enabled','create_time'=>time(),'update_time'=>time()]);
    }
    foreach(['supplier','api']as$role){
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);$urls[$role]='http://'.$address;
        $pipes=[];$processes[$role]=proc_open([PHP_BINARY,'-d','disable_functions=','-S',$address,__FILE__],[0=>['pipe','r'],1=>['file',$dir.'/'.$role.'.log','a'],2=>['file',$dir.'/'.$role.'.log','a']],$pipes,dirname(__DIR__),array_merge(getenv(),['AIGC_HTTP_FIXTURE_PREFIX'=>$prefix,'AIGC_HTTP_FIXTURE_DIR'=>$dir,'AIGC_HTTP_FIXTURE_ROLE'=>$role]));fclose($pipes[0]);
        MarketWorkerFixture::check(is_resource($processes[$role]),'Cannot start private HTTP server');
    }
    usleep(200000);
    Db::name('update_source')->insert(['name'=>'Private loopback fixture','base_url'=>$urls['supplier'],'online_base_url'=>$urls['supplier'],'license_key'=>'fixture-no-real-key','online_license_key'=>'fixture-no-real-key','status'=>1,'create_time'=>time(),'update_time'=>time()]);
    $meta=['category_code'=>'video','params_schema'=>['properties'=>['prompt'=>['type'=>'string'],'duration'=>['type'=>'integer','enum'=>[1]],'resolution'=>['type'=>'string','enum'=>['720P']],'aspect_ratio'=>['type'=>'string','enum'=>['1:1']],'generate_audio'=>['type'=>'boolean']],'required'=>['prompt','duration']],
        'supported_durations'=>[1],'supported_resolutions'=>['720P'],'supported_ratios'=>['1:1'],'material_capabilities'=>['modes'=>[['code'=>'text_to_video','method'=>'text_to_video','fields'=>[]]]]];
    $product=app\common\model\power\PowerMarketProduct::create(['product_code'=>$prefix.'video','name'=>'Private synthetic video','resource_type'=>'model','model_type'=>'video','source_code'=>'isolated-fixture','upstream_resource_key'=>$prefix,'upstream_model_code'=>'synthetic-video','upstream_channel_code'=>'fixture','source_payload'=>['category_code'=>'video','market_metadata'=>$meta],'status'=>1,'create_time'=>time(),'update_time'=>time()]);
    $sku=app\common\model\power\PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$prefix.'sku','title'=>'Private fixture','locked_params'=>['duration'=>1,'resolution'=>'720P'],'usage_unit'=>'per_call','usage_unit_size'=>1,'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1,'source_payload'=>['meter_contract'=>['version'=>1,'basis'=>'call','rounding'=>'ceil']],'create_time'=>time(),'update_time'=>time()]);
    MarketWorkerFixture::check(count(app\common\service\power\MarketVideoModelRuntimeService::options($tenant))===1,'Synthetic model not in real catalogue');
    $api=$urls['api'];$suffix='?tenant_id='.$tenant;
    $login=fn()=>dataHttp($api,'/api/login/account'.$suffix,['account'=>$prefix,'password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>4]);$token=$login()['token'];
    $report['normal_password_login']=true;
    $node=['id'=>'fixture-node','type'=>'video','position'=>['x'=>1,'y'=>2],'metadata'=>['prompt'=>'Synthetic colored block','status'=>'idle','audio'=>false,'quality'=>'720P','duration'=>1,'ratio'=>'1:1']];
    $project=dataHttp($api,'/api/app.aigc_canvas.project/create'.$suffix,['name'=>'Private HTTP generation','nodes'=>[$node],'edges'=>[],'viewport'=>['x'=>0,'y'=>0,'zoom'=>1]],$token);
    $id=(int)$project['id'];
    $request=['project_id'=>$id,'node_id'=>'fixture-node','resource_type'=>'model','model_id'=>'market_video_model:'.$product['id'],'market_product_id'=>$product['id'],'market_sku_id'=>$sku['id'],'channel'=>'market_video_model:'.$product['id'],'prompt'=>'Synthetic colored block','duration'=>1,'quality'=>'720P','ratio'=>'1:1','generation_method'=>'text_to_video','advanced_params'=>['generate_audio'=>false]];
    $submitted=dataHttp($api,'/api/app.aigc_canvas.generate/video'.$suffix,$request,$token);
    MarketWorkerFixture::check(($submitted['generation_task']['schema_version']??0)===1,'Missing canonical accepted task');$report['accepted_task_ref']=$submitted['generation_task']['task_ref'];
    $node['metadata']=array_replace($node['metadata'],['status'=>'running','pending'=>true,'taskId'=>(string)$submitted['task_id'],'generationTaskRef'=>$report['accepted_task_ref'],'generationStartedAt'=>time()*1000,'mediaItems'=>[]]);
    $saved=dataHttp($api,'/api/app.aigc_canvas.project/save'.$suffix,['id'=>$id,'nodes'=>[$node],'edges'=>[],'viewport'=>$project['viewport']],$token);
    $consumption=(int)$submitted['task_id'];$frozen=Db::name('ai_consumption_log')->where('id',$consumption)->value('price_snapshot');
    $fixture->start(__FILE__,'aigc_canvas');
    MarketWorkerFixture::wait(function()use($id){$row=Db::name('aigc_canvas_project')->where('id',$id)->find();$nodes=json_decode($row['nodes_json'],true);return ($nodes[0]['metadata']['status']??'')==='success';},'HTTP accepted task did not independently complete',30);
    $fixture->stop();$view=View::read((int)$submitted['generation_task']['app_task_id'],$tenant,$user);
    MarketWorkerFixture::check($view['status']==='success'&&count($view['results'])===1&&$view['results'][0]['type']==='video','Canonical result not ready');
    $result=$view['results'][0];MarketWorkerFixture::check(hash_file('sha256',$dir.'/public/'.$result['uri'])===hash_file('sha256',$dir.'/synthetic.mp4'),'Managed bytes mismatch');
    $node['metadata']['prompt']='User draft after acceptance';
    dataHttp($api,'/api/app.aigc_canvas.project/save'.$suffix,['id'=>$id,'nodes'=>[$node],'edges'=>[],'viewport'=>$saved['viewport']],$token);
    dataHttp($api,'/api/login/logout'.$suffix,[],$token);$token=$login()['token'];
    $reopened=dataHttp($api,'/api/app.aigc_canvas.project/detail'.$suffix.'&id='.$id,[],$token,'GET');
    MarketWorkerFixture::check($reopened['nodes'][0]['metadata']['status']==='success'&&$reopened['nodes'][0]['metadata']['prompt']==='User draft after acceptance','Reopen lost terminal output or user draft');
    MarketWorkerFixture::check($frozen===Db::name('ai_consumption_log')->where('id',$consumption)->value('price_snapshot'),'Frozen pricing changed');
    $report+=['authenticated_submission'=>true,'worker_completed_without_browser_poll'=>true,'managed_result_checksum'=>true,'stale_autosave_preserved_result_and_user_edit'=>true,'logout_login_reopen'=>true,'local_supplier_submissions'=>count(file($dir.'/submissions.jsonl')),'tasks'=>(int)Db::name('ai_app_task')->count(),'consumptions'=>(int)Db::name('ai_consumption_log')->count(),'result_assets'=>(int)Db::name('ai_task_result_asset')->count(),'billing_events'=>Db::name('ai_consumption_event')->column('event_type'),'fixture_user_balance'=>(float)Db::name('user')->where('id',$user)->value('user_money'),'fixture_tenant_balance'=>(float)Db::name('tenant')->where('id',$tenant)->value('point_balance')];
    $query=dataHttp($api,'/api/app.aigc_canvas.generate/videoQuery'.$suffix.'&task_id='.$consumption,[],$token,'GET');
    MarketWorkerFixture::check(($query['generation_task']['task_ref']??'')===$report['accepted_task_ref']&&$query['generation_task']['status']==='success','Canvas HTTP query lost canonical results');
    Db::name('aigc_short_drama_config')->insert(['tenant_id'=>$tenant,'status'=>1,'config_json'=>'{}','create_time'=>time(),'update_time'=>time()]);
    $drama=dataHttp($api,'/api/app.aigc_short_drama.canvas/create'.$suffix,['title'=>'Private HTTP drama'], $token);
    $dramaId=(int)$drama['id'];$dramaNode=['id'=>'1','type'=>'video','title'=>'Synthetic video','position'=>['x'=>0,'y'=>0],'metadata'=>['prompt'=>'Synthetic colored block','channel'=>$request['channel'],'status'=>'idle','quality'=>'720P','duration'=>1,'ratio'=>'1:1']];
    $drama=dataHttp($api,'/api/app.aigc_short_drama.canvas/save'.$suffix,['id'=>$dramaId,'nodes'=>[$dramaNode],'edges'=>[],'viewport'=>[],'expected_revision'=>$drama['graph_revision']],$token);
    $input=$request;unset($input['project_id']);$input=array_replace($input,['canvas_id'=>$dramaId,'node_id'=>'1','type'=>'video','request_key'=>$prefix.'drama']);
    $quoted=dataHttp($api,'/api/app.aigc_short_drama.canvas/quote'.$suffix,$input,$token);
    dataHttp($api,'/api/app.aigc_short_drama.canvas/confirmQuote'.$suffix,$input+['quote_token'=>$quoted['quote_token']],$token);
    $input['quote_token']=$quoted['quote_token'];
    $accepted=dataHttp($api,'/api/app.aigc_short_drama.canvas/run'.$suffix,$input,$token);
    MarketWorkerFixture::check(($accepted['generation_task']['schema_version']??0)===1,'Short drama accepted response lacks shared task');
    $runId=(int)$accepted['id'];$counts=[Db::name('ai_app_task')->count(),Db::name('ai_consumption_log')->count(),count(file($dir.'/submissions.jsonl'))];
    // Accepted work must survive mutable catalogue changes, and the same request key must never submit twice.
    $product->save(['status'=>0]);$sku->save(['sale_points'=>19]);
    $replay=dataHttp($api,'/api/app.aigc_short_drama.canvas/run'.$suffix,$input,$token);
    MarketWorkerFixture::check($replay['id']===$runId&&$replay['generation_task']['task_ref']===$accepted['generation_task']['task_ref'],'Short drama replay created another identity');
    MarketWorkerFixture::check($counts===[Db::name('ai_app_task')->count(),Db::name('ai_consumption_log')->count(),count(file($dir.'/submissions.jsonl'))],'Replay duplicated charge or supplier submit');
    $fixture->start(__FILE__,'');
    MarketWorkerFixture::wait(function()use($dramaId){$nodes=json_decode(Db::name('aigc_short_drama_canvas')->where('id',$dramaId)->value('nodes_json'),true);return ($nodes[0]['metadata']['status']??'')==='success';},'Short drama HTTP submission did not project its final result',75);
    $fixture->stop();
    $finalRun=dataHttp($api,'/api/app.aigc_short_drama.canvas/task'.$suffix.'&id='.$runId,[],$token,'GET');
    MarketWorkerFixture::check($finalRun['status']==='success'&&$finalRun['generation_task']['status']==='success'&&count($finalRun['generation_task']['results'])===1,'Short drama typed result unavailable');
    dataHttp($api,'/api/login/logout'.$suffix,[],$token);$token=$login()['token'];
    $reopenedDrama=dataHttp($api,'/api/app.aigc_short_drama.canvas/current'.$suffix.'&id='.$dramaId,[],$token,'GET');
    MarketWorkerFixture::check($reopenedDrama['nodes'][0]['metadata']['status']==='success'&&!empty($reopenedDrama['nodes'][0]['metadata']['url']),'Short drama normal login reopen lost result');
    $second=$user+1;Db::name('user')->insert(['id'=>$second,'tenant_id'=>$tenant,'sn'=>$second,'account'=>$prefix.'second','password'=>create_password('Private-fixture-pass-2026',config('project.unique_identification')),'nickname'=>'Other fixture owner','user_money'=>100,'create_time'=>time(),'update_time'=>time()]);
    $other=dataHttp($api,'/api/login/account'.$suffix,['account'=>$prefix.'second','password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>4])['token'];
    foreach(['/api/app.aigc_canvas.project/detail'.$suffix.'&id='.$id,'/api/app.aigc_canvas.generate/videoQuery'.$suffix.'&task_id='.$consumption,'/api/app.aigc_short_drama.canvas/task'.$suffix.'&id='.$runId]as$path)MarketWorkerFixture::check((callHttp($api,$path,[],$other,'GET')['code']??0)!==1,'Foreign owner read succeeded');
    $events=Db::name('ai_consumption_event')->column('event_type');$eventCounts=array_count_values($events);
    MarketWorkerFixture::check(($eventCounts['reserve']??0)===2&&($eventCounts['settle']??0)===2,'Expected one reserve and final settlement per accepted task');
    MarketWorkerFixture::check((float)Db::name('user')->where('id',$user)->value('user_money')===98.&&(float)Db::name('tenant')->where('id',$tenant)->value('point_balance')===98.,'Fixture points do not match frozen 1-point requests');
    $report['short_drama']=['http_quote_confirm_submit'=>true,'same_key_replay_no_second_submit_or_charge'=>true,'frozen_catalogue_change_survived'=>true,'worker_result_and_normal_login_reopen'=>true,'task_ref'=>$accepted['generation_task']['task_ref']];
    $report['http_foreign_owner_reads_rejected']=3;$report['total_local_supplier_submissions']=count(file($dir.'/submissions.jsonl'));$report['total_reserve_events']=$eventCounts['reserve'];$report['total_settle_events']=$eventCounts['settle'];$report['total_fixture_user_balance']=98;$report['total_fixture_tenant_balance']=98;
} catch(Throwable $e) {
    $failure=$e;$report['failure']=$e->getMessage();$report['fixture_line']=array_values(array_map(static fn($frame)=>$frame['line']??0,array_filter($e->getTrace(),static fn($frame)=>($frame['file']??'')===__FILE__))); 
    if($fixture&&isset($runId)) {
        $row=Db::name('aigc_short_drama_canvas_run')->where('id',$runId)->find();
        $report['short_run_at_failure']=array_intersect_key($row??[],array_flip(['id','status','progress','error','provider_task_id']));
        $report['native_video_status_at_failure']=Db::name('aigc_video_task')->where('id',(int)($row['provider_task_id']??0))->value('status');
        $report['short_node_at_failure']=json_decode(Db::name('aigc_short_drama_canvas')->where('id',$dramaId)->value('nodes_json'),true);
        try{$method=new ReflectionMethod(app\common\service\app\aigc_short_drama\ShortDramaCanvasService::class,'refreshRun');$method->setAccessible(true);$method->invoke(null,$row,true);}
        catch(Throwable $diagnostic){$report['reconcile_diagnostic']=$diagnostic->getMessage();}
    }
    if($fixture)foreach(glob($fixture->dir.'/*.log')?:[]as$log)fwrite(STDERR,basename($log).": ".substr(file_get_contents($log),-2500)."\n");
} finally {
    foreach($processes as$process)if(is_resource($process)){$state=proc_get_status($process);if($state['running'])posix_kill($state['pid'],SIGTERM);proc_close($process);}
    if($fixture){$fixture->cleanup();$report['private_tables_and_files_removed']=true;}
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";if(isset($failure))exit(1);
