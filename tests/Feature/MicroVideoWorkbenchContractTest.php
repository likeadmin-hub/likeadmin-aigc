<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchWorker as Worker;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchPolicy as P;
final class MicroVideoWorkbenchContractTest extends TestCase
{
    public function testEveryRegisteredWorkbenchActionHasControllerAndTenantPermission(): void
    {$root=dirname(__DIR__,2);$schema=json_decode(file_get_contents($root.'/app/apps/aigc_micro_video/api_schema.json'),true);$perms=json_decode(file_get_contents($root.'/app/apps/aigc_micro_video/permissions/tenant.json'),true);$tenantPaths=array_column($perms,'api_path');foreach($schema['apis'] as $api){if(!str_starts_with($api['api_path'],'app.aigc_micro_video.workbench/'))continue;$scene=$api['scene']==='user'?'api':'tenantapi';$class='app\\'.$scene.'\\controller\\app\\aigc_micro_video\\WorkbenchController';$action=substr($api['api_path'],strrpos($api['api_path'],'/')+1);self::assertTrue(method_exists($class,$action),$api['api_path']);self::assertSame(1,$api['need_login']);if($scene==='tenantapi')self::assertContains($api['api_path'],$tenantPaths);}}
    public function testInstallAndBothUpgradePathsHaveIdenticalAdditiveTables(): void
    {$root=dirname(__DIR__,2);$ddl=file_get_contents($root.'/app/apps/aigc_micro_video/migrations/zzz_20261018_workbench.sql');foreach(['upgrade/zzz_20261018_micro_video_workbench.sql','public/upgrade/zzz_20261018_micro_video_workbench.sql'] as $p)self::assertSame($ddl,file_get_contents($root.'/'.$p));foreach(['app/apps/aigc_micro_video/migrations/install.sql','public/install/db/like.sql'] as $p)foreach(['record','version','quote','job','execution','asset'] as $suffix)self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_workbench_'.$suffix.'`',file_get_contents($root.'/'.$p));self::assertStringNotContainsString('ALTER TABLE',$ddl);}
    public function testMalformedPaidTextResultIsRejectedWithoutOverwritingReceipt(): void
    {$row=['action'=>'rewrite','request_json'=>json_encode(['snapshot'=>['target_duration'=>30]])];$this->expectException(\InvalidArgumentException::class);Worker::parse($row,['content'=>json_encode(['script'=>['text'=>'too short','segments'=>[['duration'=>15,'prompt'=>'0-15秒：静态画面。']]]])]);}
    public function testRoleAnalysisCannotInjectExternalImages(): void
    {$row=['action'=>'analyze_roles','request_json'=>json_encode(['snapshot'=>[]])];$r=Worker::parse($row,['content'=>json_encode(['roles'=>[['role_id'=>'lead','name'=>'主角','images'=>[['uri'=>'https://evil.invalid/x.png']]]]])]);self::assertSame([],$r['roles'][0]['images']);}
    public function testDeletedRoleReferencedInSceneCannotBeSaved(): void
    {$this->expectException(\InvalidArgumentException::class);P::body('project',['roles'=>[],'scenes'=>[['scene_id'=>'s','role_ids'=>['deleted']]]]);}
    /** @dataProvider selectedRewriteCases */
    public function testSelectedModelRewriteAcceptsItsRealContractAndPreservesContinuity(int $target,array $supported,string $resultContinuity,string $expected): void
    {
        $contract=\app\common\service\app\aigc_micro_video\MicroVideoContentPlanning::contract(['mode'=>'theme','target_duration'=>$target],$supported,24,3);
        $segments=[];foreach($contract['segment_durations'] as $n=>$duration)$segments[]=['sort'=>$n+1,'title'=>'连贯剧情','duration'=>$duration,'prompt'=>"0-3秒：主角在同一房间拿起商品。\n3-".$duration.'秒：主角自然展示，配角接过后回应。','image_indices'=>[0],'start_state'=>'主角站在桌边','end_state'=>'配角接过商品','continuity'=>'保持人物服饰、商品形态和镜头方向'];
        $script=['text'=>'主角展示商品，配角回应，故事自然收束。','segments'=>$segments];if($resultContinuity!=='')$script['continuity']=$resultContinuity;
        $request=['snapshot'=>['model_id'=>'selected-video-model','target_duration'=>$target,'template_snapshot'=>['body'=>['reference_rules'=>'模板要求同一红色外套与真实商品外观']]],'planning_contract'=>$contract,'video_reference_limit'=>3];
        $r=Worker::parse(['action'=>'rewrite','request_json'=>json_encode($request)],['content'=>json_encode(['script'=>$script])]);self::assertSame($contract['segment_durations'],array_column($r['script']['segments'],'duration'));self::assertSame($expected,$r['script']['continuity']);
    }
    public static function selectedRewriteCases(): array
    {return [[30,[10,15],'模型明确整片同一服饰与光线','模型明确整片同一服饰与光线'],[30,[10,15],'','模板要求同一红色外套与真实商品外观'],[4,[30],'','模板要求同一红色外套与真实商品外观']];}
    public function testUnselectedModelRewriteCannotClaimSuccessWithMissingSegmentStateFields(): void
    {$this->expectException(\InvalidArgumentException::class);Worker::parse(['action'=>'rewrite','request_json'=>json_encode(['snapshot'=>['target_duration'=>30]])],['content'=>json_encode(['script'=>['text'=>'完整内容','segments'=>[['title'=>'展示','duration'=>30,'prompt'=>"0-3秒：拿起商品。\n3-30秒：人物展示后递交配角。",'image_indices'=>[0]]]]])]);}
}
