<?php
/** One explicitly selected real owned reference audio registration; local account and cumulative cap only. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenContracts as Contracts;
use app\common\service\open_platform\OpenKeyService as Keys;
use app\common\service\open_platform\OpenContractPreview as Preview;
use app\common\service\open_platform\OpenVerification as Verification;
use app\common\service\open_platform\OpenToolRegistry as Registry;
use app\common\service\open_platform\OpenToolAdapter as Adapter;
function verify($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$db = config('database.connections.mysql');
verify(PHP_SAPI === 'cli' && $db['hostname'] === '127.0.0.1' && $db['database'] === 'z_cn', 'Local database only');
verify(($argv[1] ?? '') === '--authorized-1000', 'Explicit authorized cumulative cap required');
verify(preg_match('/^--case=(upload_audio)$/D', $argv[2] ?? '', $match) === 1, 'Select one known case; paid batching disabled');
verify(Db::name('user')->where(['id'=>1, 'tenant_id'=>1, 'account'=>'xhadmin001', 'is_disable'=>0])->find(), 'Authorized local identity mismatch');
$api = $match[1];
$definition = ['path'=>'asset/upload_upstream', 'input'=>[]];
$params = $definition['input']; $path = '/api/v1/tools/aigc_music/' . $definition['path'];
$dir = dirname(__DIR__, 2) . '/runtime/open-platform-acceptance';
if (!is_dir($dir)) mkdir($dir, 0700, true);
$lock = fopen($dir . '/paid.lock', 'c');
verify($lock && flock($lock, LOCK_EX | LOCK_NB), 'Another paid acceptance is running');
$priorKeys = S::owner('key', 1, 1)->whereLike('name', 'local_text_%')->column('id');
$priorSpent = $priorKeys ? (float)S::table('request')->whereIn('key_id', $priorKeys)->sum('actual_user') + (float)S::table('request')->whereIn('key_id', $priorKeys)->where('active', 1)->sum('budget_user') : 0.;
verify($priorSpent < 1000, 'Authorized cumulative cap exhausted');
$run = 'local_text_' . bin2hex(random_bytes(6)); $key = null; $contracts = []; $backup = [];
$report = ['run'=>$run, 'api'=>$api, 'tenant_id'=>1, 'user_id'=>1, 'cap'=>1000, 'prior_spent'=>$priorSpent];
$save = static function() use (&$report, $dir, $run) { file_put_contents($dir . '/' . $run . '.json', S::encode($report)); };
$call = static function(string $method, string $path, array $params = [], ?array $ticket = null) use (&$key): array {
    $curl = curl_init('http://127.0.0.1:19874' . $path);
    $headers = ['Authorization: Bearer ' . $key['key'], 'Content-Type: application/json'];
    if ($ticket) { $headers[] = 'Idempotency-Key: ' . $ticket['idempotency_key']; $headers[] = 'X-Open-Contract-Preview: ' . $ticket['preview_token']; }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>210, CURLOPT_HTTPHEADER=>$headers]);
    if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>S::encode($params)]);
    $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_errno($curl); curl_close($curl);
    verify($error === 0, 'Connection interrupted; original submission must be recovered without resubmitting');
    return ['status'=>$status, 'body'=>$body, 'data'=>S::json($body)];
};
try {
    foreach ([0, 1] as $tenant) {
        $backup[$tenant] = S::table('setting')->where('tenant_id', $tenant)->find();
        $settings = S::settings($tenant); unset($settings['enabled']);
        $settings['apps'] = array_values(array_unique(array_merge($settings['apps'], ['aigc_music'])));
        $settings['rpm'] = max(30, $settings['rpm']);
        $values = ['enabled'=>1, 'config'=>S::encode($settings), 'update_time'=>time()];
        if ($backup[$tenant]) S::table('setting')->where('tenant_id', $tenant)->update($values);
        else S::table('setting')->insert($values + ['tenant_id'=>$tenant, 'create_time'=>time()]);
    }
    request()->tenantId = 1; request()->userId = 1; request()->source = app\common\enum\AdminTerminalEnum::USER;
    request()->userInfo = ['tenant_id'=>1, 'user_id'=>1, 'terminal'=>4];
    $source=S::json(file_get_contents($dir.'/local_text_9598d6359932-content.json'));
    verify(($source['result']??'')==='passed' && ($source['items'][0]['result_id']??0)===39, 'Verified synthetic local source missing');
    $music=app\common\model\app\aigc_music\AigcMusicResult::where(['id'=>39,'tenant_id'=>1,'user_id'=>1,'delete_time'=>0])->findOrEmpty();
    verify(!$music->isEmpty(), 'Owned generated source missing');
    $asset=app\common\service\app\aigc_music\AigcMusicAssetService::registerExisting(1,1,['uri'=>(string)$music['audio_uri'],'title'=>'Synthetic acceptance reference audio']);
    $params=['reference_asset_id'=>(int)$asset['id']];$report['reference_asset_id']=$params['reference_asset_id'];$report['source_result_id']=39;$save();
    $operation = Registry::match('POST', $path);
    $budget = Adapter::budget(request(), $operation, $params);
    verify($budget['user'] > 0 && $budget['user'] <= 20 && $budget['tenant'] > 0 && $budget['tenant'] <= 20, 'Unexpected operation price');
    verify($priorSpent + $budget['user'] <= 1000, 'Authorized cumulative cap would be exceeded');
    $report['quote'] = $budget['user'];
    $key = Keys::create(1, 1, ['name'=>$run, 'daily_limit'=>1000-$priorSpent, 'monthly_limit'=>1000-$priorSpent, 'scopes'=>['aigc_music','assets'], 'ip_allowlist'=>['127.0.0.1/32'], 'expires_at'=>time()+1800]);
    $schema = ['type'=>'object', 'required'=>array_keys($params), 'properties'=>[], 'additionalProperties'=>false];
    foreach ($params as $field=>$value) $schema['properties'][$field] = is_int($value)?['type'=>'integer','minimum'=>1]:['type'=>'string', 'minLength'=>1, 'maxLength'=>20000];
    $draft = Contracts::save(['code'=>$run . '.music.' . $api, 'kind'=>'tool', 'method'=>'POST', 'path'=>$path, 'config'=>[
        'title'=>'Local music reference registration operation acceptance', 'role'=>'submit', 'input'=>$schema, 'example'=>$params,
        'responses'=>['200'=>['description'=>'Accepted native music reference registration operation', 'content'=>['application/json'=>['schema'=>['type'=>'object', 'required'=>['request_id','data'], 'properties'=>['request_id'=>['type'=>'string'], 'data'=>['type'=>'object', 'required'=>['task_id','status','billing_status']]]]]]]],
    ]]);
    $contracts[] = $draft['id']; $ticket = Preview::issue($draft['id'], $key['id']);
    $save();
    $wire = $call('POST', $path, $params, $ticket);
    $row = S::owner('request', 1, 1)->where(['key_id'=>$key['id'], 'idempotency_hash'=>hash('sha256', 'POST ' . $path . ' ' . $ticket['idempotency_key'])])->find();
    verify($row, 'Rejected before tracked gateway admission (HTTP ' . $wire['status'] . ')');
    $accepted = $wire['data']['data'] ?? []; $taskId = $accepted['task_id'] ?? '';
    $report += ['request_id'=>$row['request_id'], 'contract_id'=>$draft['id'], 'task_id'=>$taskId, 'http_status'=>$wire['status']]; $save();
    verify($wire['status']===200 && $taskId!=='', 'Export was not accepted; recover original request without resubmitting');
    $data=$accepted;
    for($poll=0;$poll<36;$poll++) {
        $owned=$call('GET','/api/v1/tools/aigc_music/tasks/'.$taskId);
        $data=$owned['data']['data'] ?? [];
        if($owned['status']===200 && in_array($data['status']??'', ['success','failed'],true))break;
        $report['polls']=$poll+1;$report['last_status']=$data['status']??'query_unavailable';$save();
        sleep(5);
    }
    $row=S::table('request')->where('request_id',$row['request_id'])->find();
    app\common\service\open_platform\OpenRecovery::refresh($row);
    $row=S::table('request')->where('request_id',$row['request_id'])->find();
    $report += ['status'=>$row['status'],'billing_status'=>$row['billing_status'],'charged'=>(float)$row['actual_user']];$save();
    verify($row['status']==='success' && $row['billing_status']==='settled', 'Export remains unverified; preserve original task and reserve');
    verify((float)$row['actual_user']===(float)$budget['user'], 'Export charge differs from accepted price');
    $ids=S::json($row['consumption_ids']);verify(count($ids)===1,'One native consumption required');
    $native=Db::name('aigc_music_task')->where(['tenant_id'=>1,'user_id'=>1,'consumption_id'=>$ids[0],'provider'=>'market_music_operation','action'=>$api])->find();
    verify($native && $native['status']==='success' && $native['billing_status']==='settled','Native export not settled');
    verify(is_string($data['reference_audio_id']??null) && preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$data['reference_audio_id']), 'Missing real upstream audio registration');
    $bindings=app\common\service\app\aigc_music\AigcMusicService::referenceBindings(1,1,['audio_id'=>$data['reference_audio_id']]);
    verify(($bindings['audio_id']??'')===$data['reference_audio_id'], 'Registered audio cannot be resolved for owned generation');
    $report+=['provider_reference_present'=>true,'owned_generation_binding'=>'passed'];
    $ledgerCount=Db::name('user_account_log')->where(['tenant_id'=>1,'user_id'=>1])->count();
    $replay=$call('POST',$path,$params,$ticket);
    verify($replay['status']===$wire['status'] && $replay['body']===$wire['body'],'Replay did not preserve original acceptance');
    verify(Db::name('user_account_log')->where(['tenant_id'=>1,'user_id'=>1])->count()===$ledgerCount,'Replay charged again');
    $owned=$call('GET','/api/v1/tools/aigc_music/tasks/'.$taskId);
    verify($owned['status']===200 && ($owned['data']['data']['status']??'')==='success','Terminal owned query failed');
    verify((float)S::table('request')->where(['key_id'=>$key['id'],'method'=>'GET'])->sum('actual_user')===0.,'Queries incurred another charge');
    $verified=Contracts::detail($draft['id']);$verified['config']['verification']=['request_id'=>$row['request_id']];
    $proof=Verification::assertReceipt($verified);
    $report+=['native_task_id'=>$native['id'],'consumption_id'=>$ids[0],'replay'=>'identical_no_extra_charge','owned_query'=>'same_output_no_charge','contract_digest'=>$proof['contract_digest'],'publication_proof'=>'exact_example_and_independent_ledgers'];
    $report['result'] = 'passed';
} catch (Throwable $error) { $report['result'] = 'failed'; $report['failure'] = $error->getMessage(); }
finally {
    if ($key) {
        S::table('key')->where('id', $key['id'])->update(['status'=>-1, 'update_time'=>time()]);
        $report['charged'] = (float)S::table('request')->where('key_id', $key['id'])->sum('actual_user');
        $report['pending_budget'] = (float)S::table('request')->where(['key_id'=>$key['id'], 'active'=>1])->sum('budget_user');
    }
    foreach ($contracts as $id) S::table('contract')->where('id', $id)->update(['status'=>'archived', 'update_time'=>time()]);
    foreach ($backup as $tenant=>$row) {
        if ($row) S::table('setting')->where('tenant_id', $tenant)->update(['enabled'=>$row['enabled'], 'config'=>$row['config'], 'update_time'=>time()]);
        else S::table('setting')->where('tenant_id', $tenant)->delete();
    }
    $report['cumulative_spent'] = round($priorSpent + ($report['charged'] ?? 0) + ($report['pending_budget'] ?? 0), 2);
    $report['settings_restored'] = true; $report['key_revoked'] = $key !== null; $save();
}
echo S::encode($report), "\n";
exit($report['result'] === 'passed' ? 0 : 1);
