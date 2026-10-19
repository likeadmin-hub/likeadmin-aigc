<?php
declare(strict_types=1);
namespace app\common\service\pay;

use RuntimeException;
use think\facade\Db;
use app\common\model\user\User;

/** A fresh purchase has a new identity; transport retries keep that purchase's identity. */
final class CashCheckoutService
{
    public static function number(string $from): string
    {
        $prefix = ['recharge'=>'R','membership'=>'M','tenant_power'=>'T','tenant_brand_quota'=>'Q','tenant_brand_order'=>'B'][$from] ?? null;
        if (!$prefix) throw new RuntimeException('支付订单类型不正确');
        return $prefix.bin2hex(random_bytes(15));
    }

    public static function create(int $tenant, int $user, string $from, string $key, array $payload, callable $create): array
    {
        if ($tenant <= 0 || $user <= 0) throw new RuntimeException('请先登录后购买');
        if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $key)) throw new RuntimeException('购买请求标识无效，请重新购买');
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return Db::transaction(static function () use ($tenant, $user, $from, $key, $hash, $create) {
            $person = User::withoutGlobalScope()->where(['tenant_id'=>$tenant,'id'=>$user])->lock(true)->findOrEmpty();
            if ($person->isEmpty() || !empty($person['is_disable'])) throw new RuntimeException('用户身份已变化，请重新登录');
            if ($key !== '') {
                $old = Db::name('cash_checkout')->where(['tenant_id'=>$tenant,'user_id'=>$user,'biz_type'=>$from,'request_key'=>$key])->lock(true)->find();
                if ($old) {
                    if (!hash_equals($old['request_hash'], $hash)) throw new RuntimeException('购买请求已用于其他订单，请重新购买');
                    $result = json_decode((string)$old['result_json'], true);
                    if (!is_array($result)) throw new RuntimeException('订单正在创建，请稍后重试');
                    return $result;
                }
            }
            $sn = self::number($from);
            $id = Db::name('cash_checkout')->insertGetId([
                'tenant_id'=>$tenant,'user_id'=>$user,'biz_type'=>$from,'order_sn'=>$sn,
                'request_key'=>$key !== '' ? $key : null,'request_hash'=>$hash,'create_time'=>time(),
            ]);
            $result = $create($sn);
            Db::name('cash_checkout')->where('id', $id)->update([
                'order_id'=>(int)$result['order_id'],'result_json'=>json_encode($result, JSON_THROW_ON_ERROR),
            ]);
            return $result;
        });
    }

    /** Called inside the order's settlement transaction, before any balance or entitlement mutation. */
    public static function claimReceipt(array $order, string $from, string $sn, array $extra): void
    {
        $transaction = trim((string)($extra['transaction_id'] ?? ''));
        if ($transaction === '') return; // Internal/free grants retain their existing order lock.
        if (strlen($transaction) > 128) throw new RuntimeException('支付交易编号无效');
        $channel = (int)$order['pay_way'];
        // Historical business tables could legally share an old numeric order number.
        $identity = preg_match('/^[RMTQB][a-f0-9]{30}$/D', $sn) ? $sn
            : 'L'.substr(hash('sha256', $order['tenant_id'].'|'.$from.'|'.$order['id'].'|'.$sn), 0, 30);
        $row = Db::name('cash_checkout')->where('order_sn', $identity)->lock(true)->find();
        if (!$row) {
            $id = Db::name('cash_checkout')->insertGetId([
                'tenant_id'=>$order['tenant_id'],'user_id'=>$order['user_id'],'biz_type'=>$from,
                'order_id'=>$order['id'],'order_sn'=>$identity,'create_time'=>time(),
            ]);
            $row = Db::name('cash_checkout')->where('id', $id)->lock(true)->find();
        }
        if ((int)$row['tenant_id'] !== (int)$order['tenant_id'] || (int)$row['user_id'] !== (int)$order['user_id'] || $row['biz_type'] !== $from || (int)$row['order_id'] !== (int)$order['id'])
            throw new RuntimeException('支付订单归属不匹配');
        if ($row['transaction_id'] !== null && ($row['transaction_id'] !== $transaction || (int)$row['channel'] !== $channel))
            throw new RuntimeException('订单已关联其他支付交易');
        // The database unique key also arbitrates concurrent receipts for different orders.
        Db::name('cash_checkout')->where('id', $row['id'])->update(['channel'=>$channel,'transaction_id'=>$transaction]);
    }
}
