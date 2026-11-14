<?php
namespace Tests\Feature;
use app\common\service\ai\StudioMediaInputService as Media;
use app\common\service\app\aigc_store_visit\StoreVisitVoiceReference;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class StudioOwnedAudioTest extends TestCase
{
    public function testProbeCacheAlwaysRechecksOwnershipDurationAndSize(): void
    {
        if(getenv('STORE_VISIT_AUDIO_LOCAL_TEST')!=='z_cn')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();self::assertSame('z_cn',Db::connect()->getConfig('database'));self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        $dir=public_path().'uploads/voice-fixture-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);
        // Two seconds of PCM audio; no provider request and no third-party content.
        $samples=str_repeat(pack('v',0),16000*2);$wav='RIFF'.pack('V',36+strlen($samples)).'WAVEfmt '.pack('VvvVVvv',16,1,1,16000,32000,2,16).'data'.pack('V',strlen($samples)).$samples;
        file_put_contents($dir.'/voice.wav',$wav);$uri='uploads/'.basename($dir).'/voice.wav';Db::startTrans();
        try{
            $id=Db::name('tenant_file')->insertGetId(['tenant_id'=>1,'source_id'=>1,'source'=>1,'type'=>30,'name'=>'audio fixture','uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
            $voice=StoreVisitVoiceReference::normalize(1,1,['uri'=>$uri]);self::assertSame('audio',$voice['type']);self::assertSame('音频1',$voice['mention_name']);self::assertEqualsWithDelta(2,$voice['duration'],.1);
            foreach([[1,1,1,20*1024*1024],[1,1,60,10],[1,2,60,20*1024*1024],[2,1,60,20*1024*1024]]as[$t,$u,$seconds,$bytes]){
                $error=null;try{Media::validateAudio($t,$u,$uri,$seconds,$bytes);}catch(\Throwable $e){$error=$e;}self::assertNotNull($error,'A cached probe must not bypass the current limits or owner');
            }
            Db::name('tenant_file')->where('id',$id)->update(['delete_time'=>time()]);$error=null;try{Media::validateAudio(1,1,$uri);}catch(\Throwable $e){$error=$e;}self::assertNotNull($error,'Deleting the owned receipt invalidates access even with a cached probe');
        }finally{Db::rollback();unlink($dir.'/voice.wav');rmdir($dir);}
    }
}
