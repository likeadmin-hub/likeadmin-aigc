<?php
// Isolated tables and stubbed membership/provider boundaries; never calls a provider.
namespace app\common\service\app {
    final class AppAccessService { public static function assertTenantCanUse(...$args) { return null; } }
}
namespace app\common\service\app\aigc_llm\agent {
    final class AgentCapabilityCatalog {
        public static array $queried=[];
        public static function query(array $run,string $code,int $id): array {
            self::$queried[]=$id;return ['task_id'=>$id,'status'=>'running','results'=>[]];
        }
        public static function prepare(...$args): array { throw new \RuntimeException('Unexpected preparation'); }
        public static function submit(...$args): array { throw new \RuntimeException('Provider submission forbidden'); }
    }
}
namespace {
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().'\n'.$e->getTraceAsString());exit(1);});
$db=config('database.connections.mysql');
if(PHP_SAPI!=='cli'||$db['hostname']!=='127.0.0.1'||!in_array($db['database'],['x_cn','z_cn'],true))throw new RuntimeException('Local isolated tables only');
$cfg=\think\facade\Config::get('database');$old=$cfg['connections']['mysql']['prefix'];$audit='auditcanvas_'.getmypid().'_';
$tables=['aigc_canvas_project','aigc_canvas_run','aigc_canvas_asset','aigc_canvas_project_compat_backup','aigc_canvas_agent_thread','aigc_canvas_agent_message','aigc_llm_session','aigc_llm_message','aigc_llm_usage','aigc_llm_agent_run','aigc_llm_agent_step','aigc_llm_agent_event','aigc_llm_agent_artifact','aigc_llm_agent_preference','aigc_llm_agent_context'];
foreach($tables as $table)\think\facade\Db::execute("CREATE TABLE `{$audit}{$table}` LIKE `{$old}{$table}`");
$cfg['connections']['mysql']['prefix']=$audit;\think\facade\Config::set($cfg,'database');\think\facade\Db::connect(null,true);
if(\think\facade\Db::connect()->getConfig('prefix')!==$audit)throw new RuntimeException('isolation failed');
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentWorkspaceScope as Scope,AgentStore as S,AgentArtifactService};
use app\common\service\app\aigc_canvas\{AigcCanvasService as Canvas,CanvasConversationTools as Tools};
use app\common\model\app\aigc_llm\AigcLlmSession;
try {
    $checks = 0;
    function verify($ok, $message) { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
    function refuses(callable $fn, string $message) {
        try { $fn(); } catch (Exception $e) { verify(str_contains($e->getMessage(), $message), $e->getMessage()); return; }
        throw new RuntimeException('Expected rejection: '.$message);
    }
    function body(array $p, string $request): array {
        return ['id'=>$p['id'], 'name'=>$p['name'], 'nodes'=>$p['nodes'], 'edges'=>$p['edges'], 'thumbnail'=>$p['thumbnail'], 'viewport'=>$p['viewport'], 'save_request_id'=>$request];
    }
    $t=1999999893; $u=$t;
    $p=Canvas::createProject($t,$u,['name'=>'save isolation fixture','nodes'=>[
        ['id'=>'old','type'=>'text','position'=>['x'=>20,'y'=>30],'metadata'=>['content'=>'historical','custom'=>['enabled'=>false,'seed'=>0]]]
    ],'viewport'=>['pages'=>[['id'=>'one','name'=>'One','nodeIds'=>['old'],'createdAt'=>1,'updatedAt'=>2],['id'=>'two','name'=>'Two','nodeIds'=>[]]],'currentCanvasId'=>'one']]);
    $a=body($p,'save_fixture_request_A'); $a['nodes'][0]['position']['x']=42;
    $saved=Canvas::saveProject($t,$u,$a);
    verify($saved['viewport']['saveRevision']===1,'first edit increments revision');
    verify(Canvas::saveProject($t,$u,$a)['viewport']['saveRevision']===1,'lost-response retry acknowledges receipt');
    $bad=$a; $bad['nodes'][0]['position']['x']=43;
    refuses(fn()=>Canvas::saveProject($t,$u,$bad),'保存请求内容已变化');
    // A second tab independently made exactly the same edit against revision zero.
    $b=$a; $b['save_request_id']='save_fixture_request_B'; $b['viewport']['pages'][0]['updatedAt']=999;
    $b['nodes'][0]['metadata']=array_reverse($b['nodes'][0]['metadata'],true);
    $same=Canvas::saveProject($t,$u,$b);
    verify($same['viewport']['saveRevision']===1,'identical stale snapshot must not falsely conflict or increment');
    verify($same['viewport']['pages'][0]['updatedAt']===2,'no-op must not overwrite timestamps');
    verify($same['viewport']['saveReceipt']['id']===$a['save_request_id'],'no-op must preserve last write receipt');
    $current=body($same,'save_fixture_request_C');
    verify(Canvas::saveProject($t,$u,$current)['viewport']['saveRevision']===1,'current no-op must not churn versions');
    // Every meaningful difference must retain CAS protection, including non-active pages.
    $changes = [
        function(&$v){$v['nodes'][0]['position']['x']=99;},
        function(&$v){$v['nodes']=[];},
        function(&$v){$v['nodes'][0]['metadata']['custom']['enabled']=0;},
        function(&$v){$v['nodes'][0]['metadata']['url']='/uploads/different.mp4';},
        function(&$v){$v['edges']=[['id'=>'new','source'=>'old','target'=>'other']];},
        function(&$v){$v['name']='renamed';},
        function(&$v){$v['viewport']['x']=500;},
        function(&$v){$v['viewport']['pages'][1]['name']='other page';},
        function(&$v){$v['viewport']['pages'][1]['nodeIds']=['old'];},
        function(&$v){$v['viewport']['pages'][1]['viewport']['k']=2;},
        function(&$v){$v['viewport']['currentCanvasId']='two';},
    ];
    foreach($changes as $change){$stale=$b;$change($stale);refuses(fn()=>Canvas::saveProject($t,$u,$stale),'其他标签页');}
    $missingPages=$b;unset($missingPages['viewport']['pages']);
    refuses(fn()=>Canvas::saveProject($t,$u,$missingPages),'多个画布');
    refuses(fn()=>Canvas::saveProject($t+1,$u,$b),'项目不存在');
    refuses(fn()=>Canvas::saveProject($t,$u+1,$b),'项目不存在');
    // Even identical content cannot bypass an Agent fence.
    $row=Db::name('aigc_canvas_project')->where('id',$p['id'])->find();
    $vp=json_decode($row['viewport_json'],true);$vp['agentRevision']++;
    Db::name('aigc_canvas_project')->where('id',$p['id'])->update(['viewport_json'=>json_encode($vp)]);
    refuses(fn()=>Canvas::saveProject($t,$u,$b),'Agent');
    $now=Canvas::projectDetail($t,$u,$p['id']);
    verify($now['nodes']===$saved['nodes'],'rejections must not overwrite saved nodes');
    // A pre-revision, single-canvas historical project stays compatible.
    $legacy=Canvas::createProject($t,$u,['name'=>'legacy','nodes'=>[['id'=>'legacy','type'=>'text','metadata'=>['content'=>'keep']]],'viewport'=>['x'=>1,'y'=>2,'zoom'=>1]]);
    $edit=body($legacy,'save_fixture_legacy_1');unset($edit['viewport']['saveRevision']);$edit['nodes'][0]['metadata']['content']='edited';
    verify(Canvas::saveProject($t,$u,$edit)['nodes'][0]['metadata']['content']==='edited','legacy project saves');
    echo "PASS $checks save concurrency, receipt, historical project and ownership checks\n";
} finally {
    foreach(array_reverse($tables) as $table) Db::execute("DROP TABLE IF EXISTS `{$audit}{$table}`");
}
}
