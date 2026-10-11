<?php
/** One explicitly selected real music text operation; local account and cumulative cap only. */
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
verify(preg_match('/^--case=(lyrics|mashup_lyrics|style)$/D', $argv[2] ?? '', $match) === 1, 'Select one known case; paid batching disabled');
verify(Db::name('user')->where(['id'=>1, 'tenant_id'=>1, 'account'=>'xhadmin001', 'is_disable'=>0])->find(), 'Authorized local identity mismatch');
$api = $match[1];
$definition = [
    'lyrics'=>['path'=>'lyrics/index', 'input'=>['prompt'=>'Write four short English lines about sunrise for a synthetic software acceptance test.']],
    'mashup_lyrics'=>['path'=>'mashuplyrics/index', 'input'=>['lyrics_a'=>'Morning sunlight warms the sea. A small bird sings beside a tree.', 'lyrics_b'=>'Stars fade softly into blue. Every dawn brings something new.']],
    'style'=>['path'=>'style/index', 'input'=>['prompt'=>'Gentle instrumental piano pop, warm acoustic sound, a calm morning mood.']],
][$api];
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
    $operation = Registry::match('POST', $path);
    $budget = Adapter::budget(request(), $operation, $params);
    verify($budget['user'] > 0 && $budget['user'] <= 20 && $budget['tenant'] > 0 && $budget['tenant'] <= 20, 'Unexpected operation price');
    verify($priorSpent + $budget['user'] <= 1000, 'Authorized cumulative cap would be exceeded');
    $report['quote'] = $budget['user'];
    $key = Keys::create(1, 1, ['name'=>$run, 'daily_limit'=>1000-$priorSpent, 'monthly_limit'=>1000-$priorSpent, 'scopes'=>['aigc_music'], 'ip_allowlist'=>['127.0.0.1/32'], 'expires_at'=>time()+600]);
    $schema = ['type'=>'object', 'required'=>array_keys($params), 'properties'=>[], 'additionalProperties'=>false];
    foreach ($params as $field=>$value) $schema['properties'][$field] = ['type'=>'string', 'minLength'=>1, 'maxLength'=>20000];
    $draft = Contracts::save(['code'=>$run . '.music.' . $api, 'kind'=>'tool', 'method'=>'POST', 'path'=>$path, 'config'=>[
        'title'=>'Local music text operation acceptance', 'role'=>'submit', 'input'=>$schema, 'example'=>$params,
        'responses'=>['200'=>['description'=>'Completed native music text operation', 'content'=>['application/json'=>['schema'=>['type'=>'object', 'required'=>['request_id','data'], 'properties'=>['request_id'=>['type'=>'string'], 'data'=>['type'=>'object', 'required'=>['task_id','status','billing_status', $api==='style'?'style':'lyrics']]]]]]]],
    ]]);
    $contracts[] = $draft['id']; $ticket = Preview::issue($draft['id'], $key['id']);
    $save();
    $wire = $call('POST', $path, $params, $ticket);
    $row = S::owner('request', 1, 1)->where(['key_id'=>$key['id'], 'idempotency_hash'=>hash('sha256', 'POST ' . $path . ' ' . $ticket['idempotency_key'])])->find();
    verify($row, 'Rejected before tracked gateway admission (HTTP ' . $wire['status'] . ')');
    $data = $wire['data']['data'] ?? []; $field = $api === 'style' ? 'style' : 'lyrics';
    $report += ['request_id'=>$row['request_id'], 'contract_id'=>$draft['id'], 'task_id'=>$data['task_id'] ?? '', 'http_status'=>$wire['status'], 'status'=>$row['status'], 'billing_status'=>$row['billing_status'], 'charged'=>(float)$row['actual_user']]; $save();
    verify($wire['status'] === 200 && $row['status'] === 'success' && $row['billing_status'] === 'settled', 'Real operation did not complete and settle');
    verify(is_string($data[$field] ?? null) && trim($data[$field]) !== '', 'Native output missing');
    verify((float)$row['actual_user'] === (float)$budget['user'], 'Settled retail disagrees with accepted quote');
    $ids = S::json($row['consumption_ids']); verify(count($ids) === 1, 'Exactly one native consumption expected');
    $native = Db::name('aigc_music_task')->where(['tenant_id'=>1, 'user_id'=>1, 'consumption_id'=>$ids[0], 'provider'=>'market_music_operation', 'action'=>$api])->find();
    verify($native && $native['status'] === 'success' && $native['billing_status'] === 'settled', 'Native task and API disagree');
    $ledgerCount = Db::name('user_account_log')->where(['tenant_id'=>1, 'user_id'=>1])->count();
    $replay = $call('POST', $path, $params, $ticket);
    verify($replay['status'] === $wire['status'] && $replay['body'] === $wire['body'], 'Idempotent response changed');
    verify(Db::name('user_account_log')->where(['tenant_id'=>1, 'user_id'=>1])->count() === $ledgerCount, 'Replay created another user ledger entry');
    $owned = $call('GET', '/api/v1/tools/aigc_music/tasks/' . $data['task_id']);
    verify($owned['status'] === 200 && ($owned['data']['data'][$field] ?? null) === $data[$field], 'Owned query changed native output');
    verify((float)S::table('request')->where(['key_id'=>$key['id'], 'method'=>'GET'])->sum('actual_user') === 0., 'Query incurred additional charge');
    $verified = Contracts::detail($draft['id']); $verified['config']['verification'] = ['request_id'=>$row['request_id']];
    $proof = Verification::assertReceipt($verified);
    $report += ['output_field'=>$field, 'output_length'=>mb_strlen($data[$field]), 'native_task_id'=>$native['id'], 'consumption_id'=>$ids[0], 'replay'=>'identical_no_extra_charge', 'owned_query'=>'same_output_no_charge', 'contract_digest'=>$proof['contract_digest'], 'publication_proof'=>'exact_example_and_independent_ledgers'];
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
