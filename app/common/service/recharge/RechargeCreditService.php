<?php

namespace app\common\service\recharge;

use app\common\enum\PayEnum;
use app\common\enum\user\AccountLogEnum;
use app\common\logic\AccountLogLogic;
use app\common\model\recharge\RechargeOrder;
use app\common\model\user\User;
use app\common\model\user\UserAccountLog;
use RuntimeException;

class RechargeCreditService
{
    public static function complete(string $orderSn, array $extra = []): void
    {
        $order = RechargeOrder::where('sn', $orderSn)->lock(true)->findOrEmpty();
        if ($order->isEmpty()) {
            throw new RuntimeException('充值订单不存在');
        }

        if ((int)$order->pay_status === PayEnum::ISPAID) {
            return;
        }

        self::credit($order);

        $order->transaction_id = $extra['transaction_id'] ?? '';
        $order->pay_status = PayEnum::ISPAID;
        $order->pay_time = time();
        if (false === $order->save()) {
            throw new RuntimeException('充值订单状态更新失败');
        }
    }

    public static function repairPaidOrder(string $orderSn, bool $force = false): array
    {
        $order = RechargeOrder::where('sn', $orderSn)->lock(true)->findOrEmpty();
        if ($order->isEmpty()) {
            throw new RuntimeException('充值订单不存在');
        }
        if ((int)$order->pay_status !== PayEnum::ISPAID) {
            throw new RuntimeException('充值订单未支付');
        }
        if (!$force && self::hasRechargeLog((int)$order->user_id, (string)$order->sn)) {
            return [
                'repaired' => false,
                'reason' => '充值流水已存在',
                'points' => self::points($order),
            ];
        }

        self::credit($order, $force ? '充值到账人工补偿' : '充值到账补偿', $force);

        return [
            'repaired' => true,
            'reason' => '',
            'points' => self::points($order),
        ];
    }

    public static function hasRechargeLog(int $userId, string $orderSn): bool
    {
        if ($orderSn === '') {
            return false;
        }

        return UserAccountLog::where([
            'user_id' => $userId,
            'change_type' => AccountLogEnum::UM_INC_RECHARGE,
            'action' => AccountLogEnum::INC,
            'source_sn' => $orderSn,
        ])->findOrEmpty()->isEmpty() === false;
    }

    public static function points($order): float
    {
        $points = (float)($order->recharge_points ?? 0);
        if ($points <= 0) {
            $points = (float)$order->order_amount;
        }
        return round($points, 2);
    }

    private static function credit($order, string $remark = '用户充值', bool $allowDuplicate = false): void
    {
        if (!$allowDuplicate && self::hasRechargeLog((int)$order->user_id, (string)$order->sn)) {
            return;
        }

        $points = self::points($order);
        if ($points <= 0) {
            throw new RuntimeException('充值到账点数异常');
        }

        $user = User::where('id', $order->user_id)->lock(true)->findOrEmpty();
        if ($user->isEmpty()) {
            throw new RuntimeException('充值用户不存在');
        }
        if (!$allowDuplicate && self::hasRechargeLog((int)$order->user_id, (string)$order->sn)) {
            return;
        }

        $user->total_recharge_amount = number_format((float)$user->total_recharge_amount + $points, 2, '.', '');
        $user->user_money = number_format((float)$user->user_money + $points, 2, '.', '');
        if (false === $user->save()) {
            throw new RuntimeException('用户点数增加失败');
        }

        $log = AccountLogLogic::add(
            (int)$order->user_id,
            AccountLogEnum::UM_INC_RECHARGE,
            AccountLogEnum::INC,
            $points,
            (string)$order->sn,
            $remark
        );
        if (false === $log) {
            throw new RuntimeException('充值账户流水记录失败');
        }
    }
}
