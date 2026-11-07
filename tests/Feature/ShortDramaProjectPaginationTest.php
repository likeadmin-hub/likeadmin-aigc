<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{AigcShortDramaService as Drama, ShortDramaCanvasService as Canvas};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaProjectPaginationTest extends TestCase
{
    private bool $transaction = false;
    private array $ids = [];
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Local rollback fixtures only');
        (new \think\App())->initialize(); Db::startTrans(); $this->transaction = true;
        foreach (['drama'=>'aigc_short_drama_project','canvas'=>'aigc_short_drama_canvas'] as $kind=>$table) {
            for ($i=0; $i<5; $i++) {
                $row=['tenant_id'=>2000000727,'user_id'=>7,'title'=>'page_fixture_'.$i,'create_time'=>100+$i,'update_time'=>200-$i];
                if ($kind==='canvas') $row+=['nodes_json'=>'[]','edges_json'=>'[]','viewport_json'=>'{}'];
                $this->ids[$kind][]=Db::name($table)->insertGetId($row);
            }
            foreach ([['tenant_id'=>2000000728],['user_id'=>8],['delete_time'=>1]] as $override) {
                Db::name($table)->insert(array_replace($row,$override,['title'=>'hidden_fixture']));
            }
        }
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }
    public function testDramaPagesAreCompleteIsolatedAndKeepCreationOrderByDefault(): void
    {
        $ids=[];
        foreach ([1,2,3] as $page) {
            $data=Drama::projectLists(2000000727,7,['page_no'=>$page,'page_size'=>2]);
            self::assertSame(5,$data['count']);self::assertSame($page,$data['page_no']);
            $ids=array_merge($ids,array_column($data['lists'],'id'));
        }
        self::assertSame(array_reverse($this->ids['drama']),$ids);
        self::assertCount(5,array_unique($ids));
        $recent=Drama::projectLists(2000000727,7,['page_size'=>8,'sort_by'=>'updated']);
        self::assertSame($this->ids['drama'],array_column($recent['lists'],'id'));
    }
    public function testCanvasRecentOrderAndCreatedPagesRemainIndependent(): void
    {
        $recent=Canvas::lists(2000000727,7,['page_size'=>8]);
        self::assertSame($this->ids['canvas'],array_column($recent['lists'],'id'));
        $ids=[];
        foreach ([1,2,3] as $page) {
            $data=Canvas::lists(2000000727,7,['page_no'=>$page,'page_size'=>2,'sort_by'=>'created']);
            self::assertSame(5,$data['count']);$ids=array_merge($ids,array_column($data['lists'],'id'));
        }
        self::assertSame(array_reverse($this->ids['canvas']),$ids);
    }
    public function testSearchCountEmptyPagesAndBoundsAreComputedOnTheServer(): void
    {
        foreach ([Drama::class.'::projectLists',Canvas::class.'::lists'] as $list) {
            $data=$list(2000000727,7,['page_no'=>1,'page_size'=>2,'keyword'=>'page_fixture_3']);
            self::assertSame(1,$data['count']);self::assertCount(1,$data['lists']);
            $empty=$list(2000000727,7,['page_no'=>2,'page_size'=>2,'keyword'=>'page_fixture_3']);
            self::assertSame(1,$empty['count']);self::assertSame([],$empty['lists']);
            $clamped=$list(2000000727,7,['page_no'=>-1,'page_size'=>0]);
            self::assertSame(1,$clamped['page_no']);self::assertSame(1,$clamped['page_size']);
        }
    }
}
