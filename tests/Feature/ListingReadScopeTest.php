<?php
namespace Tests\Feature;

use app\common\lists\BaseDataLists;
use app\common\service\database\ListingReadScope;
use app\common\service\{FileService,JsonService};
use app\common\service\storage\StorageConfigService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ListingReadScopeTest extends TestCase
{
    public function testListFormattingSharesOnlyMetadataAndReleasesItBeforeTheNextRead(): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();
        $db=config('database.connections.mysql');if($db['hostname']!=='127.0.0.1'||!in_array($db['database'],['x_cn','z_cn'],true))self::markTestSkipped('Local read-only fixture');
        request()->tenantId=1;request()->source=\app\common\enum\AdminTerminalEnum::TENANT;
        $connections=config('database.connections');$connections['mysql']['trigger_sql']=true;config(['connections'=>$connections],'database');Db::connect('mysql',true);
        $queries=0;Db::listen(static function($sql)use(&$queries){if(str_starts_with($sql,'SELECT'))$queries++;});
        $list=new class extends BaseDataLists {
            public function __construct(){$this->export='';$this->pageNo=1;$this->pageSize=30;}
            public function lists(): array {$out=[];for($i=0;$i<30;$i++)$out[]=['avatar'=>FileService::getFileUrl('uploads/images/list-scope-fixture.png')];return $out;}
            public function count(): int {return 30;}
        };
        $expected=$list->lists();$baseline=$queries;$queries=0;
        $response=JsonService::dataLists($list)->getData();$scoped=$queries;
        self::assertSame($expected,$response['data']['lists']);self::assertSame(30,$response['data']['count']);
        self::assertLessThan($baseline,$scoped);self::assertLessThanOrEqual(10,$scoped);
        self::assertNull(request()->listingReadScope);
        $before=$queries;StorageConfigService::isTenantStorageEnabled(1);self::assertGreaterThan($before,$queries);
        self::assertNull(request()->marketCatalogReadScope);
        try{ListingReadScope::run(static function(){throw new \RuntimeException('fixture');});}catch(\RuntimeException $e){self::assertSame('fixture',$e->getMessage());}
        self::assertNull(request()->listingReadScope);
    }
}
