<?php
namespace Tests\Feature;

use app\common\enum\FileEnum;
use app\common\service\app\aigc_micro_video\MicroVideoSettings as Settings;
use app\common\service\app\aigc_micro_video\MicroVideoService as Video;
use app\common\service\app\aigc_micro_video\MicroVideoStore as Store;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class MicroVideoMarketSelectionTest extends TestCase
{
    public function testAdvertisedVideoModelsCanBeSubmittedAndRetriedWithoutChangingEarlierReceipts(): void
    {
        (new \think\App(dirname(__DIR__, 2).'/'))->initialize();
        $db = config('database.connections.mysql');
        if ($db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn','z_cn'], true)) {
            self::markTestSkipped('Requires local rollback database');
        }
        Db::startTrans();
        try {
            request()->tenantId = 1;
            $user = (int)Db::name('user')->where('tenant_id', 1)->value('id');
            self::assertGreaterThan(0, $user);
            Db::name('user')->where('id', $user)->update(['user_money'=>1000000]);
            Db::name('tenant')->where('id', 1)->update(['point_balance'=>1000000]);
            Store::table('config')->where('tenant_id',1)->delete();
            self::assertSame('market', Settings::get(1)['model_scope']);
            $catalog = Settings::detail(1);
            self::assertNotEmpty($catalog['models']);
            $uri = 'uploads/test/'.uniqid('micro').'.png';
            Db::name('tenant_file')->insert(['tenant_id'=>1,'source_id'=>$user,'source'=>FileEnum::SOURCE_USER,
                'type'=>FileEnum::IMAGE_TYPE,'uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'qcloud','storage_domain'=>'https://example.invalid']);
            $consumptions = Db::name('ai_consumption_log')->count();
            foreach ($catalog['models'] as $model) {
                $spec = $model['specs'][0];
                $data = ['model_id'=>$model['id'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio'],'theme'=>'镜头测试',
                    'images'=>[['uri'=>$uri]],'target_duration'=>30,'idempotency_key'=>uniqid('market-plan-')];
                $plan = Video::createPlan(1, $user, $data);
                $id = $plan['id'];
                $row = Store::owned(1, $user, $id);
                $snapshot = Store::json($row['config_snapshot']);
                self::assertNotEmpty($snapshot['vision_model_id']);
                self::assertNotEmpty($snapshot['script_model_id']);
                self::assertSame($model['id'], Store::json($row['params_json'])['model_id']);
                // Simulate the planner finishing two segments without contacting a provider.
                foreach ([1,2] as $sort) Store::table('segment')->insert(['tenant_id'=>1,'user_id'=>$user,'task_id'=>$id,
                    'sort'=>$sort,'duration'=>$spec['duration'],'title'=>'测试','prompt'=>'场景','image_indices'=>'[0]',
                    'start_state'=>'开始','end_state'=>'结束','continuity'=>'连贯','status'=>'pending']);
                Store::update($id, ['status'=>'awaiting_confirmation','plan_revision'=>1]);
                $quote = Video::estimate(1,$user,['id'=>$id]);
                Video::submit(1,$user,['id'=>$id,'quote_token'=>$quote['quote_token'],'idempotency_key'=>uniqid('submit-')]);
                $confirmed = Store::json(Store::owned(1,$user,$id)['confirmed_quote']);
                [$first,$next] = Store::segments($id);
                Store::table('segment')->where('id',$first['id'])->update(['status'=>'success']);
                Store::table('segment')->where('id',$next['id'])->update(['status'=>'failed']);
                Store::update($id, ['status'=>'waiting_continue']);
                $quote = Video::estimate(1,$user,['id'=>$id]);
                Video::retry(1,$user,['id'=>$id,'quote_token'=>$quote['quote_token'],'idempotency_key'=>uniqid('retry-')]);
                self::assertSame($confirmed['segments'], Store::json(Store::owned(1,$user,$id)['confirmed_quote'])['segments']);
                self::assertSame(['success','pending'], array_column(Store::segments($id),'status'));
            }
            self::assertSame($consumptions, Db::name('ai_consumption_log')->count());
            $only = $catalog['models'][0]['id'];
            Settings::save(1, ['video_model_ids'=>[$only], 'frame_model_ids'=>[]]);
            self::assertSame('restricted', Settings::get(1)['model_scope']);
            self::assertSame([$only], array_column(Settings::detail(1)['models'], 'id'));
            Store::table('config')->where('tenant_id',1)->update(['config_json'=>'{"video_model_ids":[],"frame_model_ids":[]}']);
            self::assertSame([],Settings::detail(1)['models']);
            $this->expectException(\InvalidArgumentException::class);
            Video::form(1,$user,['model_id'=>$only],false);
        } finally {
            Db::rollback();
            request()->tenantId = null;
        }
    }
}
