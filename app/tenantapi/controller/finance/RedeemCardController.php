<?php
namespace app\tenantapi\controller\finance;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\logic\finance\RedeemCardLogic;
use app\tenantapi\validate\finance\RedeemCardValidate;
use app\common\service\redeem\RedeemCardService;
use app\common\service\redeem\RedeemCardException;
use app\common\service\redeem\RedeemCardPolicy;
use app\common\cache\TenantAdminAuthCache;

class RedeemCardController extends BaseAdminController
{
    public function options() { return $this->execute(fn()=>RedeemCardService::options($this->tenantId)); }
    public function batches() { return $this->execute(fn()=>RedeemCardLogic::lists($this->tenantId,'batches',$this->request->get())); }
    public function lists() { return $this->execute(fn()=>RedeemCardLogic::lists($this->tenantId,'cards',$this->request->get())); }
    public function records() { return $this->execute(fn()=>RedeemCardLogic::lists($this->tenantId,'records',$this->request->get())); }
    public function generate()
    {
        if(!$this->request->isPost())return $this->fail('请使用POST请求');
        $params=$this->request->post();$validator=new RedeemCardValidate();
        if(!$validator->check($params))return $this->fail((string)$validator->getError(),['error_code'=>'invalid']);
        return $this->execute(fn()=>RedeemCardLogic::generate($this->tenantId,$this->adminId,$params));
    }
    public function disable()
    {
        if(!$this->request->isPost())return $this->fail('请使用POST请求');
        return $this->execute(function(){RedeemCardLogic::disable($this->tenantId,$this->request->post());return [];});
    }
    private function execute(callable $action)
    {
        if($this->tenantId<=0||$this->adminId<=0)return $this->fail('请先登录', ['error_code'=>'unauthenticated'],-1);
        try {
            $permissions = ($this->adminInfo['root'] ?? null) === 1 ? [] : (new TenantAdminAuthCache($this->adminId))->getAdminUri();
        } catch (\Throwable $e) {
            // Missing menus, an empty cache or a cache failure must never grant issuance.
            $permissions = [];
        }
        if (!RedeemCardPolicy::allowsAdminAction($this->adminInfo, (string)$this->request->action(), $permissions)) {
            return $this->fail('权限不足，无法访问或操作', ['error_code'=>'forbidden']);
        }
        try{return $this->success('操作成功',$action(),1,0);}
        catch(RedeemCardException $e){return $this->fail($e->getMessage(),['error_code'=>$e->errorCode]);}
        catch(\Throwable $e){\think\facade\Log::error('Redeem card tenant operation failed: '.get_class($e));return $this->fail('卡密服务暂不可用，请稍后重试',['error_code'=>'unavailable']);}
    }
}
