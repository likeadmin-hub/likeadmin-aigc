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
}
