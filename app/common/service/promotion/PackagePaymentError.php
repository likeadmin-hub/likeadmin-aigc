<?php
declare(strict_types=1);
namespace app\common\service\promotion;

/** Public hints deliberately exclude raw SDK messages, credential values and file paths. */
final class PackagePaymentError
{
    public static function message(string $error, int $channel): string
    {
        if ($channel === 2) {
            foreach ([
                'APPID_MCHID_NOT_MATCH' => '微信支付 AppID 与商户号未关联，请联系管理员核对微信商户平台配置',
                'OPENID_MISMATCH' => '当前微信身份与支付 AppID 不匹配，请重新授权登录；仍失败请联系管理员',
                'NO_AUTH' => '微信商户未开通当前支付产品，请联系管理员核对商户权限',
                'SIGN_ERROR' => '微信支付签名校验失败，请联系管理员核对商户证书与私钥',
                'PARAM_ERROR' => '微信支付参数校验失败，请联系管理员核对当前渠道配置',
                'OUT_TRADE_NO_USED' => '原支付单已存在，请先查询原订单支付结果',
            ] as $code => $hint) {
                if (preg_match('/(?<![A-Z_])'.preg_quote($code, '/').'(?![A-Z_])/', $error)) return $hint.'（'.$code.'）';
            }
            if (str_contains($error, '微信支付身份已失效')) return '当前微信支付身份已失效，请在当前小程序或公众号中重新授权登录';
            if (str_contains($error, '微信支付未配置 AppID')) return '微信支付未配置 AppID，请联系管理员完善当前渠道配置';
            if (preg_match('/certificate|private.?key|secret.?key|mch_id|mch id/i', $error)) return '微信支付商户配置不完整或证书不可用，请联系管理员核对';
        }
        return ($channel === 2 ? '微信' : '支付宝').'支付申请暂未完成，请稍后查询原订单或联系管理员核对';
    }
}
