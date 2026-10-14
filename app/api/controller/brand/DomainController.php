<?php
declare(strict_types=1);
namespace app\api\controller\brand;
use app\api\controller\BaseApiController;
use app\common\service\brand\BrandDomainService;
use app\common\service\brand\BrandOrderPolicy;
use think\facade\Db;
use think\facade\Log;
class DomainController extends BaseApiController {
    public array $notNeedLogin=['probe'];
    public function prepare() {
        try { return $this->success('域名验证资料已生成',BrandDomainService::prepare((int)$this->request->tenantId,$this->userId,(int)$this->request->post('order_id',0),(string)$this->request->post('domain',''))); }
        catch(\Throwable $e) { Log::error('OEM domain prepare: '.$e->getMessage()); return $this->fail(BrandOrderPolicy::error($e)); }
    }
    public function verify() {
        try { return $this->success('域名已验证并启用',BrandDomainService::verify((int)$this->request->tenantId,$this->userId,(int)$this->request->post('order_id',0))); }
        catch(\Throwable $e) { Log::error('OEM domain verify: '.$e->getMessage()); return $this->fail(BrandOrderPolicy::error($e)); }
    }
    public function probe() {
        $token=(string)$this->request->get('challenge','');
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return json(['error'=>'invalid challenge'],404);
        $host=strtolower((string)parse_url($this->request->domain(),PHP_URL_HOST));
        $row=Db::name('tenant_brand_domain')->where(['challenge'=>$token,'domain'=>$host])->find();
        return $row?json(['oem_challenge'=>$token],200,['Cache-Control'=>'no-store']):json(['error'=>'unknown challenge'],404);
    }
}
