<?php

namespace app\common\service\pay;

use app\common\enum\PayEnum;
use app\common\enum\user\UserTerminalEnum;
use app\common\logic\PayNotifyLogic;
use app\common\model\membership\MembershipOrder;
use RuntimeException;

/**
 * Reconcile membership orders when a payment callback is delayed or missed.
 */
class MembershipPaymentReconcileService
{
    public static function inspect(MembershipOrder $order): array
    {
        if ((int)$order['pay_status'] === PayEnum::ISPAID) {
            return ['paid' => true, 'transaction_id' => (string)$order['transaction_id']];
        }

        return match ((int)$order['pay_way']) {
            PayEnum::WECHAT_PAY => self::inspectWechat($order->toArray()),
            PayEnum::ALI_PAY => self::inspectAlipay($order->toArray()),
            default => ['paid' => false, 'transaction_id' => ''],
        };
    }

    public static function reconcile(MembershipOrder $order): bool
    {
        if ((int)$order['pay_status'] === PayEnum::ISPAID) {
            return true;
        }

        $payment = self::inspect($order);
        if (!$payment['paid']) {
            return false;
        }

        $result = PayNotifyLogic::handle('membership', (string)$order['order_sn'], [
            'transaction_id' => (string)$payment['transaction_id'],
        ]);
        if ($result !== true) {
            throw new RuntimeException((string)$result);
        }

        return true;
    }

    private static function inspectWechat(array $order): array
    {
        $paySn = trim((string)($order['pay_sn'] ?? '')) ?: (string)$order['order_sn'];
        $terminal = (int)($order['order_terminal'] ?? 0) ?: UserTerminalEnum::PC;
        $response = (new WeChatPayService($terminal, null, false))->checkPay($paySn);
        if (($response['trade_state'] ?? '') !== 'SUCCESS') {
            return ['paid' => false, 'transaction_id' => ''];
        }

        self::assertSameOrder($paySn, (string)($response['out_trade_no'] ?? ''));
        self::assertSameAmount((float)$order['order_amount'], ((float)($response['amount']['total'] ?? -1)) / 100);
        $transactionId = trim((string)($response['transaction_id'] ?? ''));
        if ($transactionId === '') {
            throw new RuntimeException('微信支付交易号为空');
        }

        return ['paid' => true, 'transaction_id' => $transactionId];
    }

    private static function inspectAlipay(array $order): array
    {
        $response = (new AliPayService((int)($order['order_terminal'] ?? UserTerminalEnum::PC)))
            ->checkPay((string)$order['order_sn']);
        if ((string)($response->code ?? '') !== '10000'
            || !in_array((string)($response->tradeStatus ?? ''), ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return ['paid' => false, 'transaction_id' => ''];
        }

        self::assertSameOrder((string)$order['order_sn'], (string)($response->outTradeNo ?? ''));
        self::assertSameAmount((float)$order['order_amount'], (float)($response->totalAmount ?? -1));
        $transactionId = trim((string)($response->tradeNo ?? ''));
        if ($transactionId === '') {
            throw new RuntimeException('支付宝交易号为空');
        }

        return ['paid' => true, 'transaction_id' => $transactionId];
    }

    private static function assertSameOrder(string $expected, string $actual): void
    {
        if ($actual === '' || !hash_equals($expected, $actual)) {
            throw new RuntimeException('支付平台返回的商户订单号不匹配');
        }
    }

    private static function assertSameAmount(float $expected, float $actual): void
    {
        if ((int)round($expected * 100) !== (int)round($actual * 100)) {
            throw new RuntimeException('支付平台返回的订单金额不匹配');
        }
    }
}
