<?php
namespace tests\Feature;

use app\common\http\middleware\LikeAdminAllowMiddleware;
use app\common\service\tenant\TenantDomainAliasService;
use app\common\service\brand\BrandDomainService;
use app\common\service\brand\BrandUserException;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;
use think\Request;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TenantSubdomainAliasTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App())->initialize();
        Config::set(['default'=>'alias_test','connections'=>['alias_test'=>[
            'type'=>'sqlite','database'=>':memory:','prefix'=>'la_',
            'fields_strict'=>true,'fields_cache'=>false,
        ]]], 'database');
        Config::set(['http_host'=>'xxx.com, https://console.example.com:8443/'], 'project');
        Db::execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY,sn TEXT,domain_alias TEXT,domain_alias_enable INTEGER,disable INTEGER,delete_time INTEGER)');
        Db::execute('CREATE TABLE la_tenant_domain_alias (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,domain TEXT,is_primary INTEGER,status INTEGER,create_time INTEGER,update_time INTEGER,delete_time INTEGER)');
        Db::name('tenant')->insertAll([
            ['id'=>1,'sn'=>'one','domain_alias'=>'','domain_alias_enable'=>0,'disable'=>0],
            ['id'=>2,'sn'=>'two','domain_alias'=>'legacy.xxx.com','domain_alias_enable'=>0,'disable'=>0],
        ]);
        $this->request('xxx.com');
    }

    private function request(string $host,string $path=''): Request
    {
        $request=(new Request())->setHost($host)->setPathinfo($path)->setMethod('GET');
        $request->withServer(['HTTP_HOST'=>$host,'REQUEST_URI'=>'/'.$path]);
        app()->instance('request',$request);
        return $request;
    }

    /** @dataProvider availableAliases */
    public function testAvailableSubdomainCanBeSavedResolvedAndIsolated(string $host): void
    {
        $primary=TenantDomainAliasService::syncTenantAliases(1,[['domain'=>$host,'is_primary'=>1,'status'=>1]],'',true);
        self::assertSame($host,$primary);
        self::assertSame(1,(int)TenantDomainAliasService::findTenantByDomain($host)->id);
        foreach(['platform/login'=>404,'platformapi/config/getConfig'=>403] as $path=>$code){
            $r=(new LikeAdminAllowMiddleware())->handle($this->request($host,$path),static fn()=>response('allowed'));
            self::assertSame($code,$r->getCode());
        }
        $r=(new LikeAdminAllowMiddleware())->handle($this->request($host,'tenantapi/config/getConfig'),static fn($r)=>response(['tenant_id'=>$r->tenantId]));
        self::assertSame(1,(int)$r->getData()['tenant_id']);
    }

    public static function availableAliases(): array
    {
        return [['x.xxx.com'],['one.xxx.com'],['nested.x.xxx.com'],['x.console.example.com'],['independent.example.net']];
    }

    /** @dataProvider occupiedAliases */
    public function testPlatformAndOccupiedTenantHostsRemainReserved(string $host,string $message,int $tenantId): void
    {
        Db::name('tenant_domain_alias')->insert(['tenant_id'=>2,'domain'=>'bound.xxx.com','status'=>1]);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($message);
        TenantDomainAliasService::validateAliases([['domain'=>$host,'is_primary'=>1,'status'=>1]],$tenantId,true);
    }

    public static function occupiedAliases(): array
    {
        $cases=[];
        foreach([0,1] as $tenantId){
            foreach(['xxx.com','HTTPS://XXX.COM:443/','console.example.com'] as $host)$cases[]=[$host,'租户域名不能与平台域名一致',$tenantId];
            foreach(['bound.xxx.com','legacy.xxx.com','two.xxx.com','two.console.example.com'] as $host)$cases[]=[$host,'租户别名已存在',$tenantId];
        }
        return $cases;
    }

    public function testNewTenantCanUseAnUnusedSubdomain(): void
    {
        TenantDomainAliasService::validateAliases([['domain'=>'new.xxx.com','status'=>1]],0,true);
        self::assertSame(0,Db::name('tenant_domain_alias')->count());
    }

    public function testCurrentPlatformHostAndItsTenantSubdomainWithoutConfiguration(): void
    {
        Config::set(['http_host'=>''],'project');
        TenantDomainAliasService::validateAliases([['domain'=>'x.xxx.com','status'=>1]],1,true);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('租户域名不能与平台域名一致');
        TenantDomainAliasService::validateAliases([['domain'=>'xxx.com','status'=>1]],1,true);
    }

    public function testDisabledTenantDefaultSubdomainCannotBeReassigned(): void
    {
        Db::name('tenant')->where('id',2)->update(['disable'=>1]);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('租户别名已存在');
        TenantDomainAliasService::validateAliases([['domain'=>'two.xxx.com','status'=>1]],1,true);
    }

    public function testOtherDomainWorkflowsKeepTheirExistingSubdomainPolicy(): void
    {
        $this->expectException(BrandUserException::class);
        BrandDomainService::normalize('x.xxx.com');
    }
}
