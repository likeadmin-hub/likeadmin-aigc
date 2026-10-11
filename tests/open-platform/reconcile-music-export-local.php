<?php
/** Verify one existing authorized export, never create or resubmit an upstream task. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\{OpenStore as S,OpenRecovery,OpenVerification,OpenContracts,OpenToolRegistry,OpenToolAdapter,OpenToolTasks,OpenAssets,OpenSafeHttp};
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local authorized evidence only');
check(preg_match('/^--run=(local_text_[a-f0-9]{12})$/D',$argv[1]??'',$match)===1,'Select an existing acceptance run');
$run=$match[1];$dir=dirname(__DIR__,2).'/runtime/open-platform-acceptance';$source=S::json(file_get_contents($dir.'/'.$run.'.json'));
check(($source['tenant_id']??0)===1&&($source['user_id']??0)===1&&($source['cap']??0)===1000&&in_array($source['api']??'',['wav','midi','mp4','vox'],true),'Unknown authorized export report');
check(Db::name('user')->where(['tenant_id'=>1,'id'=>1,'account'=>'xhadmin001'])->find(),'Authorized identity missing');
$lock=fopen($dir.'/paid.lock','c');check($lock&&flock($lock,LOCK_EX|LOCK_NB),'Another acceptance is running');
$r=S::owner('request',1,1)->where(['request_id'=>$source['request_id'],'app_code'=>'aigc_music','method'=>'POST','path'=>'/api/v1/tools/aigc_music/export/'.$source['api']])->find();
check($r&&S::owner('key',1,1)->where(['id'=>$r['key_id'],'name'=>$run,'status'=>-1])->find(),'Not the revoked local acceptance request');
check(OpenToolTasks::decode('aigc_music',$source['task_id'])===$r['tool_task_id'],'Original task association changed');
$report=['run'=>$run,'verified_at'=>date(DATE_ATOM),'request_id'=>$r['request_id'],'task_id'=>$source['task_id'],'api'=>$source['api'],'new_submissions'=>0,'result'=>'pending'];
try{
    OpenRecovery::refresh($r);$r=S::owner('request',1,1)->where('request_id',$r['request_id'])->find();
    $report+=['status'=>$r['status'],'billing_status'=>$r['billing_status'],'charged'=>(float)$r['actual_user']];
    check($r['status']==='success'&&$r['billing_status']==='settled'&&(int)$r['active']===0,'Original export remains unverified; preserve original task');
    check((float)$r['actual_user']===(float)$source['quote'],'Original frozen export quote differs');
    OpenVerification::assertLedger($r);$contract=OpenContracts::detail($source['contract_id']);$contract['config']['verification']=['request_id'=>$r['request_id']];$proof=OpenVerification::assertReceipt($contract);
    request()->tenantId=1;request()->userId=1;request()->userInfo=['tenant_id'=>1,'user_id'=>1,'terminal'=>4];request()->source=app\common\enum\AdminTerminalEnum::USER;
    $op=OpenToolRegistry::get('aigc_music')['operations']['GET tasks/{task_id}']+['app_code'=>'aigc_music','route'=>'GET tasks/{task_id}','params'=>['task_id'=>$r['tool_task_id']],'history'=>true,'internal'=>true];
    $counts=static fn()=>[Db::name('user_account_log')->where(['tenant_id'=>1,'user_id'=>1])->count(),Db::name('tenant_point_log')->where('tenant_id',1)->count()];$before=$counts();
    $data=OpenToolTasks::publicResult('aigc_music',$op,OpenToolAdapter::call(request(),$op,[]));
    check(($data['status']??'')==='success','Owned native result query failed');
    if($source['api']==='vox')check(($data['vox_audio_id']??'')!=='','Human voice reference missing');
    else{
        $asset=OpenAssets::detail(1,1,$data['file_id']);$export=Db::name('aigc_music_export')->where(['id'=>$data['export_id'],'tenant_id'=>1,'user_id'=>1])->find();
        $stored=S::owner('file',1,1)->where('file_id',$data['file_id'])->find();
        $metadata=S::json($export['result_json']??[]);check($export&&$stored&&$stored['uri']===$export['file_uri'],'Native export and owned file differ');
        $wire=OpenSafeHttp::get($asset['url'],104857600,60);$bytes=$wire['body'];
        app\common\service\app\aigc_music\AigcMusicExportStorageService::validate($source['api'],$bytes);
        check(strlen($bytes)===(int)$metadata['file_size']&&hash('sha256',$bytes)===$metadata['sha256'],'Downloaded stored file does not match original export');
        $output=$dir.'/media-evidence';if(!is_dir($output))mkdir($output,0700,true);$file=$run.'.'.$source['api'];check(file_put_contents($output.'/'.$file,$bytes)===strlen($bytes),'Unable to save export verification bytes');
        $report+=['export_id'=>$export['id'],'file_id'=>$data['file_id'],'file_size'=>strlen($bytes),'sha256'=>$metadata['sha256'],'file'=>'media-evidence/'.$file,'content_validation'=>'stored_bytes_match_and_format_valid'];
    }
    $again=OpenToolTasks::publicResult('aigc_music',$op,OpenToolAdapter::call(request(),$op,[]));
    check(($again['file_id']??$again['vox_audio_id']??'')===($data['file_id']??$data['vox_audio_id']??'')&&$counts()===$before,'Repeated owned query changed file identity or charged again');
    $report+=['contract_digest'=>$proof['contract_digest'],'publication_proof'=>'exact_example_and_independent_ledgers','owned_query'=>'stable_no_extra_charge','submission_replay'=>'not_retested_after_original_key_revocation'];$report['result']='passed';
}catch(Throwable $e){$report['result']='failed';$report['failure']=$e->getMessage();}
file_put_contents($dir.'/'.$run.'-reconciled.json',S::encode($report));echo S::encode($report),"\n";exit($report['result']==='passed'?0:1);
