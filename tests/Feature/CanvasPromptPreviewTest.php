<?php
// Local disposable table and fake text model. No live project/config/billing writes.
namespace app\common\service\app\aigc_canvas {
    final class AigcCanvasService {
        public const APP_CODE = 'aigc_canvas';
        public static array $config = ['router_enabled'=>true,'router_available'=>true,'router_model_code'=>'tenant-model'];
        public static array $calls = [];
        public static string $content = '{"prompt":"海边的女孩，柔和逆光，自然构图。"}';
        public static bool $fail = false;
        public static function promptEnrichmentConfig($tenant) { return self::$config; }
        public static function llmText($tenant,$user,$params) {
            self::$calls[]=[$tenant,$user,$params];
            if(self::$fail)throw new \RuntimeException('private provider error');
            if(isset($params['_result_validator']))$params['_result_validator'](['content'=>self::$content]);
            return ['content'=>self::$content];
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
    $nodes=[['id'=>'i','type'=>'image','metadata'=>['prompt'=>'旧提示词','mentions'=>[['id'=>'same','name'=>'项目素材']],'custom'=>['enabled'=>false]]],['id'=>'v','type'=>'videoNode','data'=>['duration'=>5,'ratio'=>'16:9']],['id'=>'t','type'=>'text','metadata'=>['content'=>'历史文本']]];
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
    $params['node_id']='v';Preview::preview(11,22,$params);
    $input=json_decode(Runtime::$calls[1][2]['content'],true);verify($input['type']==='video'&&$input['duration']===5&&$input['ratio']==='16:9','Legacy video settings not read');
    foreach([[12,22,2,'i'],[11,23,2,'i'],[11,22,3,'i'],[11,22,2,'other-project-node'],[11,22,2,'t']] as [$t,$u,$p,$n])refuses(fn()=>Preview::preview($t,$u,['project_id'=>$p,'node_id'=>$n,'prompt'=>'测试']),$n==='t'||$n==='other-project-node'?'只能优化':'无权访问');
    refuses(fn()=>Preview::preview(0,22,$params),'登录');
    refuses(fn()=>Preview::preview(11,22,['project_id'=>2,'node_id'=>'i','prompt'=>str_repeat('字',4001)]),'4000');
    Runtime::$config['router_enabled']=false;refuses(fn()=>Preview::preview(11,22,$params),'尚未启用');
    Runtime::$config['router_enabled']=true;Runtime::$config['router_available']=false;refuses(fn()=>Preview::preview(11,22,$params),'暂无可用');
    Runtime::$config['router_available']=true;
    Runtime::$fail=true;try{Preview::preview(11,22,$params);throw new \RuntimeException('Expected provider error');}catch(\RuntimeException $e){verify(!str_contains($e->getMessage(),'private')&&str_contains($e->getMessage(),'未完成'),'Raw error leaked');}
    verify(Preview::normalize('```json'."\n".'{"prompt":"保留 @图片1 和 【@角色】 的构图"}'."\n".'```','@图片1 【@角色】')==='保留 @图片1 和 【@角色】 的构图','Mentions not preserved');
    foreach(['{}','not-json','{"prompt":"删除引用"}','{"prompt":"@图片1 @新主体"}'] as $content){try{Preview::normalize($content,'@图片1');throw new \LogicException('Accepted invalid preview');}catch(\RuntimeException){$checks++;}}
    verify($before===Db::name('aigc_canvas_project')->order('id')->select()->toArray(),'Preview changed a historical project');
    $schema=json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_canvas/api_schema.json'),true);
    $routes=array_values(array_filter($schema['apis'],fn($row)=>$row['api_path']==='app.aigc_canvas.generate/optimizePrompt'));
    verify(count($routes)===1&&$routes[0]['need_login']===1&&$routes[0]['api_method']==='POST','Missing authenticated API contract');
    echo "Canvas prompt preview: $checks checks passed; no real model or media generation\n";
} finally { Db::execute("DROP TABLE IF EXISTS `$table`"); }
}
