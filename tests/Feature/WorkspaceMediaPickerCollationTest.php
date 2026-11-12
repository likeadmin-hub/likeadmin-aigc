<?php
namespace Tests\Feature;
use app\common\service\WorkspaceMediaPickerService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class WorkspaceMediaPickerCollationTest extends TestCase
{
    public function testMixedLegacyAndJsonSourcesRemainSearchableAndOwned(): void
    {
        if(getenv('AIGC_VIDEO_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local database fixture required');
        (new \think\App())->initialize();self::assertSame('127.0.0.1',config('database.connections.mysql.hostname'));
        self::assertContains(config('database.connections.mysql.database'),['x_cn','z_cn']);
        $sources=[];$tables=[];$marker='picker_'.bin2hex(random_bytes(5)).'_';
        try {
            foreach(['legacy'=>'utf8mb4_general_ci','current'=>'utf8mb4_unicode_ci','json'=>'utf8mb4_bin'] as $name=>$collation){
                $suffix=$marker.$name;$table=Db::name($suffix)->getTable();
                Db::execute('CREATE TABLE `'.$table.'` (id INT PRIMARY KEY, tenant_id INT, user_id INT, create_time INT, delete_time INT, prompt TEXT, uri TEXT, storage_scope VARCHAR(20), storage_engine VARCHAR(20), storage_domain TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE='.$collation);$tables[]=$table;
                Db::table($table)->insert(['id'=>1,'tenant_id'=>1,'user_id'=>1,'create_time'=>100,'delete_time'=>0,'prompt'=>'中文素材 '.$name,'uri'=>'uploads/'.$name.'.png','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'']);
                $sources[$name.':result']=['table'=>$suffix,'type'=>"'image'",'name'=>'r.prompt','uri'=>'r.uri','extra'=>$name==='json'?"JSON_OBJECT('说明',r.prompt)":"''"];
            }
            $query=new \ReflectionMethod(WorkspaceMediaPickerService::class,'candidates');$query->setAccessible(true);
            $rows=$query->invoke(null,1,1,$sources,['image'],[],'中文',null);
            self::assertCount(3,$rows);self::assertSame(['current:result','json:result','legacy:result'],array_column($rows,'stream'));
            self::assertSame('中文素材 json',json_decode($rows[1]['extra'],true)['说明']);
            self::assertSame([], $query->invoke(null,1,2,$sources,['image'],[],'中文',null));
            self::assertSame([], $query->invoke(null,2,1,$sources,['image'],[],'中文',null));
        } finally {foreach(array_reverse($tables) as $table)Db::execute('DROP TABLE `'.$table.'`');}
    }
}
