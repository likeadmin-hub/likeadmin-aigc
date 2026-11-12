<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_motion_sync\MotionSyncMedia;
use app\common\service\storage\ManagedStorageReader;

final class MotionSyncMediaTest extends TestCase
{
    private function body(): array
    {return ['model_key'=>'kling-2.6-motion-control','image'=>['mime_type'=>'image/png','file_size'=>1000,'width'=>400,'height'=>800],
        'video'=>['mime_type'=>'video/mp4','file_size'=>100000,'duration'=>3.2]];}
    public function testPublishedTwoSixImageDimensionsAndFormatsAreEnforced(): void
    {
        MotionSyncMedia::assertFacts($this->body());self::assertTrue(true);
        foreach([['width'=>300],['height'=>1200],['mime_type'=>'image/webp'],['file_size'=>10485761]] as $change){
            $body=$this->body();$body['image']=array_replace($body['image'],$change);$rejected=false;
            try{MotionSyncMedia::assertFacts($body);}catch(\InvalidArgumentException){$rejected=true;}
            self::assertTrue($rejected,'Invalid 2.6 character input was accepted');
        }
    }
    public function testThreeZeroDoesNotInheritUndeclaredTwoSixDimensions(): void
    {
        $body=$this->body();$body['model_key']='kling-3.0-motion-control';$body['image']['width']=250;
        MotionSyncMedia::assertFacts($body);self::assertTrue(true);
        $body['video']['file_size']=100*1024*1024+1;
        $this->expectException(\InvalidArgumentException::class);MotionSyncMedia::assertFacts($body);
    }
    public function testObjectReaderRejectsURLsAndTraversalBeforeAnyStorageAccess(): void
    {
        foreach(['https://untrusted.example/a.mp4','uploads/../private.mp4','uploads/%2e%2e/private.mp4','uploads//private.mp4'] as $key){
            $rejected=false;try{ManagedStorageReader::read(['storage_scope'=>'tenant','storage_engine'=>'aliyun','uri'=>$key],1,1000);}catch(\RuntimeException){$rejected=true;}
            self::assertTrue($rejected);
        }
    }
}
