<?php
namespace Tests\Feature;
use app\common\service\update\SystemPackageUpdateService;
use PHPUnit\Framework\TestCase;
final class SystemSqlTransactionPlanTest extends TestCase
{
    private string $directory;
    protected function setUp():void { $this->directory=sys_get_temp_dir().'/system-sql-plan-'.bin2hex(random_bytes(8));mkdir($this->directory.'/sql/structure',0700,true); }
    protected function tearDown():void { foreach(glob($this->directory.'/sql/structure/*')as$p)unlink($p);rmdir($this->directory.'/sql/structure');rmdir($this->directory.'/sql');rmdir($this->directory); }
    private function plans(array $manifest,array $owners=[]):array
    {
        $method=new \ReflectionMethod(SystemPackageUpdateService::class,'transactionalSystemSqlPlans');$method->setAccessible(true);
        return $method->invoke((new \ReflectionClass(SystemPackageUpdateService::class))->newInstanceWithoutConstructor(),$this->directory,$manifest,$owners);
    }
    public function testNewCoreSqlPreparesOnlyDdlAndRetainsMetadataForFinalTransaction():void
    {
        $file='sql/structure/studio.sql';file_put_contents($this->directory.'/'.$file,"CREATE TABLE IF NOT EXISTS la_fixture (id int PRIMARY KEY) ENGINE=InnoDB; INSERT INTO la_app (code) VALUES ('fixture'); UPDATE la_tenant_app SET enable_status='disabled' WHERE app_code='fixture';");
        $plans=$this->plans(['version'=>'1.0.440','sql_order'=>[$file]]);self::assertCount(1,$plans);
        self::assertStringContainsString('CREATE TABLE',$plans[$file]['prepare']);self::assertStringNotContainsString('INSERT',$plans[$file]['prepare']);self::assertStringNotContainsString('UPDATE',$plans[$file]['prepare']);
        self::assertStringContainsString('INSERT INTO la_app',$plans[$file]['complete']);self::assertStringContainsString('UPDATE la_tenant_app',$plans[$file]['complete']);self::assertSame(['la_app','la_tenant_app'],$plans[$file]['tables']);
        self::assertSame([],$this->plans(['version'=>'1.0.439','sql_order'=>[$file]]));
        self::assertSame([],$this->plans(['version'=>'1.0.440','sql_order'=>[$file]],[$file=>['app_code'=>'fixture']]));
    }
    public function testUnknownSqlFailsBeforeAnyExecution():void
    {
        $file='sql/structure/unsafe.sql';file_put_contents($this->directory.'/'.$file,'CREATE TABLE IF NOT EXISTS la_fixture (id int); SET @sql=1;');
        $this->expectException(\RuntimeException::class);$this->plans(['version'=>'1.0.440','sql_order'=>[$file]]);
    }
    public function testRetirementDataStaysInCompletionWithTransactionEngineChecks():void
    {
        $file='sql/structure/retire.sql';file_put_contents($this->directory.'/'.$file,"-- likeadmin:retire-micro-video-v1\nSELECT 1;");
        $plans=$this->plans(['version'=>'1.0.440','sql_order'=>[$file]]);self::assertSame('',$plans[$file]['prepare']);self::assertStringContainsString('retire-micro-video-v1',$plans[$file]['complete']);self::assertContains('la_app_case',$plans[$file]['tables']);
    }
}
