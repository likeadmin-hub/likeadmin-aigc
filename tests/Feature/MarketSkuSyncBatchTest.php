<?php
namespace Tests\Feature;

use app\common\model\power\{PowerMarketProduct,PowerMarketSku};
use app\common\service\power\{MarketSkuSyncBatch,PowerMarketService};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class MarketSkuSyncBatchTest extends TestCase
{
    public function testBatchMatchesLegacyWritesAndPreservesManualPricesKeysAndShelfState(): void
    {
        if(getenv('MARKET_PRICE_DB_TESTS')!=='local-existing')self::markTestSkipped('Explicit local rollback fixture; no supplier calls');
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();
        $db=config('database.connections.mysql');self::assertSame('127.0.0.1',$db['hostname']);self::assertContains($db['database'],['x_cn','z_cn']);
        request()->tenantId=0;request()->source=\app\common\enum\AdminTerminalEnum::PLATFORM;
        $connections=config('database.connections');$connections['mysql']['trigger_sql']=true;config(['connections'=>$connections],'database');Db::connect('mysql',true);
        $mode='';$reads=['legacy'=>0,'batch'=>0];
        Db::listen(static function($sql)use(&$reads,&$mode){if(isset($reads[$mode])&&preg_match('/^SELECT .*FROM `la_power_market_sku`/s',$sql))$reads[$mode]++;});
        $save=new \ReflectionMethod(PowerMarketService::class,'upsertSku');$key=new \ReflectionMethod(PowerMarketService::class,'fallbackSkuKey');
        $shape=static fn($name)=>['sku_key'=>$name,'title'=>$name,'billing_mode'=>'fixed','locked_params'=>['quality'=>$name],
            'selectable_params'=>[],'usage_unit'=>'per_call','usage_unit_size'=>1,'price'=>['points'=>'5.000001']];
        Db::startTrans();
        try{
            $outputs=[];
            foreach(['legacy','batch']as$run){
                $mode='';$token='sku-batch-'.bin2hex(random_bytes(8));
                $product=PowerMarketProduct::create(['product_code'=>$token,'name'=>'Offline SKU synchronization fixture','resource_type'=>'model','model_type'=>'video','source_code'=>'offline-fixture','upstream_resource_key'=>$token,'status'=>1,'create_time'=>time(),'update_time'=>time()]);$id=(int)$product['id'];
                $items=[];
                for($i=0;$i<20;$i++)$items[]=$shape('native-'.$i);
                $items[]=$shape('renamed-fallback');$items[]=$shape('renamed-masked');
                foreach($items as$i=>$sku){$stored=$i===20?$key->invoke(null,$sku):($i===21?'******':$sku['sku_key']);
                    PowerMarketSku::create(['product_id'=>$id,'sku_key'=>$stored,'title'=>$sku['title'],'billing_mode'=>'fixed','locked_params'=>$sku['locked_params'],'selectable_params'=>[],'usage_unit'=>'per_call','usage_unit_size'=>1,'upstream_price'=>1,'sale_points'=>19,'sale_status'=>0,'status'=>1,'source_payload'=>[],'create_time'=>time(),'update_time'=>time()]);
                }
                $items[]=$shape('new-sku');$items[]=$shape('new-sku');$items[array_key_last($items)]['price']['points']='6.000001';
                PowerMarketSku::where('product_id',$id)->update(['status'=>0]);
                $mode=$run;$batch=$run==='batch'?new MarketSkuSyncBatch($id):null;
                foreach($items as$sku)$save->invoke(null,$id,$sku,['type'=>'model','resource'=>['model_code'=>'offline']],0,$batch);
                $mode='';$rows=Db::name('power_market_sku')->where('product_id',$id)->order('sku_key')->select()->toArray();
                self::assertCount(23,$rows);
                foreach($rows as&$row){self::assertSame(1,(int)$row['status']);self::assertSame(0,(int)$row['sale_status']);
                    self::assertSame($row['sku_key']==='new-sku'?'5.000001':'19.000000',(string)$row['sale_points']);
                    unset($row['id'],$row['product_id'],$row['create_time'],$row['update_time']);
                }unset($row);$outputs[$run]=$rows;
            }
            self::assertSame($outputs['legacy'],$outputs['batch']);
            self::assertGreaterThanOrEqual(20,$reads['legacy']);self::assertSame(1,$reads['batch']);
        }finally{Db::rollback();}
    }
}
