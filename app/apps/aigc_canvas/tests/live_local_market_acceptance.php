<?php
/** Local, explicitly paid acceptance. Default `plan` never submits. No automatic resubmission. */
require dirname(__DIR__, 4) . '/vendor/autoload.php';
(new think\App())->initialize();
use app\common\service\power\{MarketTextModelRuntimeService as Text, MarketImageModelRuntimeService as Image, MarketVideoRuntimeService as Video, MarketMusicAppRuntimeService as Music, MarketNanoBananaAppRuntimeService as Nano};
use app\common\service\app\aigc_canvas\{AigcCanvasService as Canvas};
use app\common\service\app\aigc_canvas\agent\model\CanvasModelRouterService as Router;
use think\facade\Db;
$db=config('database.connections.mysql');
if ($db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn','z_cn'], true)) throw new RuntimeException('Local database only');
$action=$argv[1]??'plan'; $tenant=(int)($argv[2]??0); $user=(int)($argv[3]??0); $referenceProject=(int)($argv[4]??0);
if (!$tenant || !$user || !$referenceProject) throw new RuntimeException('Usage: plan|init|submit|poll|report tenant user reference-project [entry-index] [--allow-paid]');
$dir=root_path().'runtime/canvas-acceptance-'.$tenant.'-'.$user.'-'.$referenceProject;
if (!is_dir($dir)) mkdir($dir,0700,true);
$read=static fn($name)=>json_decode(file_get_contents($dir.'/'.$name.'.json'),true,512,JSON_THROW_ON_ERROR);
$write=static function($name,$data)use($dir){file_put_contents($dir.'/'.$name.'.tmp',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));rename($dir.'/'.$name.'.tmp',$dir.'/'.$name.'.json');};
$print=static function($data){echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;};
$invoke=static fn($class,$method,...$args)=>(new ReflectionMethod($class,$method))->invoke(null,...$args);
if ($action==='plan') {
    $source=Canvas::projectDetail($tenant,$user,$referenceProject); $image='';
    foreach($source['nodes'] as $node) if (($node['type']??'')==='image' && preg_match('~^https://~',$node['metadata']['image']??'')) { $image=$node['metadata']['image']; break; }
    if (!$image) throw new RuntimeException('Reference project needs a durable uploaded image');
    $groups=['text'=>['options'=>Text::textOptions($tenant)]]+Router::marketOverview($tenant);$entries=[];
    foreach($groups as $type=>$group) foreach($group['options'] as $o) {
        $candidates=[]; $errors=[];
        foreach($o['skus']??[$o] as $sku) {
            $ratios=$sku['ratio_options']??$o['ratio_options']??[];$preferred=$type==='image'?'1:1':'16:9';
            $durations=array_filter($sku['duration_options']??$o['duration_options']??[],fn($v)=>is_numeric($v)&&$v>0);
            if (!$durations) $durations=array_filter($o['duration_options']??[],fn($v)=>is_numeric($v)&&$v>0);
            $p=['channel'=>$o['id'],'model_code'=>$o['model_code']??'','market_product_id'=>$o['market_product_id']??0,'market_sku_id'=>$sku['market_sku_id']??0,
                'resource_type'=>$o['resource_type']??'','model_selection_explicit'=>true,'quantity'=>1,
                'quality'=>$sku['quality']??$sku['resolution']??$o['default_quality']??'', 'resolution'=>$sku['resolution']??'',
                'ratio'=>in_array($preferred,$ratios,true)?$preferred:($ratios[0]??$preferred),
                'duration'=>($sku['duration']??0)>0?$sku['duration']:($durations?min($durations):($o['default_duration']??5)),
                'prompt'=>'Create a calm studio scene with a small ceramic cup placed on a pale wooden table. Soft natural daylight enters from the left. Keep the background uncluttered and warm, with gentle shadows and a balanced composition. Use realistic material detail, subtle depth of field, and a fixed camera. No people, no text, no logos, and no sudden motion.'];
            try {
                if($type==='text') { $p=['model_code'=>$o['id'],'prompt'=>'Reply with exactly OK.','max_tokens'=>64];$quote=['user_charge_points'=>null]; }
                elseif($type==='image') { $runtime=Nano::isSelection($p)?Nano::class:Image::class; $quote=$runtime::quote($tenant,$p,1); }
                elseif($type==='music') {$p['prompt']='A peaceful instrumental piano melody, gentle tempo, no vocals.';$p['instrumental']=true;$quote=Music::quote($tenant,$p);}
                else {
                    $modes=$o['generation_modes']??['text_to_video'];
                    $mode=in_array('text_to_video',$modes,true)?'text_to_video':$modes[0];
                    // A reference-video SKU is a different input operation, not a cheaper t2v specification.
                    if(($sku['input_mode']??'')==='video_edit' || ($sku['pricing_variant']??'')==='with_video') continue;
                    if(($sku['input_mode']??'')==='image_reference') $mode='image_reference';
                    $p['generation_method']=$mode;
                    if($mode!=='text_to_video'){$p['reference_assets']=[['type'=>'image','url'=>$image,'role'=>$mode==='image_to_video'?'first_frame_image':'reference_image']];$p['reference_images']=[$image];if($mode==='image_to_video')$p['first_frame_image']=$image;}
                    $quote=Video::quote($tenant,$p);
                }
                $candidates[]=['request'=>$p,'quoted_points'=>$quote['user_charge_points']??null,'settlement_mode'=>$quote['settlement_mode']??$sku['settlement_mode']??'reserved'];
            } catch(Throwable $e){$errors[]=['sku'=>$p['market_sku_id'],'error'=>$e->getMessage()];}
        }
        if(!$candidates)throw new RuntimeException('No valid minimum for '.$o['id'].': '.json_encode($errors));
        usort($candidates,static fn($a,$b)=>(float)$a['quoted_points']<=>(float)$b['quoted_points'] ?: (int)filter_var($a['request']['resolution']??'',FILTER_SANITIZE_NUMBER_INT)<=>(int)filter_var($b['request']['resolution']??'',FILTER_SANITIZE_NUMBER_INT));
        $entries[]=['index'=>count($entries),'type'=>$type,'id'=>$o['id'],'name'=>$o['name']??$o['label']]+$candidates[0];
    }
    if (is_file($dir.'/project.json')) throw new RuntimeException('Plan already initialized; use its immutable snapshot');
    $write('plan',['tenant_id'=>$tenant,'user_id'=>$user,'reference_project'=>$referenceProject,'created_at'=>date('c'),'entries'=>$entries]);
    foreach($entries as $e)$print(['index'=>$e['index'],'type'=>$e['type'],'name'=>$e['name'],'sku'=>$e['request']['market_sku_id']??0,'resolution'=>$e['request']['resolution']??'','duration'=>$e['request']['duration']??0,'mode'=>$e['request']['generation_method']??'','quote'=>$e['quoted_points']]);
    exit;
}
$plan=$read('plan');
if($action==='init') {
    if(is_file($dir.'/project.json')){$print($read('project'));exit;}
    $nodes=[];
    foreach($plan['entries'] as $e){$nodes[]=['id'=>'acceptance-'.$e['index'],'type'=>$e['type']==='music'?'audio':$e['type'],'title'=>$e['name'],'position'=>['x'=>($e['index']%6)*420,'y'=>intdiv($e['index'],6)*500],'width'=>340,'height'=>280,'metadata'=>$e['request']+['status'=>'idle','content'=>'']];}
    $p=Canvas::createProject($tenant,$user,['name'=>'上架模型最小规格真实验收','nodes'=>$nodes,'edges'=>[],'viewport'=>['x'=>40,'y'=>40,'zoom'=>0.4]]);
    $write('project',['id'=>$p['id']]);$print(['project_id'=>$p['id'],'nodes'=>count($nodes)]);exit;
}
if($action==='report'){foreach($plan['entries'] as $e){$f=$dir.'/entry-'.$e['index'].'.json';$r=is_file($f)?$read('entry-'.$e['index']):[];$print(['index'=>$e['index'],'name'=>$e['name'],'type'=>$e['type'],'status'=>$r['status']??'not_submitted','task_id'=>$r['task_id']??0,'error'=>$r['error']??'','query_error'=>$r['query_error']??'','quote'=>$e['quoted_points']]);}exit;}
$index=(int)($argv[5]??-1);$entry=$plan['entries'][$index]??null;if(!$entry)throw new RuntimeException('Unknown entry index');
$lock=fopen($dir.'/entry-'.$index.'.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Entry already running');
$key='entry-'.$index;$row=is_file($dir.'/'.$key.'.json')?$read($key):[];$project=$read('project');
$updateNode=static function($row)use($tenant,$user,$project,$index,$dir){
    $lock=fopen($dir.'/project.lock','c');flock($lock,LOCK_EX);
    try{$p=Canvas::projectDetail($tenant,$user,(int)$project['id']);foreach($p['nodes'] as &$n)if($n['id']==='acceptance-'.$index){$m=&$n['metadata'];$m['status']=$row['status'];$m['pending']=in_array($row['status'],['submitting','running','pending','processing'],true);$m['taskId']=$row['task_id']??'';$m['errorDetails']=$row['error']??'';$m['generationStartedAt']=$row['started_at']*1000;
        $r=$row['result']??[];if($row['status']==='success'){
            if($n['type']==='text')$m['content']=$r['content']??$r['text']??'';
            $items=$r['images']??$r['videos']??$r['audios']??$r['tracks']??[];
            $url=$r['url']??$r['video_url']??$r['audio_url']??($items[0]['url']??$items[0]['image_url']??'');
            if(is_string($items[0]??null))$url=$items[0];
            if($url){$m['url']=$url;if($n['type']==='image')$m['image']=$url;}
            $m['poster']=$r['poster']??$r['cover']??'';
        }
    }unset($n);Canvas::saveProject($tenant,$user,['id'=>$project['id'],'nodes'=>$p['nodes'],'edges'=>$p['edges']??[],'viewport'=>$p['viewport']??[]]);}finally{flock($lock,LOCK_UN);fclose($lock);}
};
if($action==='submit'){
    if(!in_array('--allow-paid',$argv,true))throw new RuntimeException('Submission requires explicit --allow-paid authorization');
    if($row && in_array('--retry-after-fix',$argv,true)){
        if(!in_array($row['status'],['failed','submit_error','success'],true))throw new RuntimeException('Cannot retry an active or uncertain attempt');
        $write($key.'-previous-'.time(),$row);$row=[];
    }
    if($row){$print(['index'=>$index,'status'=>$row['status'],'message'=>'Existing attempt retained; no resubmission']);exit;}
    $row=['index'=>$index,'status'=>'submitting','started_at'=>time(),'task_id'=>0];$write($key,$row);$updateNode($row);
    try{$method='generate'.ucfirst($entry['type']);$r=Canvas::$method($tenant,$user,$entry['request']+['project_id'=>(int)$project['id'],'node_id'=>'acceptance-'.$index]);$row['result']=$r;$row['task_id']=$r['task_id']??$r['consumption_id']??0;$row['status']=$r['status']??($entry['type']==='text'?'success':'running');$row['error']=$r['error']??'';}
    catch(Throwable $e){$row['status']='failed';$row['submission_error']=true;$row['error']=$e->getMessage();}
}elseif($action==='poll'){
    if(empty($row['task_id'])||in_array($row['status'],['success','failed','canceled'],true)){$print(['index'=>$index,'status'=>$row['status']??'not_submitted']);exit;}
    try{$method=$entry['type'].'TaskDetail';$r=Canvas::$method($tenant,$user,(int)$row['task_id']);$row['result']=$r;$row['status']=$r['status']??'running';$row['error']=$r['error']??$r['error_message']??'';$row['query_error']='';}
    catch(Throwable $e){$row['query_error']=$e->getMessage();}
}else throw new RuntimeException('Unknown action');
$row['updated_at']=time();$write($key,$row);$updateNode($row);$print(['index'=>$index,'status'=>$row['status'],'task_id'=>$row['task_id'],'error'=>$row['error']??'','query_error'=>$row['query_error']??'']);
