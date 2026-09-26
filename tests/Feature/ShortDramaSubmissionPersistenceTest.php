<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaSubmission;
use app\common\model\app\aigc_short_drama\AigcShortDramaScriptTask;
use think\facade\Db;
use PHPUnit\Framework\TestCase;

class ShortDramaSubmissionPersistenceTest extends TestCase
{
    private bool $transaction=false;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS')!=='1') self::markTestSkipped('Transactional fixtures only');
        (new \think\App())->initialize();Db::startTrans();$this->transaction=true;
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }

    public function testLostAcknowledgementReusesTaskAndDifferentContentIsRejected(): void
    {
        $params=['prompt'=>'原文','submission_key'=>'test:'.bin2hex(random_bytes(12))];$calls=0;
        $create=static function(array $receipt) use (&$calls): array {
            $calls++;$task='test_submit_'.bin2hex(random_bytes(8));
            AigcShortDramaScriptTask::create(['tenant_id'=>2000000719,'user_id'=>7,'project_id'=>0,'task_id'=>$task,'status'=>'pending',
                'idempotency_key'=>$receipt['_submission_key'],'request_json'=>json_encode($receipt),'prompt'=>'原文','delete_time'=>0]);
            return ['task_id'=>$task];
        };
        $first=ShortDramaSubmission::run(2000000719,7,'create',$params,$create);
        $retry=ShortDramaSubmission::run(2000000719,7,'create',$params,$create);
        self::assertSame($first['task_id'],$retry['task_id']);self::assertSame(1,$calls);
        $params['prompt']='不同内容';
        $this->expectExceptionCode(409);
        ShortDramaSubmission::run(2000000719,7,'create',$params,$create);
    }

    public function testUploadReplayUsesServerFileHashWithoutDispatchingAgain(): void
    {
        $path=tempnam(sys_get_temp_dir(),'sd-upload-replay-');
        file_put_contents($path,'保留上传原文');
        request()->withFiles(['file'=>['tmp_name'=>$path,'name'=>'剧本.txt','type'=>'text/plain','size'=>filesize($path),'error'=>0]]);
        try {
            $params=['submission_key'=>'sd:'.bin2hex(random_bytes(12)),'submission_version'=>2,'source'=>'home_script_upload','supplement'=>'原要求'];
            $identity=ShortDramaSubmission::identity(2000000719,7,'script_upload',array_replace($params,['_file_sha256'=>hash_file('sha256',$path),'_file_name'=>'剧本.txt']));
            $task='test_upload_'.bin2hex(random_bytes(8));
            AigcShortDramaScriptTask::create(['tenant_id'=>2000000719,'user_id'=>7,'project_id'=>0,'task_id'=>$task,'status'=>'pending',
                'idempotency_key'=>$identity['key'],'request_json'=>json_encode(['_submission_hash'=>$identity['hash']]),'prompt'=>'原文','delete_time'=>0]);
            $result=\app\common\service\app\aigc_short_drama\AigcShortDramaService::parseUploadedScript(2000000719,7,$params);
            self::assertSame($task,$result['task_id']);self::assertTrue($result['reused']);
            self::assertArrayNotHasKey('_dispatch_request',$result);
            self::assertNull(Db::query('SELECT IS_USED_LOCK(?) AS owner',['sd_submit_'.substr($identity['key'],0,48)])[0]['owner']);
            file_put_contents($path,'同名但是内容已变');
            $this->expectExceptionCode(409);
            \app\common\service\app\aigc_short_drama\AigcShortDramaService::parseUploadedScript(2000000719,7,$params);
        } finally {request()->withFiles([]);unlink($path);}
    }
}
