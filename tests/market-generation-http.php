<?php
/** Private HTTP fixture: real login/routes/runtime/billing/worker, loopback supplier only. No production task writes or external generation. */
if(PHP_SAPI==='cli-server'){$_SERVER['SCRIPT_FILENAME']=dirname(__DIR__).'/public/index.php';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['PHP_SELF']='/index.php';$_SERVER['PATH_INFO']=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);}
require dirname(__DIR__).'/vendor/autoload.php';
class IsolatedGenerationHttpApp extends think\App { public function getBasePath(): string { return dirname(__DIR__).'/app/'; } }
(new IsolatedGenerationHttpApp(dirname(__DIR__)))->initialize();
require __DIR__.'/Support/MarketWorkerFixture.php';
if(getenv('AIGC_HTTP_DEEP_MATRIX')==='1')require __DIR__.'/Support/MarketDeepHttpTransport.php';
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
        if(getenv('AIGC_HTTP_DEEP_MATRIX')==='1'){require __DIR__.'/Support/MarketDeepHttpSupplier.php';if(marketDeepSupplier($path,$dir))return;}
        if(in_array($path,['/fixture/input.wav','/fixture/result.wav','/fixture/result.mp4'],true)) {
            header('Content-Type: '.(str_ends_with($path,'.wav')?'audio/wav':'video/mp4'));
            readfile($dir.(str_ends_with($path,'.wav')?'/synthetic.wav':'/synthetic.mp4'));return;
        }

        if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer fixture-no-real-key'){http_response_code(403);echo '{}';return;}
        if(preg_match('~^/api/v1/apps/(seedsvc|lipsync)/(submit|query)$~',(string)$path,$matches)) {
            $input=json_decode(file_get_contents('php://input'),true);
            if($matches[2]==='submit') {
                $input['_fixture_app']=$matches[1];file_put_contents($dir.'/submissions.jsonl',json_encode($input)."\n",FILE_APPEND|LOCK_EX);
                echo json_encode(['code'=>1,'data'=>['task_id'=>'fixture-task-'.count(file($dir.'/submissions.jsonl')),'status'=>'running']]);return;
            }
            $url='http://'.$_SERVER['HTTP_HOST'].'/fixture/result.'.($matches[1]==='seedsvc'?'wav':'mp4');
            echo json_encode(['code'=>1,'data'=>['status'=>'success',($matches[1]==='seedsvc'?'audio_url':'video_url')=>$url]]);return;
        }
        if($path==='/api/v1/tasks'&&$_SERVER['REQUEST_METHOD']==='POST') {
            $input=json_decode(file_get_contents('php://input'),true);file_put_contents($dir.'/submissions.jsonl',json_encode($input)."\n",FILE_APPEND|LOCK_EX);
            echo json_encode(['code'=>1,'data'=>['id'=>'fixture-task-'.count(file($dir.'/submissions.jsonl')),'status'=>'running']]);return;
        }
        if(str_starts_with((string)$path,'/api/v1/tasks/')) {
            $index=(int)substr(basename($path),strlen('fixture-task-'));
            if(is_file($dir.'/hold-from-index')&&$index>=(int)file_get_contents($dir.'/hold-from-index')) {echo json_encode(['code'=>1,'data'=>['id'=>basename($path),'status'=>'running']]);return;}
            $input=json_decode(file($dir.'/submissions.jsonl')[$index-1],true);$image=($input['model']??'')==='synthetic-image';
            $url=$image?'data:image/png;base64,'.base64_encode(file_get_contents($dir.'/synthetic.png')):'data:video/mp4;base64,'.base64_encode(file_get_contents($dir.'/synthetic.mp4'));
            echo json_encode(['code'=>1,'data'=>['id'=>basename($path),'status'=>'success',($image?'images':'videos')=>[['url'=>$url,'duration'=>1,'width'=>32,'height'=>32]]]]);return;
        }
        http_response_code(404);echo json_encode(['error'=>'Unexpected fixture supplier path']);return;
    }
    // Normal production middleware, including password login, tenant and app access.
    pcntl_async_signals(true);pcntl_signal(SIGALRM,static function(){error_log('Fixture watchdog '.json_encode(['prefix'=>env('database.prefix'),'stack'=>array_map(static fn($f)=>($f['class']??'').'::'.($f['function']??''),debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,18))]));throw new RuntimeException('Private HTTP watchdog');});pcntl_alarm(15);
    $http=app()->http;$response=$http->run();pcntl_alarm(0);$response->send();$http->end($response);return;
}
if(($argv[1]??'')==='--worker') {
    MarketWorkerFixture::activate($original,$argv[2],$argv[3]);app()->env->set('database.prefix',$argv[2]);
    if(!is_dir($argv[3].'/runtime'))mkdir($argv[3].'/runtime',0700,true);app()->setRuntimePath($argv[3].'/runtime/');
    if($argv[4]==='fixture_micro_runner') {while(true){app\common\service\app\aigc_micro_video\MicroVideoWorker::tick();usleep(100000);}}
    $command=new app\common\command\AiTaskWorker();
    exit($command->run(new think\console\Input(['--app='.$argv[4],'--lease=10','--sleep=1','--batch=1']),new think\console\Output('console')));
}
MarketWorkerFixture::check(getenv('AIGC_GENERATION_HTTP_TESTS')==='1','Explicit local fixture opt-in required');
$fixture=null;$processes=[];$report=['scope'=>'Private schemas and fake account; real HTTP login, generation and worker; loopback supplier replacement','external_generation_calls'=>0,'production_wallet_writes'=>0];
function callHttp(string $base,string $path,array $data=[],string $token='',string $method='POST'): array {
    $headers=['Content-Type: application/json'];if(getenv('AIGC_HTTP_DEEP_MATRIX')==='1')$headers[]='Host: private-media.example.test';if($token!=='')$headers[]='token: '.$token;
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
    foreach(Db::query('SHOW TABLES')as$row)foreach($row as$table)foreach(['aigc_','tenant_point_','user_point_','open_platform_']as$group){$base=$original['connections']['mysql']['prefix'];if(str_starts_with($table,$base.$group))$names[]=substr($table,strlen($base));}
    $fixture=new MarketWorkerFixture($original,array_unique($names));$tenant=$fixture->tenant;$user=$fixture->user;$dir=$fixture->dir;$prefix=$fixture->prefix;app()->env->set('database.prefix',$prefix);
    mkdir($dir.'/config',0700);file_put_contents($dir.'/config/install.lock','private-fixture');
    copy('/tmp/market-batch52-media/synthetic.mp4',$dir.'/synthetic.mp4');
    copy('/tmp/market-batch52-media/synthetic.wav',$dir.'/synthetic.wav');
    Db::name('user')->where('id',$user)->update(['password'=>create_password('Private-fixture-pass-2026',config('project.unique_identification'))]);
    Db::name('config')->insert(['type'=>'login','name'=>'login_way','value'=>'[1]']);
    $fixtureApps=['aigc_canvas','aigc_video','aigc_short_drama','aigc_image','aigc_product_image','aigc_product_suite'];
    if(getenv('AIGC_HTTP_TOOL_MATRIX')==='1')$fixtureApps=array_unique(array_merge($fixtureApps,array_map(static fn($code)=>'aigc_'.$code,['style_transfer','photo_restore','model_wear','background_removal','image_translate','one_click_cleanup','product_multi_angle','fashion_lookbook','outpaint','local_redraw','fitting','product_promo_video'])));
    if(getenv('AIGC_HTTP_DEEP_MATRIX')==='1')$fixtureApps=array_merge($fixtureApps,['aigc_music','aigc_digital_human','aigc_micro_video']);
    if(getenv('AIGC_HTTP_STAGE_MATRIX')==='1')$fixtureApps=array_merge($fixtureApps,['aigc_music_cover','aigc_digital_human','aigc_micro_video']);
    foreach($fixtureApps as$code) {
        Db::name('app')->insert(['code'=>$code,'name'=>'Private fixture','status'=>'installed','is_builtin'=>1,'current_version'=>'0.0.0','install_time'=>time(),'update_time'=>time()]);
        Db::name('tenant_app')->insert(['tenant_id'=>$tenant,'app_code'=>$code,'buy_status'=>'paid','shelf_status'=>'on','enable_status'=>'enabled','create_time'=>time(),'update_time'=>time()]);
    }
    foreach(['supplier','api']as$role){
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);$urls[$role]='http://'.$address;
        $pipes=[];$processes[$role]=proc_open([PHP_BINARY,'-d','disable_functions=','-S',$address,__FILE__],[0=>['pipe','r'],1=>['file',$dir.'/'.$role.'.log','a'],2=>['file',$dir.'/'.$role.'.log','a']],$pipes,dirname(__DIR__),array_merge(getenv(),['AIGC_HTTP_FIXTURE_PREFIX'=>$prefix,'AIGC_HTTP_FIXTURE_DIR'=>$dir,'AIGC_HTTP_FIXTURE_ROLE'=>$role]));fclose($pipes[0]);
        MarketWorkerFixture::check(is_resource($processes[$role]),'Cannot start private HTTP server');
    }
    if(getenv('AIGC_HTTP_DEEP_MATRIX')==='1')file_put_contents($dir.'/supplier-url',$urls['supplier']);
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
    MarketWorkerFixture::check(($query['generation_task']['task_ref']??'')===$report['accepted_task_ref']&&$query['generation_task']['status']==='success'&&(int)$query['task_id']===$consumption,'Canvas HTTP query lost canonical results');
    if(getenv('AIGC_HTTP_FIXTURE_SKIP_DRAMA')!=='1') {
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
    }
    $userBefore=(float)Db::name('user')->where('id',$user)->value('user_money');$tenantBefore=(float)Db::name('tenant')->where('id',$tenant)->value('point_balance');
    file_put_contents($dir.'/synthetic.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII='));
    $referenceLimit=getenv('AIGC_HTTP_TOOL_MATRIX')==='1'?6:3;
    $imageMeta=['max_reference_images'=>$referenceLimit,'params_schema'=>['prompt'=>['type'=>'string','required'=>true],'image_size'=>['type'=>'string','enum'=>['1K']],'aspect_ratio'=>['type'=>'string','enum'=>['1:1']],'image_urls'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>$referenceLimit]]];
    $imageProduct=app\common\model\power\PowerMarketProduct::create(['product_code'=>$prefix.'image','name'=>'Private synthetic image','resource_type'=>'model','model_type'=>'image','source_code'=>'isolated-fixture','upstream_resource_key'=>$prefix.'image','upstream_model_code'=>'synthetic-image','upstream_channel_code'=>'fixture','source_payload'=>['market_metadata'=>$imageMeta],'status'=>1,'create_time'=>time(),'update_time'=>time()]);
    $imageSku=app\common\model\power\PowerMarketSku::create(['product_id'=>$imageProduct['id'],'sku_key'=>$prefix.'image-sku','title'=>'Private image fixture','locked_params'=>['image_size'=>'1K','aspect_ratio'=>'1:1'],'usage_unit'=>'per_call','usage_unit_size'=>1,'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1,'source_payload'=>['meter_contract'=>['version'=>1,'basis'=>'call','rounding'=>'ceil']],'create_time'=>time(),'update_time'=>time()]);
    $channel='market_image_model:'.$imageProduct['id'];
    $nativeImage=dataHttp($api,'/api/app.aigc_image.generate/index'.$suffix,['channel'=>$channel,'quality'=>'1k','ratio'=>'1:1','quantity'=>1,'prompt'=>'Synthetic block'], $token);
    MarketWorkerFixture::check(($nativeImage['generation_task']['schema_version']??0)===1,'Native image accepted task lacks canonical view');
    $fixture->start(__FILE__,'aigc_image');
    MarketWorkerFixture::wait(fn()=>Db::name('aigc_image_task')->where('id',$nativeImage['task_id'])->value('status')==='success'&&View::read((int)$nativeImage['generation_task']['app_task_id'],$tenant,$user)['status']==='success','Native image worker did not finish',30);$fixture->stop();
    $imageView=View::read((int)$nativeImage['generation_task']['app_task_id'],$tenant,$user);
    MarketWorkerFixture::check($imageView['status']==='success'&&count($imageView['results'])===1&&hash_file('sha256',$dir.'/public/'.$imageView['results'][0]['uri'])===hash_file('sha256',$dir.'/synthetic.png'),'Native image managed result mismatch');
    $imageUri=$imageView['results'][0]['uri'];
    app\common\service\app\aigc_product_image\AigcProductImageService::saveConfig($tenant,['status'=>1,'config_json'=>['channel'=>$channel,'quality'=>'1k','unit_price'=>3]]);
    $single=dataHttp($api,'/api/app.aigc_product_image.generate/index'.$suffix,['product_image'=>$imageUri,'custom_scene_image'=>$imageUri,'scene_mode'=>'custom','channel'=>$channel,'quality'=>'1k','ratio'=>'1:1','size_key'=>'1:1','prompt'=>'Synthetic product'], $token);
    MarketWorkerFixture::check(count($single['generation_tasks']??[])===1,'Single application did not expose its owned generation child');
    $fixture->start(__FILE__,'aigc_image');
    $singleNative=(int)$single['image_task_id'];MarketWorkerFixture::wait(fn()=>Db::name('aigc_image_task')->where('id',$singleNative)->value('status')==='success'&&View::read((int)$single['generation_tasks'][0]['app_task_id'],$tenant,$user)['status']==='success','Single image application child did not complete',30);$fixture->stop();
    $singleFinal=dataHttp($api,'/api/app.aigc_product_image.task/detail'.$suffix.'&id='.$single['task_id'],[],$token,'GET');
    MarketWorkerFixture::check($singleFinal['status']==='success'&&count($singleFinal['results'])===1&&$singleFinal['generation_tasks'][0]['status']==='success','Single app business result not reconciled '.json_encode(['status'=>$singleFinal['status'],'results'=>count($singleFinal['results']),'children'=>array_map(static fn($t)=>[$t['status'],$t['stage']],$singleFinal['generation_tasks'])]));
    app\common\service\app\aigc_product_suite\AigcProductSuiteService::saveConfig($tenant,['status'=>1,'default_channel'=>$channel,'default_quality'=>'1k','default_ratio'=>'1:1','unit_price'=>3]);
    file_put_contents($dir.'/hold-from-index',(string)(count(file($dir.'/submissions.jsonl'))+2));
    $suite=dataHttp($api,'/api/app.aigc_product_suite.generate/index'.$suffix,['product_images'=>[$imageUri],'channel'=>$channel,'quality'=>'1k','ratio'=>'1:1','module_codes'=>['hero','detail'],'prompt'=>'Synthetic product'], $token);
    MarketWorkerFixture::check(count($suite['image_task_ids']??[])===2&&count($suite['generation_tasks']??[])===2,'Suite did not keep two ordered generation children');
    $firstChild=(int)$suite['image_task_ids'][0];$secondChild=(int)$suite['image_task_ids'][1];$fixture->start(__FILE__,'aigc_image');
    MarketWorkerFixture::wait(fn()=>Db::name('aigc_image_task')->where('id',$firstChild)->value('status')==='success'&&View::read((int)$suite['generation_tasks'][0]['app_task_id'],$tenant,$user)['status']==='success','First suite child not completed',30);
    $partial=dataHttp($api,'/api/app.aigc_product_suite.task/detail'.$suffix.'&id='.$suite['task_id'],[],$token,'GET');
    MarketWorkerFixture::check($partial['status']==='running'&&$partial['generation_tasks'][0]['status']==='success'&&$partial['generation_tasks'][1]['status']==='running','First child incorrectly completed its parent');
    unlink($dir.'/hold-from-index');MarketWorkerFixture::wait(fn()=>Db::name('aigc_image_task')->where('id',$secondChild)->value('status')==='success'&&View::read((int)$suite['generation_tasks'][1]['app_task_id'],$tenant,$user)['status']==='success','Second suite child did not finish',30);
    MarketWorkerFixture::wait(fn()=>Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Image baseline result handoffs not drained',30);$fixture->stop();
    $suiteFinal=dataHttp($api,'/api/app.aigc_product_suite.task/detail'.$suffix.'&id='.$suite['task_id'],[],$token,'GET');
    MarketWorkerFixture::check($suiteFinal['status']==='success'&&count($suiteFinal['results'])===2&&array_column($suiteFinal['generation_tasks'],'status')===['success','success'],'Completed suite lost business or typed child results');
    $userAfter=(float)Db::name('user')->where('id',$user)->value('user_money');$tenantAfter=(float)Db::name('tenant')->where('id',$tenant)->value('point_balance');
    MarketWorkerFixture::check($userBefore-$userAfter===10.&&$tenantBefore-$tenantAfter===4.,'Native/application retail overrides charged incorrect fixture amounts');
    $report['image_http']=['native_managed_result_checksum'=>true,'single_application_parent_and_child_success'=>true,'suite_first_child_does_not_finish_parent'=>true,'suite_two_children_two_results'=>true,'native_price'=>1,'application_retail_price_per_child'=>3,'fixture_user_delta'=>10,'fixture_tenant_delta'=>4];
    $report['total_local_supplier_submissions']=count(file($dir.'/submissions.jsonl'));$report['total_fixture_user_balance']=$userAfter;$report['total_fixture_tenant_balance']=$tenantAfter;
    if(getenv('AIGC_HTTP_TOOL_MATRIX')==='1') {
        require __DIR__.'/Support/MarketToolHttpMatrix.php';
        $report['tool_matrix']=[];
        runMarketToolHttpMatrix($fixture,$api,$suffix,$token,$channel,$imageUri,$report['tool_matrix']);
        $report['total_local_supplier_submissions']=count(file($dir.'/submissions.jsonl'));
        $report['total_fixture_user_balance']=(float)Db::name('user')->where('id',$user)->value('user_money');
        $report['total_fixture_tenant_balance']=(float)Db::name('tenant')->where('id',$tenant)->value('point_balance');
    }
    if(getenv('AIGC_HTTP_STAGE_MATRIX')==='1') {
        require __DIR__.'/Support/MarketStageHttpMatrix.php';$report['stage_matrix']=[];
        runMarketStageHttpMatrix($fixture,$api,$suffix,$token,$imageUri,$urls['supplier'],$report['stage_matrix']);
        $report['total_local_supplier_submissions']=count(file($dir.'/submissions.jsonl'));
        $report['total_fixture_user_balance']=(float)Db::name('user')->where('id',$user)->value('user_money');
        $report['total_fixture_tenant_balance']=(float)Db::name('tenant')->where('id',$tenant)->value('point_balance');
    }
    if(getenv('AIGC_HTTP_DEEP_MATRIX')==='1') {
        require __DIR__.'/Support/MarketDeepHttpMatrix.php';$report['deep_matrix']=[];
        runMarketDeepHttpMatrix($fixture,$api,$suffix,$token,$imageUri,$urls['supplier'],$report['deep_matrix']);
    }
    $beforeRestart=[];foreach(['ai_app_task','ai_consumption_log','ai_task_result_asset']as$table)$beforeRestart[$table]=Db::name($table)->order('id')->select()->toArray();
    $pendingJobs=(int)Db::name('ai_task_job')->where('status','<>','success')->count();
    $fixture->start(__FILE__,'aigc_image');
    MarketWorkerFixture::wait(fn()=>Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Restart did not complete pending result handoffs',30);$fixture->stop();
    $afterRestart=[];foreach(array_keys($beforeRestart)as$table)$afterRestart[$table]=Db::name($table)->order('id')->select()->toArray();
    MarketWorkerFixture::check($beforeRestart===$afterRestart,'Restart changed completed task, billing or stored asset records');
    $afterRestart['ai_task_job']=Db::name('ai_task_job')->order('id')->select()->toArray();
    $fixture->start(__FILE__,'aigc_image');usleep(1200000);$fixture->stop();
    $idleRestart=[];foreach(array_keys($afterRestart)as$table)$idleRestart[$table]=Db::name($table)->order('id')->select()->toArray();
    MarketWorkerFixture::check($afterRestart===$idleRestart,'Idle restart rewrote completed task, billing, asset or job records');
    $report['unfinished_jobs_completed_on_restart']=$pendingJobs;
    $eventCounts=array_count_values(Db::name('ai_consumption_event')->column('event_type'));$acceptedCount=count(file($dir.'/submissions.jsonl'));
    MarketWorkerFixture::check(($eventCounts['reserve']??0)===$acceptedCount&&($eventCounts['settle']??0)===$acceptedCount+($report['deep_matrix']['text_settlements']??0),'Final accepted tasks did not each have exactly one reservation and settlement');
    $report['total_fixture_user_balance']=(float)Db::name('user')->where('id',$user)->value('user_money');$report['total_fixture_tenant_balance']=(float)Db::name('tenant')->where('id',$tenant)->value('point_balance');
    $report['worker_restart_preserved_completed_rows']=true;$report['total_reserve_events']=$eventCounts['reserve'];$report['total_settle_events']=$eventCounts['settle'];
    $report['total_tasks']=(int)Db::name('ai_app_task')->count();$report['total_result_assets']=(int)Db::name('ai_task_result_asset')->count();

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
