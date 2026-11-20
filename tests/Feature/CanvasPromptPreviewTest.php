<?php
// Local disposable table and fake text model. No live project/config/billing writes.
namespace app\common\service\app\aigc_canvas {
    final class AigcCanvasService {
        public const APP_CODE = 'aigc_canvas';
        public static array $config = ['router_enabled'=>true,'router_available'=>true,'router_model_code'=>'tenant-model'];
        public static array $calls = [];
        public static string $content = '{"prompt":"海边的女孩，柔和逆光，自然构图。"}';
        public static ?\Throwable $failure = null;
        public static ?\Closure $respond = null;
        public static function promptEnrichmentConfig($tenant) { return self::$config; }
        public static function llmText($tenant,$user,$params) {
            self::$calls[]=[$tenant,$user,$params];
            if (self::$failure !== null) throw self::$failure;
            $response = ['content' => self::$respond ? (self::$respond)($params) : self::$content];
            if(isset($params['_result_validator']))$params['_result_validator']($response);
            return $response;
        }
    }
}
namespace {
require dirname(__DIR__,2).'/vendor/autoload.php'; (new think\App())->initialize();
use think\facade\{Db,Config};
use app\common\service\app\aigc_canvas\AigcCanvasService as Runtime;
use app\common\service\app\aigc_canvas\prompt\CanvasPromptPreviewService as Preview;
$db=config('database.connections.mysql');
if(PHP_SAPI!=='cli'||$db['hostname']!=='127.0.0.1'||!in_array($db['database'],['x_cn','z_cn'],true))throw new \RuntimeException('Local isolated tables only');
$cfg=Config::get('database');$prefix='auditpreview_'.getmypid().'_';$table=$prefix.'aigc_canvas_project';
Db::execute("CREATE TABLE `$table` (id INT PRIMARY KEY, tenant_id INT NOT NULL, user_id INT NOT NULL, delete_time INT NOT NULL DEFAULT 0, nodes_json LONGTEXT NOT NULL)");
$cfg['connections']['mysql']['prefix']=$prefix;Config::set($cfg,'database');Db::connect(null,true);
if(Db::connect()->getConfig('prefix')!==$prefix)throw new \RuntimeException('Isolation failed');
$checks=0;
function verify($condition,$message){global $checks;if(!$condition)throw new \RuntimeException($message);$checks++;}
function refuses(callable $action,string $text){$before=count(Runtime::$calls);try{$action();}catch(\Exception $e){verify(str_contains($e->getMessage(),$text),$e->getMessage());verify(count(Runtime::$calls)===$before,'Rejected input invoked model');return;}throw new \RuntimeException('Expected rejection');}
try {
    $nodes=[['id'=>'i','type'=>'image','metadata'=>['prompt'=>'旧提示词','mentions'=>[['id'=>'same','name'=>'项目素材']],'custom'=>['enabled'=>false]]],['id'=>'v','type'=>'videoNode','data'=>['duration'=>5,'ratio'=>'16:9','mentions'=>[['id'=>'role-a','name'=>'林舟'],['id'=>'role-b','name'=>'林舟助理'],['id'=>'prop','name'=>'会议 文件']]]],['id'=>'t','type'=>'text','metadata'=>['content'=>'历史文本']]];
    Db::name('aigc_canvas_project')->insert(['id'=>2,'tenant_id'=>11,'user_id'=>22,'nodes_json'=>json_encode($nodes,JSON_UNESCAPED_UNICODE)]);
    Db::name('aigc_canvas_project')->insert(['id'=>3,'tenant_id'=>12,'user_id'=>23,'nodes_json'=>json_encode([['id'=>'i','type'=>'image']])]);
    $before=Db::name('aigc_canvas_project')->order('id')->select()->toArray();
    $params=['project_id'=>2,'node_id'=>'i','prompt'=>'海边的女孩','model_code'=>'forged','system_prompt'=>'forged'];
    $result=Preview::preview(11,22,$params);
    verify($result['prompt']==='海边的女孩，柔和逆光，自然构图。','Image preview not returned');
    $call=Runtime::$calls[0];verify($call[0]===11&&$call[1]===22,'Owner not forwarded');
    verify($call[2]['model_code']==='tenant-model'&&$call[2]['system_prompt']!=='forged','Caller overrode tenant configuration');
    verify($call[2]['action_code']==='canvas_prompt_preview'&&$call[2]['business_table']==='aigc_canvas_project','Billing trace not scoped to preview/project');
    verify($call[2]['_disable_model_fallback']&&is_callable($call[2]['_result_validator']),'Configured model or validation before settlement missing');
    verify(!array_key_exists('max_tokens', $call[2]) && !array_key_exists('request_timeout_seconds', $call[2])
        && ($call[2]['_use_provider_output_budget'] ?? false) === true,
        'Canvas preview still owns a separate output or request budget');
    verify(!str_contains($call[2]['system_prompt'], '4000'), 'Prompt optimizer still instructs the model to cap the result');
    $params['node_id']='v';Preview::preview(11,22,$params);
    $input=json_decode(Runtime::$calls[1][2]['content'],true);verify($input['type']==='video'&&$input['duration']===5&&$input['ratio']==='16:9','Legacy video settings not read');
    $textResult=Preview::preview(11,22,[...$params,'node_id'=>'t']);
    verify($textResult['type']==='text' && $textResult['prompt']!=='', 'Text prompt enhancement unavailable');
    foreach([[12,22,2,'i'],[11,23,2,'i'],[11,22,3,'i'],[11,22,2,'other-project-node']] as [$t,$u,$p,$n])refuses(fn()=>Preview::preview($t,$u,['project_id'=>$p,'node_id'=>$n,'prompt'=>'测试']),$n==='t'||$n==='other-project-node'?'只能优化':'无权访问');
    refuses(fn()=>Preview::preview(0,22,$params),'登录');
    Runtime::$config['router_enabled']=false;refuses(fn()=>Preview::preview(11,22,$params),'尚未启用');
    Runtime::$config['router_enabled']=true;Runtime::$config['router_available']=false;refuses(fn()=>Preview::preview(11,22,$params),'暂无可用');
    Runtime::$config['router_available']=true;
    $failures = [
        [new \RuntimeException('private provider error'), '原提示词已保留'],
        [new \app\common\service\power\MarketTextModelProviderException('private provider error', false, protocolCode: 'provider_stream_incomplete', gatewayRefunded: true), '本次未扣积分'],
        [new \app\common\service\power\MarketTextModelProviderException('private provider error', false, protocolCode: 'legacy_missing_completion'), '未收到完整回复'],
        [new \app\common\service\power\TextAdmissionRejected('用户积分不足'), '用户积分不足'],
        [new \app\common\service\power\TextSettlementPending(1, [], [], new \RuntimeException('private')), '费用待核对'],
    ];
    foreach ($failures as [$failure, $expected]) {
        Runtime::$failure = $failure;
        try { Preview::preview(11, 22, $params); throw new \LogicException('Expected preview failure'); }
        catch (\Exception $e) { verify(str_contains($e->getMessage(), $expected) && !str_contains($e->getMessage(), 'private'), 'Missing safe preview guidance'); }
    }
    Runtime::$failure = null;
    $referenceInput = '@林舟 走到@林舟助理身旁，随后@林舟拿起@会议 文件，镜头切到@图片1和【@会议室】。';
    Runtime::$respond = static function ($params) {
        $prompt = json_decode($params['content'], true)['prompt'];
        preg_match_all('/\[\[CANVAS_REF_+\d+\]\]/', $prompt, $matches);
        verify(count($matches[0]) === 6, 'Text model should receive protected references for all six occurrences');
        [$lead, $assistant, $repeatedLead, $file, $image, $room] = $matches[0];
        verify($lead === $repeatedLead && count(array_unique($matches[0])) === 5, 'Repeated and overlapping names lost their identities');
        return json_encode(['prompt' => "{$lead}走向{$assistant}，随后{$repeatedLead}翻阅{$file}。{$room}内，摄像机对准{$image}。"], JSON_UNESCAPED_UNICODE);
    };
    verify(Preview::preview(11, 22, [...$params, 'prompt' => $referenceInput])['prompt']
        === '@林舟走向@林舟助理，随后@林舟翻阅@会议 文件。【@会议室】内，摄像机对准@图片1。', 'Protected references did not restore in the model result');
    foreach (['missing', 'duplicate', 'invented_marker', 'invented_mention', 'rebound'] as $badResult) {
        Runtime::$respond = static function ($params) use ($badResult) {
            $prompt = json_decode($params['content'], true)['prompt'];
            preg_match('/\[\[CANVAS_REF_+\d+\]\]/', $prompt, $match);
            $marker = $match[0] ?? '';
            $prompt = match ($badResult) {
                'missing' => str_replace($marker, '', $prompt),
                'duplicate' => $prompt . $marker,
                'invented_marker' => $prompt . '[[CANVAS_REF_99]]',
                'invented_mention' => $prompt . ' @无关角色',
                'rebound' => str_replace('[[CANVAS_REF_4]]', '[[CANVAS_REF_4]]2', $prompt),
            };
            return json_encode(['prompt' => $prompt], JSON_UNESCAPED_UNICODE);
        };
        try {
            Preview::preview(11, 22, [...$params, 'prompt' => $referenceInput]);
            throw new \LogicException('Accepted changed references: ' . $badResult);
        } catch (\RuntimeException $error) {
            verify(str_contains($error->getMessage(), '优化结果改变了 @ 引用'), 'Reference change returned the wrong error');
        }
    }
    Runtime::$respond = null;
    $longOutput = str_repeat('长', 4500) . ' @图片1';
    $longInput = str_repeat('字', 4100) . ' @图片1';
    Runtime::$content = json_encode(['prompt' => str_repeat('长', 4500) . ' [[CANVAS_REF_1]]'], JSON_UNESCAPED_UNICODE);
    verify(Preview::preview(11, 22, [...$params, 'prompt' => $longInput])['prompt'] === $longOutput,
        'A valid long optimization result was rejected');
    $longCall = end(Runtime::$calls);
    verify(json_decode($longCall[2]['content'], true)['prompt'] === str_repeat('字', 4100) . ' [[CANVAS_REF_1]]', 'The long original prompt was truncated');
    verify(Preview::normalize('```json'."\n".'{"prompt":"保留 @图片1 和 【@角色】 的构图"}'."\n".'```','@图片1 【@角色】')==='保留 @图片1 和 【@角色】 的构图','Mentions not preserved');
    verify(Preview::normalize('{"prompt":"@图片1缓慢转身，保持人物一致"}','让@图片1动起来')==='@图片1缓慢转身，保持人物一致','Inline model reference mistaken for following Chinese text');
    foreach(['{}','not-json','{"prompt":"删除引用"}','{"prompt":"@图片1 @新主体"}'] as $content){try{Preview::normalize($content,'@图片1');throw new \LogicException('Accepted invalid preview');}catch(\RuntimeException){$checks++;}}
    verify($before===Db::name('aigc_canvas_project')->order('id')->select()->toArray(),'Preview changed a historical project');
    $schema=json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_canvas/api_schema.json'),true);
    $routes=array_values(array_filter($schema['apis'],fn($row)=>$row['api_path']==='app.aigc_canvas.generate/optimizePrompt'));
    verify(count($routes)===1&&$routes[0]['need_login']===1&&$routes[0]['api_method']==='POST','Missing authenticated API contract');
    echo "Canvas prompt preview: $checks checks passed; no real model or media generation\n";
} finally { Db::execute("DROP TABLE IF EXISTS `$table`"); }
}
