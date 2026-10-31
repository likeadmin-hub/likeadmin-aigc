<?php
namespace Tests\Feature;

use app\common\model\app\aigc_image\AigcImageTask;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** New synthetic parents/children only; every record is rolled back. No provider calls. */
class ImageAppHistoryReplayIdempotencyTest extends TestCase
{
    private array $rows = [];
    protected function setUp(): void
    {
        if (getenv('AIGC_VIDEO_PREPARATION_DB_TESTS') !== '1') self::markTestSkipped('Explicit rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        self::assertContains(Db::connect()->getConfig('database'), ['x_cn', 'z_cn']);
        Db::startTrans();
    }
    protected function tearDown(): void
    {
        if (!$this->rows) return;
        Db::rollback();
        foreach ($this->rows as [$table, $id]) self::assertSame(0, (int)Db::name($table)->where('id', $id)->count());
    }
    public static function apps(): array
    {
        return array_map(static fn($code) => [$code], ['product_image','product_multi_angle','product_suite','local_redraw',
            'image_translate','style_transfer','background_removal','fashion_lookbook','model_wear','one_click_cleanup',
            'outpaint','fitting','photo_restore']);
    }
    /** @dataProvider apps */
    public function testRepeatedTerminalChildFactsPreserveTheWholeParentAndLateTransitionsStillWork(string $code): void
    {
        $app = 'aigc_' . $code;
        $name = 'Aigc' . str_replace(' ', '', ucwords(str_replace('_', ' ', $code)));
        $class = 'app\\common\\model\\app\\' . $app . '\\' . $name . 'Task';
        $service = 'app\\common\\service\\app\\' . $app . '\\' . $name . 'Service';
        $child = AigcImageTask::create(['tenant_id'=>1,'user_id'=>1999999384,'provider'=>'power_market',
            'status'=>'failed','error'=>'Synthetic failure','finish_time'=>0,'create_time'=>1700000000,
            'update_time'=>1700000001,'delete_time'=>0]);
        $this->rows[] = ['aigc_image_task', $child['id']];
        $parent = $class::create(($code === 'product_suite' ? ['quantity'=>1] : []) + ['tenant_id'=>1,'user_id'=>1999999384,'image_task_id'=>$child['id'],
            'image_task_ids'=>[$child['id']],'status'=>'failed','error'=>'Synthetic failure','finish_time'=>1700000002,
            'create_time'=>1700000000,'update_time'=>1700000003,'delete_time'=>0]);
        $table = $app . '_task';
        $this->rows[] = [$table, $parent['id']];
        $replay = new \ReflectionMethod($service, 'syncTaskFromImageTask');
        // Normalize legitimate aggregate facts once, then verify exact row stability.
        $replay->invoke(null, $parent);
        $before = Db::name($table)->where('id', $parent['id'])->find();
        self::assertSame(1700000002, (int)$before['finish_time']);
        for ($i=0; $i<2; $i++) {
            $replay->invoke(null, $parent);
            self::assertSame($before, Db::name($table)->where('id', $parent['id'])->find());
        }
        $parent->save(['status'=>'running','finish_time'=>0,'update_time'=>1700000004]);
        $replay->invoke(null, $parent);
        $after = Db::name($table)->where('id', $parent['id'])->find();
        self::assertSame('failed', $after['status']);
        self::assertGreaterThan(1700000004, (int)$after['finish_time']);
        $replay->invoke(null, $parent);
        self::assertSame($after, Db::name($table)->where('id', $parent['id'])->find());
        self::assertSame(1, (int)Db::name('aigc_image_task')->where('id', $child['id'])->count());
    }
}
