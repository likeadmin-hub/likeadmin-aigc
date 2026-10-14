<?php
declare(strict_types=1);
namespace think\facade {
    class Db {
        public static array $rows=[];
        public static bool $offline=false;
        public static function query(string $sql,array $bindings=[]): array {
            if(self::$offline)throw new \RuntimeException('fixture database offline');
            if(!str_contains($sql,'information_schema.tables') || count($bindings)!==6)throw new \RuntimeException('readiness must inspect all six exact shared table names');
            return self::$rows;
        }
    }
}
namespace think\console {
    class Command {}
    class Input { public function getOption(string $name): mixed {return $name==='once';} }
    class Output { public function writeln(string $message):void {} }
}
namespace {
    function config(string $name,mixed $default=null): mixed {return $name==='database.connections.mysql.prefix'?'fixture_':$default;}
    require dirname(__DIR__,2).'/app/common/service/decorate/agent/DecorationAgentService.php';
    require dirname(__DIR__,2).'/app/common/command/DecorationAgentWorker.php';
    use app\common\service\decorate\agent\DecorationAgentService as Service;
    if(Service::workerReady())throw new RuntimeException('uninitialized schema treated as ready');
    $worker=new app\common\command\DecorationAgentWorker();$execute=new ReflectionMethod($worker,'execute');$execute->setAccessible(true);
    if($execute->invoke($worker,new think\console\Input(),new think\console\Output())!==0)throw new RuntimeException('missing schema crashes unified worker group');
    think\facade\Db::$rows=array_fill(0,5,['table_name'=>'fixture']);
    if(Service::workerReady())throw new RuntimeException('partial schema treated as ready');
    think\facade\Db::$rows=array_fill(0,6,['table_name'=>'fixture']);
    if(!Service::workerReady())throw new RuntimeException('installed schema cannot start');
    think\facade\Db::$offline=true;
    $failed=false;try{Service::workerReady();}catch(RuntimeException $e){$failed=$e->getMessage()==='fixture database offline';}
    if(!$failed)throw new RuntimeException('connection failure swallowed as a missing schema');
    if($execute->invoke($worker,new think\console\Input(),new think\console\Output())!==1)throw new RuntimeException('database failure does not retire worker');
    echo "PASS: uninitialized/partial decoration waits; complete schema runs; connection error propagates\n";
}
