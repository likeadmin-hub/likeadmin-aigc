<?php
namespace app\api\controller;
use app\common\service\virtualpay\VirtualPayService;
use app\common\service\virtualpay\VirtualPaySessionService;
use app\common\service\virtualpay\MiniappShippingService;
use app\common\service\license\MiniprogramAccessService;
use think\facade\Db;
class VirtualPayController extends BaseApiController
{
    private function enabled(): void
    {
        if($this->getUserTerminal()!==1)throw new \RuntimeException('请在微信小程序中使用虚拟支付');
        (new MiniprogramAccessService())->assertEnabled();
    }
    public function session()
    {
        try{$this->enabled();return $this->data(['session_id'=>(new VirtualPaySessionService())->exchange((int)$this->request->tenantId,$this->userId,(string)$this->request->post('code'))]);}
        catch(\Exception $e){return $this->fail($e->getMessage());}
    }
    public function prepay()
    {
        try{$this->enabled();return $this->data((new VirtualPayService())->prepay((int)$this->request->tenantId,$this->userId,$this->getUserTerminal(),(string)$this->request->post('from'),(int)$this->request->post('order_id'),(string)$this->request->post('session_id'),(string)$this->request->post('platform'),0));}
        catch(\Exception $e){return $this->fail($e->getMessage());}
    }
    public function status()
    {
        try{return $this->data((new VirtualPayService())->status((int)$this->request->tenantId,$this->userId,(string)$this->request->get('from'),(int)$this->request->get('order_id')));}
        catch(\Exception $e){return $this->fail($e->getMessage());}
    }
    public function receipt()
    {
        try{
            $from=(string)$this->request->get('from');$id=(int)$this->request->get('order_id');VirtualPayService::order((int)$this->request->tenantId,$this->userId,$from,$id);
            $intent=Db::name('virtual_pay_intent')->where(['tenant_id'=>(int)$this->request->tenantId,'user_id'=>$this->userId,'biz_type'=>$from,'order_id'=>$id])->order('id desc')->find();
            if(!$intent)throw new \RuntimeException('支付记录不存在');return $this->data((new MiniappShippingService())->confirmation($intent));
        }catch(\Exception $e){return $this->fail($e->getMessage());}
    }
}
