<?php
declare(strict_types=1);
namespace app\common\service\promotion;

use think\facade\Db;
use think\facade\Log;
use app\common\service\virtualpay\VirtualPayMaintenanceService;
use app\common\service\virtualpay\VirtualPayPolicy;

/** Repair expired checkouts through the same channel-verified cancellation used by maintenance. */
final class PackagePendingService
{
    public function lists(int $tenant, int $user, string $from = '', int $package = 0): array
    {
        if ($tenant <= 0 || $user <= 0) throw new \RuntimeException('请先登录后查看订单');
        $query = Db::name('tenant_package_price_snapshot')->where([
            'tenant_id'=>$tenant, 'user_id'=>$user, 'status'=>'pending',
        ]);
        if ($from !== '') {
            if (!in_array($from, ['membership', 'recharge'], true)) throw new \RuntimeException('套餐类型不正确');
            $query->where('biz_type', $from);
        }
        if ($package > 0) $query->where('package_id', $package);
        $rows = $query->order('id desc')->limit(20)->select()->toArray();
        $result = []; $attempts = 0; $deadline = microtime(true) + 5;
        foreach ($rows as $row) {
            if ((int)$row['expires_at'] <= time()) {
                // Bound page-load work. Unresolved rows remain visible and cannot be silently replaced.
                if ($attempts++ < 3 && microtime(true) < $deadline) {
                    try {
                        (new VirtualPayMaintenanceService())->cancel($tenant, $user, $row['biz_type'], (int)$row['order_id']);
                    } catch (\Throwable $e) {
                        VirtualPayPolicy::assertRuntime();
                        Log::warning('Package checkout recovery: '.VirtualPayPolicy::error($e));
                    }
                }
                $current = PackagePricingService::snapshot($tenant, $row['biz_type'], (int)$row['order_id']);
                if (!$current || $current['status'] !== 'pending') continue;
                $row['resume_error'] = '原订单支付结果暂未确认，系统会继续保留订单；请稍后重试或联系客服';
            }
            $result[] = [
                'order_id'=>(int)$row['order_id'], 'from'=>$row['biz_type'],
                'package_id'=>(int)$row['package_id'], 'cycle'=>$row['cycle'],
                'order_amount'=>PackagePromotionPolicy::amount((int)$row['payable_cents']),
                'expires_at'=>(int)$row['expires_at'], 'resume_error'=>$row['resume_error'] ?? '',
            ];
        }
        return $result;
    }
}
