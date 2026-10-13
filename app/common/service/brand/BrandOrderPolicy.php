<?php
declare(strict_types=1);
namespace app\common\service\brand;

use InvalidArgumentException;

/** Pure policy shared by authenticated workflow and presentation boundaries. */
final class BrandOrderPolicy
{
    public static function error(\Throwable $error): string
    {
        return $error instanceof BrandUserException || $error instanceof InvalidArgumentException
            ? $error->getMessage() : '操作暂未完成，请稍后重试或联系服务商核对订单。';
    }
    public static function cents(string|int $amount): int
    {
        $value = (string)$amount;
        if (!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $value, $matches)) {
            throw new InvalidArgumentException('请输入有效金额，最多两位小数');
        }
        return ((int)$matches[1]) * 100 + (int)str_pad($matches[2] ?? '', 2, '0');
    }

    public static function amount(int $cents): string
    {
        if ($cents < 0 || $cents > 999999999999) throw new InvalidArgumentException('金额超出允许范围');
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function renewBase(int $expire, int $delivery): int
    {
        return max($expire, $delivery);
    }

    public static function state(array $order): string
    {
        return [
            'awaiting_payment'=>'待支付','tenant_review'=>'待租户审核','tenant_info'=>'待补充资料',
            'awaiting_quota'=>'待采购额度','ready_submit'=>'待提交平台','platform_review'=>'待平台审核',
            'platform_info'=>'平台退回待处理','opening'=>'开通中','open_failed'=>'开通异常待处理',
            'delivered'=>'已交付','refund_review'=>'退款审核中','refunding'=>'退款处理中',
            'refund_failed'=>'退款异常待处理','refunded'=>'已退款','closed'=>'已关闭',
        ][$order['workflow_state'] ?? ''] ?? '处理中';
    }

    public static function actions(array $order, string $role): array
    {
        $state = $order['workflow_state'] ?? '';
        if ($role === 'user') return match ($state) {
            'awaiting_payment'=>['pay','cancel'], 'tenant_info','platform_info'=>['supplement','request_refund'],
            'tenant_review','awaiting_quota','ready_submit','platform_review','opening','open_failed'=>['request_refund'],
            'delivered'=>['renew','domain'], default=>[],
        };
        if ($role === 'tenant') return match ($state) {
            'tenant_review'=>['approve','needs_info','reject'], 'tenant_info'=>['approve','reject'],
            'awaiting_quota'=>['purchase_quota','reserve'], 'ready_submit'=>['submit'],
            'platform_info'=>['needs_info','submit','reject'], 'refund_review'=>['approve_refund','deny_refund'], default=>[],
        };
        if ($role === 'platform') return match ($state) {
            'platform_review'=>['confirm','return','reject'], 'open_failed'=>['retry','approve_refund'],
            'refund_review'=>['approve_refund','deny_refund'], 'refund_failed'=>['retry_refund'], default=>[],
        };
        return [];
    }

    public static function present(array $order, string $role): array
    {
        unset($order['admin_password_hash'], $order['open_error']);
        $snapshot = json_decode((string)($order['package_snapshot'] ?? ''), true) ?: [];
        $brand = json_decode((string)($order['brand_snapshot'] ?? ''), true) ?: [];
        unset($brand['logo_asset']);
        $order['order_status'] = self::state($order);
        $order['next_actions'] = self::actions($order, $role);
        $order['brand'] = $brand;
        $order['entitlements'] = array_map(fn($plan) => [
            'app_code'=>$plan['app_code'] ?? '', 'app_name'=>$plan['app_name'] ?? '',
            'plan_name'=>$plan['name'] ?? $plan['app_plan_name'] ?? '', 'duration_months'=>(int)($plan['duration_months'] ?? 0),
        ], $snapshot['app_plans'] ?? []);
        $order['duration_days'] = (int)($snapshot['duration_days'] ?? 0);
        $order['processing_advice'] = match ($order['workflow_state'] ?? '') {
            'awaiting_quota'=>'付款已确认，服务商采购额度后继续办理，请勿重复付款。',
            'open_failed'=>'开通暂未完成，平台正在处理，您的付款和额度记录已保留。',
            'refund_failed'=>'退款需要人工核对，请联系服务商，勿重复付款。',
            'opening'=>'平台正在为您开通，请稍后查看进度。', default=>$order['order_status'],
        };
        if ((int)($order['child_tenant_id'] ?? 0) > 0 && ($order['workflow_state'] ?? '') === 'delivered') {
            $order['login_path'] = '/t/' . (int)$order['child_tenant_id'] . '/admin/login';
            $order['account_settings_path'] = '/t/' . (int)$order['child_tenant_id'] . '/admin/user/setting';
        }
        if ($role === 'user') {
            $allowed = ['id','order_sn','order_kind','package_id','package_name','unit_price','order_amount','pay_status',
                'pay_way','pay_time','workflow_state','flow_version','tenant_review','platform_review','open_status',
                'refund_status','reserve_status','reserve_expire_time','closed_time','delivered_time','service_expire_time',
                'child_tenant_id','target_tenant_id','child_tenant_name','admin_account','review_note','create_time',
                'order_status','next_actions','brand','entitlements','duration_days','processing_advice','login_path','account_settings_path'];
            $order = array_intersect_key($order, array_flip($allowed));
        }
        return $order;
    }
}
