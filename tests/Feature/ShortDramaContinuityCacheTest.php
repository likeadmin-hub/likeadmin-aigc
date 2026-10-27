<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\{ShortDramaContinuityCache as Checks, ShortDramaContinuity as Continuity};
use PHPUnit\Framework\TestCase;
use think\facade\Cache;

class ShortDramaContinuityCacheTest extends TestCase
{
    protected function setUp():void{(new \think\App())->initialize();}
    private function rows():array
    {
        $rows=[];for($i=1;$i<=500;$i++)$rows[]=['id'=>$i,'production_project_id'=>$i,'status'=>'success','progress'=>100,
            'continuity_json'=>json_encode(['version'=>1,'digest'=>'d'.$i,'previous_digest'=>$i>1?'d'.($i-1):'',
                'episode_number'=>$i,'summary'=>'原剧情'.$i,'audit_status'=>'verified','state'=>[],'open_hooks'=>[]])];
        return $rows;
    }
    public function testFiveHundredEpisodeCachePreservesResultAndNeverCachesProgress():void
    {
        $p=random_int(100000000,200000000);$rows=$this->rows();
        try {
            $expected=Continuity::dependencyStatus($rows,[]);
            self::assertSame($expected,Checks::resolve(2000000731,7,$p,$rows,[],true));
            $rows[1]['progress']=43;
            $result=Checks::resolve(2000000731,7,$p,$rows,[],true);
            self::assertSame(43,$result[1]['progress']);self::assertFalse($result[499]['needs_review']);
            $versions=[20=>['narrative_digest'=>'edited','ledger'=>json_decode($rows[19]['continuity_json'],true)]];
            $changed=Checks::resolve(2000000731,7,$p,$rows,$versions,true);
            self::assertTrue($changed[19]['needs_review']);self::assertTrue($changed[499]['needs_review']);
            self::assertSame(Continuity::dependencyStatus($rows,$versions),$changed);
            self::assertFalse(Checks::resolve(2000000731,7,$p,$rows,$versions,false)[499]['needs_review']);
            self::assertFalse(Checks::resolve(2000000732,7,$p,$rows,[],true)[499]['needs_review']);
        }finally{Cache::delete('short_drama_continuity_v1:2000000731:7:'.$p);Cache::delete('short_drama_continuity_v1:2000000732:7:'.$p);}
    }
}
