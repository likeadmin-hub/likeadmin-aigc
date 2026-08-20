<?php

namespace app\common\command;

use app\common\enum\PayEnum;
use app\common\model\power\TenantPowerOrder;
use app\common\service\pay\TenantPowerPaymentReconcileService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class ReconcileTenantPowerOrders extends Command
{
    protected function configure()
    {
        $this->setName('tenant_power:reconcile')
            ->addOption('sn', null, Option::VALUE_OPTIONAL, '算力订单号')
            ->addOption('tenant_id', null, Option::VALUE_OPTIONAL, '租户ID')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '最大扫描数量', 100)
            ->addOption('apply', null, Option::VALUE_NONE, '确认支付后执行订单结算；不传则只审计')
            ->setDescription('向支付平台核对并修复已付款但未到账的算力套餐订单');
    }

    protected function execute(Input $input, Output $output)
    {
        $query = TenantPowerOrder::where('pay_status', PayEnum::UNPAID)->order('id', 'desc');
        $sn = trim((string)$input->getOption('sn'));
        if ($sn !== '') {
            $query->where('order_sn', $sn);
        }
        $tenantId = (int)$input->getOption('tenant_id');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $limit = max(1, min(1000, (int)$input->getOption('limit')));
        $orders = $query->limit($limit)->select();
        $providerPaid = 0;
        $repaired = 0;

        foreach ($orders as $order) {
            try {
                $payment = TenantPowerPaymentReconcileService::inspect($order);
                if (!$payment['paid']) {
                    continue;
                }
                $providerPaid++;
                if (!(bool)$input->getOption('apply')) {
                    $output->writeln('待修复: ' . $order['order_sn']);
                    continue;
                }
                if (TenantPowerPaymentReconcileService::reconcile($order)) {
                    $repaired++;
                    $output->writeln('已修复: ' . $order['order_sn']);
                }
            } catch (\Throwable $e) {
                $output->writeln('核对失败: ' . $order['order_sn'] . ' - ' . $e->getMessage());
            }
        }

        $output->writeln(sprintf(
            '扫描 %d 笔，支付平台已付款 %d 笔，实际修复 %d 笔。%s',
            count($orders),
            $providerPaid,
            $repaired,
            (bool)$input->getOption('apply') ? '已执行结算。' : '当前为审计模式，加 --apply 后执行结算。'
        ));
        return true;
    }
}
