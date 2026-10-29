<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaStoryPreviewDraft as Backup;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaStoryPreviewDraftTest extends TestCase
{
    public function testTerminalPreviewBackupIsIsolatedAndVersioned(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to transactional local DB tests');
        (new \think\App())->initialize(); Db::startTrans();
        try {
            $scope=['tenant_id'=>2000000719,'user_id'=>7,'task_id'=>'test_story_backup_'.bin2hex(random_bytes(8))];
            $preview=['content'=>'未完成故事','episodes'=>[['episode_number'=>1,'story_outline'=>'原有大纲']]];
            $request=['workflow_variant'=>'story_outline_v2','multi_episode'=>true,'episode_count'=>3];
            Db::name('aigc_short_drama_script_task')->insert($scope+['project_id'=>17,'status'=>'failed',
                'request_json'=>json_encode($request),'result_json'=>json_encode(['__story_preview'=>$preview],JSON_UNESCAPED_UNICODE),
                'create_time'=>1,'update_time'=>1]);
            $original=Db::name('aigc_short_drama_script_task')->where($scope)->find();
            $initial=Backup::view($scope['tenant_id'],7,$scope['task_id'],$preview);
            self::assertStringContainsString('原有大纲',$initial['text']);
            $saved=Backup::save($scope['tenant_id'],7,$scope['task_id'],17,$initial['token'],'用户整理的内容');
            self::assertSame('用户整理的内容',$saved['text']);
            self::assertSame($original,Db::name('aigc_short_drama_script_task')->where($scope)->find());
            self::assertSame($saved,Backup::view($scope['tenant_id'],7,$scope['task_id'],$preview));
            foreach ([[$scope['tenant_id'],7,$initial['token'],409],[$scope['tenant_id']+1,7,$saved['token'],404],[$scope['tenant_id'],8,$saved['token'],404]] as [$tenant,$user,$token,$code]) {
                try { Backup::save($tenant,$user,$scope['task_id'],17,$token,'覆盖'); self::fail('Unsafe save accepted'); }
                catch (\RuntimeException $error) { self::assertSame($code,$error->getCode()); }
            }
            Db::name('aigc_short_drama_script_task')->where($scope)->update(['status'=>'running']);
            try { Backup::save($scope['tenant_id'],7,$scope['task_id'],17,$saved['token'],'覆盖'); self::fail('Active task edited'); }
            catch (\RuntimeException $error) { self::assertSame(409,$error->getCode()); }
        } finally { Db::rollback(); }
    }
}
