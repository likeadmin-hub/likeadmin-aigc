<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoFramePolicy as P;
final class MicroVideoFramePolicyTest extends TestCase
{
    private function segments(): array{return [['id'=>11,'sort'=>1,'image_indices'=>'[0]','start_state'=>'人物手持蓝瓶站定','end_state'=>'抬起蓝瓶','prompt'=>'0-3秒：抬手','continuity'=>'保持人物'],['id'=>12,'sort'=>2,'image_indices'=>'[0,1]','start_state'=>'抬起蓝瓶','end_state'=>'瓶身正对镜头','prompt'=>'0-3秒：展示','continuity'=>'保持人物']];}
    public function testSharedBoundaryCountAndIdentity(): void{$defs=P::definitions(['continuity'=>'同一人物蓝瓶'], $this->segments(),3);self::assertCount(3,$defs);$links=P::links($this->segments(),[['id'=>21,'boundary_index'=>0],['id'=>22,'boundary_index'=>1],['id'=>23,'boundary_index'=>2]]);self::assertSame($links[11]['last_frame_id'],$links[12]['first_frame_id']);self::assertSame(22,$links[11]['last_frame_id']);}
    public function testReferencesAreNeverSilentlyDroppedForAnchor(): void{$this->expectException(\InvalidArgumentException::class);P::definitions(['continuity'=>'同一人物'], $this->segments(),2);}
    public function testRevisionOwnershipAndConfirmationBarriers(): void{$task=['id'=>1,'tenant_id'=>3,'user_id'=>4,'plan_revision'=>2,'frames_revision'=>5,'generation_mode'=>'start_end'];$frames=[];foreach([0,1,2] as $i)$frames[]=['id'=>20+$i,'task_id'=>1,'tenant_id'=>3,'user_id'=>4,'boundary_index'=>$i,'version'=>1,'status'=>'success','confirmed'=>1,'image_uri'=>'uploads/frame'.$i.'.png','is_latest'=>1];self::assertCount(3,P::ready($task,$frames,2));$frames[1]['user_id']=9;$this->expectException(\InvalidArgumentException::class);P::ready($task,$frames,2);}
    public function testQuoteIdentityChangesOnModeFrameVersionAndPlanRevision(): void{$t=['id'=>1,'plan_revision'=>2,'frames_revision'=>3,'generation_mode'=>'start_end','frame_model_id'=>'m','frame_quality'=>'q'];$f=[['id'=>10,'version'=>1,'image_uri'=>'uploads/a.png']];$a=P::identity($t,$f);$t['frames_revision']++;self::assertNotSame($a,P::identity($t,$f));$t['frames_revision']--;$f[0]['version']++;self::assertNotSame($a,P::identity($t,$f));$f[0]['version']--;$t['generation_mode']='reference';self::assertNotSame($a,P::identity($t,$f));}
    public function testDirtyTailRetainsOnlyIndependentBoundaries(): void{self::assertSame([2,3,4],P::dependentIndices(2,4));self::assertSame([],P::dependentIndices(5,4));}
    public function testVideoPayloadUsesModeGatedAuthoritativeBoundaryRoles(): void
    {
        $assets=P::assets('start_end',['managed/shared-first.png','managed/shared-last.png']);self::assertSame(['first_frame_image','last_frame_image'],array_column($assets,'role'));self::assertSame(['managed/shared-first.png','managed/shared-last.png'],array_column($assets,'uri'));
        $reference=P::assets('reference',['original/a.png','original/b.png']);self::assertSame(['reference_image','reference_image'],array_column($reference,'role'));$single=P::assets('reference',['original/a.png'],'image_to_video');self::assertSame('first_frame_image',$single[0]['role']);$this->expectException(\InvalidArgumentException::class);P::assets('start_end',['managed/incomplete.png']);
    }

}
