<?php
namespace Tests\Feature;

use app\common\service\app\aigc_image\AigcImageAssetService as Images;
use app\common\service\ai\AiTaskResultAssetService as Results;
use app\common\model\ai\AiConsumptionLog;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Valid local storage in an owned temporary public root; rows always roll back. */
class MarketManagedStorageTest extends TestCase
{
    private function local(callable $test): void
    {
        if(getenv('AIGC_MANAGED_STORAGE_DB_TESTS')!=='1')self::markTestSkipped('Explicit local managed-storage fixture only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $prop=new \ReflectionProperty(\think\App::class,'rootPath');$prop->setAccessible(true);$old=$prop->getValue(app());$dir=sys_get_temp_dir().'/b51-storage-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/public',0700);$prop->setValue(app(),$dir.'/');Db::startTrans();
        try{$test($dir);}finally{Db::rollback();$prop->setValue(app(),$old);$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
    }
    public function testValidInlineBytesHaveAnOwnedManagedReceiptAndExplicitStorageScope(): void
    {
        $this->local(function($dir){
            $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
            $stored=Images::persistGeneratedImage('data:image/png;base64,'.base64_encode($png),199999953,199999954,true,1,['scope'=>'tenant','default'=>'local','engine'=>['local'=>[]]]);
            self::assertTrue($stored['stored']);self::assertSame(1,$stored['width']);self::assertSame(1,$stored['height']);self::assertSame('tenant',$stored['storage_scope']);self::assertSame('local',$stored['storage_engine']);
            self::assertSame(hash('sha256',$png),hash_file('sha256',$dir.'/public/'.$stored['uri']));$row=Db::name('tenant_file')->where(['tenant_id'=>199999953,'source_id'=>199999954,'uri'=>$stored['uri']])->find();self::assertNotEmpty($row);self::assertSame('tenant',$row['storage_scope']);self::assertSame('local',$row['storage_engine']);
        });
    }
    public function testInvalidInlineBytesNeverWriteAFileOrOwnershipRow(): void
    {
        $this->local(function($dir){$before=(int)Db::name('tenant_file')->where('tenant_id',199999953)->count();$error=null;try{Images::persistGeneratedImage('data:image/png;base64,'.base64_encode('not image bytes'),199999953,199999954,true,1,['scope'=>'tenant','default'=>'local']);}catch(\Exception $e){$error=$e;}self::assertNotNull($error);self::assertSame($before,(int)Db::name('tenant_file')->where('tenant_id',199999953)->count());self::assertSame([],glob($dir.'/public/*'));});
    }
    /** @dataProvider types */
    public function testOnlyOwnedStoredAssetsProjectIntoTheMatchingCanonicalResult(string $type,string $key,string $field): void
    {
        $this->local(function()use($type,$key,$field){
            $source='https://fixtures.invalid/original';$summary=[$key=>[[$field=>$source],[$field=>'https://fixtures.invalid/unrelated']],'receipt'=>['fixture'=>true]];$price=['fixture'=>'frozen'];
            $c=AiConsumptionLog::create(['consume_no'=>'b51-storage-'.bin2hex(random_bytes(8)),'tenant_id'=>199999953,'user_id'=>199999954,'app_code'=>'aigc_image','price_snapshot'=>$price,'response_summary'=>$summary,'run_status'=>'success','billing_status'=>'settled','create_time'=>time(),'update_time'=>time()]);
            $asset=['consumption_id'=>$c['id'],'tenant_id'=>199999953,'user_id'=>199999954,'asset_type'=>$type,'transfer_status'=>'stored','external_url'=>$source,'local_uri'=>'uploads/fixture-managed','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'http://localhost','storage_meta'=>['width'=>1,'height'=>1]];
            $hydrate=new \ReflectionMethod(Results::class,'hydrateStoredSummary');$wrong=$asset;$wrong['user_id']++;$hydrate->invoke(null,(int)$c['id'],[$wrong]);self::assertSame($summary,AiConsumptionLog::find($c['id'])['response_summary']);
            $hydrate->invoke(null,(int)$c['id'],[$asset]);$done=AiConsumptionLog::find($c['id']);$projected=$done['response_summary'];self::assertSame('uploads/fixture-managed',$projected[$key][0][$field]);self::assertSame('tenant',$projected[$key][0]['storage_scope']);self::assertSame($summary[$key][1],$projected[$key][1]);self::assertSame($summary['receipt'],$projected['receipt']);self::assertSame($price,$done['price_snapshot']);self::assertSame('settled',$done['billing_status']);
            $hydrate->invoke(null,(int)$c['id'],[$asset]);self::assertSame($projected,AiConsumptionLog::find($c['id'])['response_summary']);
        });
    }
    public static function types(): array{return [['image','images','image_uri'],['video','videos','video_uri'],['audio','items','audio_uri']];}

    /** @dataProvider measuredMedia */
    public function testMeasuredDeliveryMetadataPreservesUnknownsAndFrozenBilling(string $type, array $meta, array $original, array $expected): void
    {
        $this->local(function () use ($type, $meta, $original, $expected) {
            $key=$type==='video'?'videos':'items';$uriKey=$type==='video'?'video_uri':'audio_uri';$uri='https://fixtures.invalid/result';
            $summary=[$key=>[[$uriKey=>$uri]+$original],'receipt'=>['keep'=>'unchanged']];$price=['frozen'=>['units'=>17]];
            $c=AiConsumptionLog::create(['consume_no'=>'b53-meta-'.bin2hex(random_bytes(8)),'tenant_id'=>199999953,'user_id'=>199999954,'app_code'=>'aigc_'.$type,'price_snapshot'=>$price,'response_summary'=>$summary,'run_status'=>'success','billing_status'=>'settled','create_time'=>time(),'update_time'=>time()]);
            $asset=['consumption_id'=>$c['id'],'tenant_id'=>199999953,'user_id'=>199999954,'asset_type'=>$type,'transfer_status'=>'stored','external_url'=>$uri,'local_uri'=>'uploads/managed','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'http://localhost','storage_meta'=>$meta];
            (new \ReflectionMethod(Results::class,'hydrateStoredSummary'))->invoke(null,(int)$c['id'],[$asset]);$fresh=AiConsumptionLog::find($c['id']);$row=$fresh['response_summary'][$key][0];
            foreach($expected as$field=>$value)self::assertSame($value,$row[$field]);
            self::assertSame('uploads/managed',$row[$uriKey]);self::assertSame($price,$fresh['price_snapshot']);self::assertSame($summary['receipt'],$fresh['response_summary']['receipt']);self::assertSame('settled',$fresh['billing_status']);
        });
    }
    public static function measuredMedia(): array
    {
        return [
            ['video',['width'=>0,'height'=>0],['width'=>32,'height'=>64],['width'=>32,'height'=>64]],
            ['video',['width'=>128,'height'=>256],['width'=>32,'height'=>64],['width'=>128,'height'=>256]],
            ['audio',['file_size'=>16078,'duration'=>1,'mime_type'=>'audio/wav'],['file_size'=>0,'duration'=>0,'mime_type'=>'audio/mpeg'],['file_size'=>16078,'duration'=>1,'mime_type'=>'audio/wav']],
            ['audio',['file_size'=>0,'duration'=>0,'mime_type'=>''],['file_size'=>8,'duration'=>2,'mime_type'=>'audio/wav'],['file_size'=>8,'duration'=>2,'mime_type'=>'audio/wav']],
            ['audio',['file_size'=>-1,'duration'=>INF],['file_size'=>8,'duration'=>2],['file_size'=>8,'duration'=>2]],
        ];
    }
}
