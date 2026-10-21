<?php
namespace app\api\controller;

use app\api\validate\RedeemCardValidate;
use app\common\service\redeem\RedeemCardService;
use app\common\service\redeem\RedeemCardException;

class RedeemCardController extends BaseApiController
{
    // No notNeedLogin entries: the standard token/tenant middleware applies.
    public function redeem()
    {
        if(!$this->request->isPost())return $this->fail('请使用POST请求',['error_code'=>'invalid']);
        return $this->execute(function($tenant){
            RedeemCardService::throttle($tenant,$this->userId,(string)$this->request->ip());
            $params=$this->request->post();$validator=new RedeemCardValidate();
            if(!is_string($params['code']??null))throw new RedeemCardException('invalid','卡密格式无效');
            if(!$validator->check($params))throw new RedeemCardException('invalid',(string)$validator->getError());
            return RedeemCardService::redeem($tenant,$this->userId,(string)$params['code']);
        });
    }
    public function records() { return $this->execute(fn($tenant)=>RedeemCardService::listing($tenant,'records',$this->request->get(),$this->userId)); }
    private function execute(callable $action)
    {
        $tenant=(int)($this->request->tenantId??0);
        if($tenant<=0||$this->userId<=0||(!empty($this->userInfo['tenant_id'])&&(int)$this->userInfo['tenant_id']!==$tenant))return $this->fail('请先登录后兑换',['error_code'=>'unauthenticated'],-1);
        try{return $this->success('兑换成功',$action($tenant),1,0);}
        catch(RedeemCardException $e){return $this->fail($e->getMessage(),['error_code'=>$e->errorCode],$e->errorCode==='unauthenticated'?-1:0,0);}
        catch(\Throwable $e){\think\facade\Log::error('Redeem card operation failed: '.get_class($e));return $this->fail('兑换暂未完成，请稍后重试或联系客服',['error_code'=>'unavailable'],0,0);}
    }
}
