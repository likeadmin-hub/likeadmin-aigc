<?php
declare(strict_types=1);
require __DIR__.'/Support/OemDisposable.php'; require __DIR__.'/Support/OemFixtures.php';
use think\facade\Db;
use app\common\service\brand\BrandDomainService as Domain;
use app\common\service\brand\BrandDomainProbe;
use app\common\service\brand\TenantBrandService as Brand;
use app\common\service\brand\BrandWorkflowService as Flow;
use app\common\service\brand\BrandPlatformService as Platform;
use app\common\service\brand\BrandProvisionService as Provision;
oemCheck(class_exists(Domain::class),'Domain ownership and TLS verification is missing');
class DomainProbeFixture extends BrandDomainProbe {
    public bool $owned=false,$routed=false,$tls=false;
    public function verify(string $domain,string $token,string $target): void {
        if(!$this->owned) throw new \app\common\service\brand\BrandUserException('域名归属尚未验证');
        if(!$this->routed) throw new \app\common\service\brand\BrandUserException('域名解析尚未指向本站');
        if(!$this->tls) throw new \app\common\service\brand\BrandUserException('HTTPS 尚未配置');
    }
}
Db::startTrans(); try {
    foreach(['localhost','127.0.0.1','https://example.com','abc.com/path','abc.com:8443','a..com','a.invalid','-bad.com'] as $bad) oemReject(fn()=>Domain::normalize($bad),'unsafe domain accepted: '.$bad);
    oemCheck(Domain::normalize('Brand.Example.COM')==='brand.example.com','canonical hostname');
    $f=oemFixture(); Db::name('tenant_brand_quota_bucket')->insert(['tenant_id'=>$f['seller'],'package_id'=>$f['package'],'total_quota'=>1,'remaining_quota'=>1]);
    $id=Brand::createBrandOrder($f['seller'],$f['user'],4,$f['params'])['order_id'];
    oemReject(fn()=>Domain::prepare($f['seller'],$f['user'],$id,'brand.example.com'),'domain activated before delivery');
    oemPaid($f,$id); Flow::review($f['seller'],9,$id,'approve',''); Flow::submit($f['seller'],9,$id); Platform::confirm($id,1); Provision::execute(Provision::claim('domain-fixture'));
    $challenge=Domain::prepare($f['seller'],$f['user'],$id,'brand.example.com');
    oemCheck(Domain::prepare($f['seller'],$f['user'],$id,'brand.example.com')['challenge']===$challenge['challenge'],'retries retain challenge');
    oemReject(fn()=>Domain::prepare($f['seller'],$f['user']+1,$id,'brand.example.com'),'cross-user domain ownership');
    $probe=new DomainProbeFixture(); app()->instance(BrandDomainProbe::class,$probe); \think\facade\Config::set(['http_host'=>'platform.example.com'],'project');
    foreach(['owned','routed','tls'] as $step) {
        Db::name('tenant_brand_domain')->where('order_id',$id)->update(['last_check_time'=>0]);
        oemReject(fn()=>Domain::verify($f['seller'],$f['user'],$id),'missing '.$step.' allowed domain'); $probe->$step=true;
        oemCheck((int)Db::name('tenant')->where('id',Flow::owned($f['seller'],$f['user'],$id)['child_tenant_id'])->value('domain_alias_enable')===1,'failed proof cannot enable alias');
    }
    Db::name('tenant_brand_domain')->where('order_id',$id)->update(['last_check_time'=>0]); Domain::verify($f['seller'],$f['user'],$id);
    $child=Flow::owned($f['seller'],$f['user'],$id)['child_tenant_id'];
    oemCheck(Db::name('tenant_domain_alias')->where(['tenant_id'=>$child,'domain'=>'brand.example.com','status'=>1])->count()===1,'verified domain maps to delivered tenant');
    Domain::verify($f['seller'],$f['user'],$id); oemCheck(Db::name('tenant_domain_alias')->where('tenant_id',$child)->count()===1,'verified retry retains mapping');
    echo "PASS domain input isolation, ownership/DNS/TLS gates and verified alias delivery\n";
} finally { Db::rollback(); }
