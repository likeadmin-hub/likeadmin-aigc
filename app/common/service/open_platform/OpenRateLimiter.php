<?php
namespace app\common\service\open_platform;
use think\facade\Db;
/** Shared persistent counters include replays; loss of cache never removes limits. */
final class OpenRateLimiter
{
    public static function admit(array $key): void
    {
        Db::transaction(function()use($key){$tenant=(int)$key['tenant_id'];$keyId=(int)$key['id'];OpenStore::table('setting')->where('tenant_id',$tenant)->lock(true)->find();$config=OpenStore::limits($tenant);$slot=intdiv(time(),60);$counts=[];
            foreach([0,$keyId] as $id){$row=OpenStore::table('rate')->where(['tenant_id'=>$tenant,'key_id'=>$id,'slot'=>$slot])->lock(true)->find();$limit=$id===0?(int)$config['rpm']:min((int)$config['rpm'],(int)$key['rpm']?:PHP_INT_MAX);if((int)($row['calls']??0)>=$limit)throw new OpenException('rate_limit_exceeded','请求频率超限',429);$counts[$id]=$row;}
            foreach($counts as $id=>$row){if($row)OpenStore::table('rate')->where('id',$row['id'])->inc('calls')->update();else OpenStore::table('rate')->insert(['tenant_id'=>$tenant,'key_id'=>$id,'slot'=>$slot,'calls'=>1]);}
        });
    }
    public static function prune(): void {OpenStore::table('rate')->where('slot','<',intdiv(time(),60)-2880)->limit(500)->delete();}
}
