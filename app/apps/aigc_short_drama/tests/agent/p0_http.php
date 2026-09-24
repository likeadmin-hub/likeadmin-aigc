<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use think\facade\Db;

function fixtureHttp(string $action, string $method = 'GET', array $body = [], string $token = 'isolated-http-fixture', int $tenant = 94001): array
{
    $url = 'http://127.0.0.1:19080/api/app.aigc_short_drama.canvas/' . $action . '?tenant_id=' . $tenant;
    if ($method === 'GET') $url .= '&' . http_build_query($body);
    $context = stream_context_create(['http' => ['method' => $method, 'timeout' => 10, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\ntoken: " . $token . "\r\n",
        'content' => $method === 'POST' ? json_encode($body) : '',
    ]]);
    $raw = file_get_contents($url, false, $context);
    $result = json_decode((string)$raw, true);
    if (!is_array($result)) {
        preg_match_all('#<(?:h1|h2|p)[^>]*>(.*?)</(?:h1|h2|p)>#s', (string)$raw, $messages);
        throw new RuntimeException('Invalid HTTP response: ' . substr(strip_tags(implode(' ', $messages[1])), 0, 1600));
    }
    return $result;
}

$inserted = [];
$process = null;
$canvasIds = [];
try {
    // Explicit ownership: never take over or delete pre-existing fixture rows.
    if (Db::name('tenant')->where('id', 94001)->count() || Db::name('user')->where('id', 95001)->count() || Db::name('app')->where('code', 'aigc_short_drama')->count()) {
        throw new RuntimeException('HTTP fixture scope is not empty');
    }
    foreach ([
        ['tenant', ['id' => 94001, 'sn' => 'http-fixture', 'name' => 'Isolated HTTP', 'create_time' => time(), 'delete_time' => null]],
        ['user', ['id' => 95001, 'sn' => 95001, 'account' => 'http-fixture', 'tenant_id' => 94001]],
        ['user_session', ['tenant_id' => 94001, 'user_id' => 95001, 'token' => 'isolated-http-fixture', 'terminal' => 4, 'expire_time' => time() + 86400 * 365]],
        ['app', ['code' => 'aigc_short_drama', 'status' => 'installed']],
        ['tenant_app', ['tenant_id' => 94001, 'app_code' => 'aigc_short_drama', 'buy_status' => 'paid', 'enable_status' => 'enabled', 'shelf_status' => 'on', 'expire_time' => time() + 3600]],
    ] as [$table, $row]) $inserted[] = [$table, Db::name($table)->insertGetId($row)];
    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:19080', '-t', app()->getRootPath() . 'public', __DIR__ . '/http_router.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated HTTP process');
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 19080, $errno, $error, 0.1);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('HTTP process not ready');
    $anonymous = fixtureHttp('lists', 'GET', [], '');
    agentCheck($anonymous['code'] !== 1, 'HTTP route rejects missing token');
    $created = fixtureHttp('create', 'POST', ['title' => 'HTTP four-node fixture']);
    if (($created['code'] ?? 0) !== 1) throw new RuntimeException('HTTP create failed: ' . json_encode($created, JSON_UNESCAPED_UNICODE));
    $id = (int)$created['data']['id'];
    $canvasIds[] = $id;
    agentCheck($id > 0, 'HTTP actual route creates isolated owned canvas');
    $nodes = [];
    foreach (['text', 'image', 'video', 'audio'] as $index => $type) $nodes[] = ['id' => $index + 1, 'type' => $type, 'x' => $index * 300, 'y' => 20, 'width' => 250, 'height' => 220, 'metadata' => ['content' => 'fixture-' . $type]];
    $saved = fixtureHttp('save', 'POST', ['id' => $id, 'nodes' => $nodes, 'edges' => [['from' => 1, 'to' => 2]], 'expected_document_token' => $created['data']['document_token'], 'expected_revision' => $created['data']['graph_revision']]);
    agentCheck($saved['code'] === 1, 'HTTP real middleware/controller saves four nodes with content token');
    agentCheck($saved['data']['graph_revision']===1 && $saved['data']['schema_version']===2, 'HTTP numeric revision passes real request filter and advances graph');
    $loaded = fixtureHttp('current', 'GET', ['id' => $id]);
    agentCheck($loaded['code'] === 1 && count($loaded['data']['nodes']) === 4 && $loaded['data']['nodes'] === $saved['data']['nodes'], 'HTTP reload preserves four-node response');
    $conflict = fixtureHttp('save', 'POST', ['id' => $id, 'nodes' => [], 'expected_document_token' => $created['data']['document_token']]);
    agentCheck($conflict['code'] !== 1 && str_starts_with($conflict['msg'], 'VERSION_CONFLICT'), 'HTTP stale snapshot returns conflict instead of overwriting');
    $conflict = fixtureHttp('save', 'POST', ['id'=>$id,'nodes'=>[],'expected_revision'=>0]);
    agentCheck($conflict['code']!==1 && str_starts_with($conflict['msg'],'VERSION_CONFLICT'), 'HTTP stale numeric revision cannot bypass graph CAS');
    $wrongTenant = fixtureHttp('current', 'GET', ['id' => $id], 'isolated-http-fixture', 94002);
    agentCheck($wrongTenant['code'] !== 1, 'HTTP tenant resolver rejects unowned tenant context');
    agentCheck($loaded['data']['agent_enabled']===false,'Agent is off without an explicit tenant configuration');
    $patch=['canvas_id'=>$id,'request_key'=>'http-patch','expected_revision'=>1,'operations'=>[['op'=>'move_nodes','nodes'=>[['id'=>1,'x'=>777,'expected_layout_revision'=>$saved['data']['nodes'][0]['metadata']['layout_revision']]]]]];
    $disabled=fixtureHttp('patch','POST',$patch+['agent_enabled'=>true]);
    agentCheck($disabled['code']!==1 && $disabled['msg']==='CANVAS_AGENT_DISABLED','request body cannot enable disabled Agent graph writes');
    if (Db::name('aigc_short_drama_config')->where('tenant_id',94001)->count()) throw new RuntimeException('Unexpected existing tenant Agent configuration');
    $configId=Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>94001,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);
    $inserted[]=['aigc_short_drama_config',$configId];
    $patched=fixtureHttp('patch','POST',$patch);
    if ($patched['code']!==1) echo 'SYNTHETIC_PATCH_FAILURE ',json_encode($patched),PHP_EOL;
    // Existing ThinkPHP numeric-string geometry contract is preserved.
    agentCheck($patched['code']===1 && $patched['data']['graph_revision']===2 && $patched['data']['nodes'][0]['x']==='777','explicit isolated opt-in permits authenticated graph patch');
    $stable=true;
    for ($i=0;$i<10;$i++) $stable=$stable && fixtureHttp('patch','POST',$patch)['data']===$patched['data'];
    agentCheck($stable && Db::name('aigc_short_drama_canvas_mutation_receipt')->where('canvas_id',$id)->count()===1,'ten real HTTP patch retries return one stable receipt');
    $changed=$patch;$changed['operations'][0]['nodes'][0]['x']=999;
    $changedResult=fixtureHttp('patch','POST',$changed);
    agentCheck($changedResult['code']!==1 && $changedResult['msg']==='IDEMPOTENCY_CONFLICT','HTTP changed payload cannot reuse patch key');
    agentCheck(fixtureHttp('patch','POST',$patch,'')['code']!==1,'Agent patch requires authenticated user');
    agentCheck(fixtureHttp('patch','POST',$patch,'isolated-http-fixture',94002)['code']!==1,'Agent patch rejects foreign tenant context');
    Db::name('aigc_short_drama_config')->where('id',$configId)->update(['config_json'=>'{"canvas_agent":{"enabled":false}}']);
    $disabled=fixtureHttp('patch','POST',$patch);
    agentCheck($disabled['code']!==1 && $disabled['msg']==='CANVAS_AGENT_DISABLED','disabling Agent also stops graph receipt replay entry');
    $manual=fixtureHttp('save','POST',['id'=>$id,'nodes'=>$patched['data']['nodes'],'edges'=>$patched['data']['edges'],'expected_revision'=>2]);
    agentCheck($manual['code']===1 && $manual['data']['agent_enabled']===false,'manual save remains available with Agent explicitly disabled');
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
    }
    foreach ($canvasIds as $id) {
        Db::name('aigc_short_drama_canvas_mutation_receipt')->where(['canvas_id'=>$id,'tenant_id'=>94001,'user_id'=>95001])->delete();
        Db::name('aigc_short_drama_canvas')->where(['id' => $id, 'tenant_id' => 94001, 'user_id' => 95001])->delete();
    }
    foreach (array_reverse($inserted) as [$table, $id]) Db::name($table)->where('id', $id)->delete();
}
echo "NOT_RUN browser-to-database generation; HTTP fixture only permits read/create/save/list\n";
