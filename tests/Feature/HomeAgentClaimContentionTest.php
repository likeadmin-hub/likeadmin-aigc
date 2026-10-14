<?php
namespace app\common\service\app\aigc_llm\agent {
    final class AgentStore {
        public static int $failure=0;
        public static int $claims=0;
        public static function table(string $name): object { return new ClaimQuery(); }
    }
    final class ClaimQuery {
        public function __call(string $name,array $args){
            if($name==='select')return new class {public function toArray():array{return [];}};
            if($name==='find'){
                AgentStore::$claims++;
                if(AgentStore::$failure){
                    $code=AgentStore::$failure;AgentStore::$failure=0;
                    $error=new \PDOException('fixture claim error');$error->errorInfo=[$code===1213?'40001':'HY000',$code,'fixture'];
                    throw new \think\db\exception\PDOException($error);
                }
                return null;
            }
            if($name==='update')throw new \LogicException('No fixture may submit or update a run');
            return $this;
        }
    }
}
namespace {
    require dirname(__DIR__,2).'/vendor/autoload.php';(new \think\App())->initialize();
    set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
    use app\common\service\app\aigc_llm\agent\{AgentExecutionService as E,AgentStore as S};
    $db=config('database.connections.mysql');if(PHP_SAPI!=='cli'||$db['hostname']!=='127.0.0.1'||!in_array($db['database'],['x_cn','z_cn'],true))throw new \RuntimeException('Local test only');
    foreach([1213,1205] as $code){S::$failure=$code;if(E::tick()!==0||E::tick()!==0)throw new \RuntimeException('Transient claim did not defer and recover');}
    foreach([1146,2006] as $code){S::$failure=$code;try{E::tick();throw new \LogicException('Non-contention failure masked');}catch(\think\db\exception\PDOException $e){if((int)$e->getData()['PDO Error Info']['Driver Error Code']!==$code)throw $e;}}
    if(S::$claims!==6)throw new \RuntimeException('Unexpected immediate retry loop');
    echo "Claim contention passed: rolled-back deadlock/timeout deferred, next poll recovers, other errors propagate, no provider or task writes\n";
}
