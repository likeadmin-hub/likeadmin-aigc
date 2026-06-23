<?php

namespace app\common\command;

use app\common\enum\PayEnum;
use app\common\model\recharge\RechargeOrder;
use app\common\service\recharge\RechargeCreditService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class RepairRecharge extends Command
{
    protected function configure()
    {
        $this->setName('recharge:repair')
            ->addOption('sn', null, Option::VALUE_OPTIONAL, '充值订单号')
            ->addOption('tenant_id', null, Option::VALUE_OPTIONAL, '租户ID')
            ->addOption('start', null, Option::VALUE_OPTIONAL, '开始时间，例如 2026-06-23 00:00:00')
            ->addOption('end', null, Option::VALUE_OPTIONAL, '结束时间，例如 2026-06-23 23:59:59')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '最大扫描数量', 100)
            ->addOption('apply', null, Option::VALUE_NONE, '执行补点；不传则只审计')
            ->addOption('force', null, Option::VALUE_NONE, '强制补点；必须同时指定 --sn 和 --apply')
            ->setDescription('审计/修复已支付但缺少充值到账流水的订单');
    }

    protected function execute(Input $input, Output $output)
    {
        $apply = (bool)$input->getOption('apply');
        $force = (bool)$input->getOption('force');
        $sn = trim((string)$input->getOption('sn'));
        if ($force && (!$apply || $sn === '')) {
            $output->writeln('--force 必须同时指定 --sn 和 --apply');
            return false;
        }
        $orders = $this->orders($input);
        $total = count($orders);
        $suspect = 0;
        $repaired = 0;

        if ($total === 0) {
            $output->writeln('没有找到符合条件的已支付充值订单');
            return true;
        }

        foreach ($orders as $order) {
            if (!$force && RechargeCreditService::hasRechargeLog((int)$order['user_id'], (string)$order['sn'])) {
                continue;
            }

            $suspect++;
            $points = RechargeCreditService::points((object)$order);
            $line = sprintf(
                '疑似漏加点数: sn=%s tenant_id=%s user_id=%s points=%.2f pay_time=%s',
                $order['sn'],
                $order['tenant_id'],
                $order['user_id'],
                $points,
                $order['pay_time'] ? date('Y-m-d H:i:s', (int)$order['pay_time']) : '-'
            );

            if (!$apply) {
                $output->writeln($line);
                continue;
            }

            Db::startTrans();
            try {
                $result = RechargeCreditService::repairPaidOrder((string)$order['sn'], $force);
                Db::commit();
                if ($result['repaired']) {
                    $repaired++;
                    $output->writeln($line . ' 已修复');
                } else {
                    $output->writeln($line . ' 跳过: ' . $result['reason']);
                }
            } catch (\Throwable $e) {
                Db::rollback();
                $output->writeln($line . ' 修复失败: ' . $e->getMessage());
            }
        }

        $output->writeln(sprintf(
            '扫描订单 %d 笔，疑似异常 %d 笔，实际修复 %d 笔。%s',
            $total,
            $suspect,
            $repaired,
            $apply ? '已执行补点。' : '当前为审计模式，确认后加 --apply 执行补点。'
        ));

        return true;
    }

    private function orders(Input $input): array
    {
        $query = RechargeOrder::where('pay_status', PayEnum::ISPAID)
            ->where('refund_status', 0)
            ->order('id', 'desc');

        $sn = trim((string)$input->getOption('sn'));
        if ($sn !== '') {
            $query->where('sn', $sn);
        }

        $tenantId = (int)$input->getOption('tenant_id');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }

        $start = trim((string)$input->getOption('start'));
        if ($start !== '') {
            $query->where('pay_time', '>=', strtotime($start));
        }

        $end = trim((string)$input->getOption('end'));
        if ($end !== '') {
            $query->where('pay_time', '<=', strtotime($end));
        }

        $limit = max(1, min(1000, (int)$input->getOption('limit')));
        return $query->limit($limit)->select()->toArray();
    }
}
