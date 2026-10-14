<?php
declare(strict_types=1);
namespace app\common\service\ai { class AiTaskJobService { public static $claims=0; public static $finished=[]; public static function claim(...$args){if(++self::$claims>1)throw new \RuntimeException('claimed another batch after generation changed');return [['id'=>1,'job_type'=>'test','consumption_id'=>0,'result_asset_id'=>0,'attempts'=>1],['id'=>2,'job_type'=>'test','consumption_id'=>0,'result_asset_id'=>0,'attempts'=>1]];}public static function run($job){$GLOBALS['epoch_changed']=true;return true;}public static function succeed($job){self::$finished[]=$job['id'];} } }
namespace app\common\service\app\aigc_canvas\agent\runtime { class SubAgentTaskService { public static $claims=0; public static $finished=[];public static function recoverExpiredLeases(){return 0;}public static function claim(...$args){if(++self::$claims>1)throw new \RuntimeException('claimed another subtask after generation changed');return [['id'=>3,'agent_code'=>'test','attempts'=>1]];}public static function execute($task){self::$finished[]=$task['id'];$GLOBALS['epoch_changed']=true;} } }
namespace app\common\service\app\aigc_short_drama { class ShortDramaWorkerMemory {public static function release():void{}} class ShortDramaEpisodeService {public static function tick(){throw new \RuntimeException("stale episode worker claimed work");}} }
namespace {
$baseline=getenv('AIGC_WORKER_BASELINE_ROOT') ?: dirname(__DIR__,2);
$autoload=require $baseline.'/vendor/autoload.php';
$autoload->setPsr4('app\\',[$baseline.'/app']);
if($bridge=getenv('AIGC_WORKER_BRIDGE_ROOT'))foreach(['AiTaskWorker','CanvasSubAgentWorker','ShortDramaEpisodeWorker','ShortDramaPlanningWorker','ShortDramaCanvasAgentWorker','Crontab'] as $worker)require $bridge.'/files/app/common/command/'.$worker.'.php';
function aigc_runtime_generation_changed(): bool { return (bool)$GLOBALS['epoch_changed']; }
class Options extends \think\console\Input { private $values;public function __construct(array $values){$this->values=$values;parent::__construct([]);}public function getOption(string $name){return $this->values[$name]??null;} }
function runWorker(string $class,array $options): int {$command=new $class();$method=new ReflectionMethod($command,'execute');$method->setAccessible(true);return (int)$method->invoke($command,new Options($options),new \think\console\Output('buffer'));}
try {
    $GLOBALS['epoch_changed']=false;
    runWorker(\app\common\command\AiTaskWorker::class,['worker'=>'result','app'=>'fixture','sleep'=>1,'lease'=>90,'batch'=>2]);
    if(\app\common\service\ai\AiTaskJobService::$finished!==[1,2])throw new RuntimeException('already claimed task state was not finished');
    $GLOBALS['epoch_changed']=false;
    runWorker(\app\common\command\CanvasSubAgentWorker::class,['sleep'=>1,'lease'=>180,'limit'=>2,'once'=>false,'worker'=>'test']);
    if(\app\common\service\app\aigc_canvas\agent\runtime\SubAgentTaskService::$finished!==[3])throw new RuntimeException('subtask state was not finished');
    $GLOBALS['epoch_changed']=true;
    foreach ([\app\common\command\ShortDramaEpisodeWorker::class, \app\common\command\ShortDramaPlanningWorker::class, \app\common\command\ShortDramaCanvasAgentWorker::class, \app\common\command\DecorationAgentWorker::class, \app\common\command\Crontab::class] as $class) runWorker($class,['once'=>false,'tenant'=>1,'sleep'=>1]);
    echo "PASS: generation retirement occurs after claimed batch/task completion and before another claim\n";
} catch(Throwable $e){fwrite(STDERR,"FAIL: ".$e->getMessage()."\n");exit(1);}
}
