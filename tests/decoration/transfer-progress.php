<?php
namespace think\facade {
    final class Cache {
        public static array $items=[];
        public static bool $broken=false;
        public static function get($key,$default=null){if(self::$broken)throw new \RuntimeException('cache down');return self::$items[$key]??$default;}
        public static function set($key,$value,$ttl){if(self::$broken)throw new \RuntimeException('cache down');if($ttl!==600)throw new \RuntimeException('TTL required');self::$items[$key]=$value;}
    }
}
namespace {
    require dirname(__DIR__,2).'/app/common/service/decorate/DecorationTransferProgress.php';
    use app\common\service\decorate\DecorationTransferProgress as Progress;
    use think\facade\Cache;
    $checks=0;
    function check($ok,$message){global $checks;if(!$ok)throw new \RuntimeException($message);$checks++;}
    $id=str_repeat('a',32);$p=Progress::begin(7,9,$id,'import');
    $p->step('校验模板包');$p->step('保存素材 1');
    $state=Progress::read(7,9,$id);
    check($state['status']==='running'&&count($state['steps'])===3,'real steps retained in order');
    check(Progress::read(8,9,$id)['status']==='pending','tenant isolated');
    check(Progress::read(7,10,$id)['status']==='pending','operator isolated');
    $p->succeed(['asset_import'=>['files'=>3]]);$p->step('late progress');$p->fail('late failure');
    $state=Progress::read(7,9,$id);
    check($state['status']==='success'&&$state['result']['asset_import']['files']===3,'terminal cannot regress');
    try{Progress::begin(7,9,$id,'import');throw new \LogicException('duplicate accepted');}catch(\RuntimeException $e){check(str_contains($e->getMessage(),'已使用'),'duplicate operation blocked');}
    foreach(['../bad','',str_repeat('z',32)] as $bad){try{Progress::read(7,9,$bad);throw new \LogicException('invalid id accepted');}catch(\RuntimeException $e){$checks++;}}
    $p=Progress::begin(7,9,str_repeat('b',32),'export');for($i=0;$i<520;$i++)$p->step('step '.$i);
    $state=Progress::read(7,9,str_repeat('b',32));check(count($state['steps'])<=400&&end($state['steps'])['message']==='step 519','bounded log keeps latest');
    Cache::$broken=true;$p->step('cache unavailable');$p->succeed();
    check(Progress::read(7,9,$id)['available']===false,'telemetry outage does not fail transfer');
    Progress::begin(7,9,'','import')->step('legacy client supported');
    echo "PASS template transfer progress: $checks checks\n";
}
