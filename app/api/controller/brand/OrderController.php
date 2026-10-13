<?php

namespace app\api\controller\brand;

use app\api\controller\BaseApiController;
use app\common\model\brand\TenantBrandOrder;
use app\common\service\brand\TenantBrandService;
use app\common\service\brand\BrandWorkflowService;
use app\common\service\brand\BrandOrderPolicy;
use think\facade\Db;
use think\facade\Log;
use Exception;

class OrderController extends BaseApiController
{
    public function requestRefund()
    {
        try {
            \app\common\service\brand\BrandRefundService::request((int)$this->request->tenantId,$this->userId,(int)$this->request->post('order_id',0),(string)$this->request->post('note',''));
            return $this->success('退款申请已提交，请等待审核',[],1,1);
        } catch (\Throwable $e) { Log::error('OEM refund request: '.$e->getMessage()); return $this->fail(BrandOrderPolicy::error($e)); }
    }
    public function lists()
    {
        $page=max(1,(int)$this->request->get('page_no',1)); $size=min(50,max(1,(int)$this->request->get('page_size',20)));
        $query=Db::name('tenant_brand_order')->where(['tenant_id'=>(int)$this->request->tenantId,'user_id'=>$this->userId])->whereNull('delete_time');
        $count=(clone $query)->count();
        $rows=$query->order('id','desc')->page($page,$size)->select()->toArray();
        return $this->success('获取成功',['lists'=>array_map(fn($row)=>TenantBrandService::formatBrandOrder($row,'user'),$rows),'count'=>$count,'page_no'=>$page,'page_size'=>$size]);
    }

    public function supplement()
    {
        try {
            BrandWorkflowService::supplement((int)$this->request->tenantId,$this->userId,(int)$this->request->post('order_id',0),$this->request->post());
            return $this->success('资料已补充，等待服务商重新审核',[],1,1);
        } catch (\Throwable $e) { Log::error('OEM supplement: '.$e->getMessage()); return $this->fail(BrandOrderPolicy::error($e)); }
    }
    public function create()
    {
        try {
            $result = TenantBrandService::createBrandOrder(
                (int)$this->request->tenantId,
                $this->userId,
                $this->getUserTerminal(),
                $this->request->post()
            );
            return $this->success('创建成功', $result, 1, 1);
        } catch (\Throwable $e) {
            Log::error('OEM create: '.$e->getMessage());
            return $this->fail(BrandOrderPolicy::error($e));
        }
    }

    public function detail()
    {
        $order = TenantBrandOrder::where([
            'tenant_id' => (int)$this->request->tenantId,
            'user_id' => $this->userId,
            'id' => (int)$this->request->get('order_id', 0),
        ])->findOrEmpty();
        if ($order->isEmpty()) {
            return $this->fail('贴牌订单不存在');
        }
        return $this->success('获取成功', BrandWorkflowService::detail($order->toArray(),'user'));
    }

    public function cancel()
    {
        try {
            $order = TenantBrandOrder::where([
                'tenant_id' => (int)$this->request->tenantId,
                'user_id' => $this->userId,
                'id' => (int)$this->request->post('order_id', 0),
            ])->findOrEmpty();
            if ($order->isEmpty()) return $this->fail('贴牌订单不存在');
            if ((int)$order['flow_version']===2) {
                \app\common\service\brand\BrandWorkflowService::cancel((int)$this->request->tenantId,$this->userId,(int)$order['id']);
                return $this->success('订单已取消',[],1,1);
            }
            if ((int)$order['pay_status'] !== 0) return $this->fail('已支付订单不能取消');
            // Reuse expiry semantics: set immediate expiry and release atomically.
            $order->save(['reserve_expire_time' => time(), 'update_time' => time()]);
            TenantBrandService::expirePendingOrders();
            return $this->success('订单已取消', [], 1, 1);
        } catch (\Throwable $e) {
            Log::error('OEM cancel: '.$e->getMessage());
            return $this->fail(BrandOrderPolicy::error($e));
        }
    }
}
