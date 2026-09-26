<?php

namespace app\api\service;

use app\common\enum\user\UserTerminalEnum;
use app\common\model\user\User;
use app\common\model\user\UserAuth;

class WechatAccountService
{
    /**
     * 手机号已验证后，允许将微信生成且未设置独立登录凭证的账号统一到手机号账号。
     * is_new_user 仅表示是否完成头像昵称填写，不能用于判断微信身份是否可合并。
     */
    public static function isMergeableShadow(User $user): bool
    {
        if ($user->isEmpty()
            || !empty($user->mobile)
            || !empty($user->password)
            || !empty($user->is_disable)
            || !str_starts_with((string)$user->account, 'u')) {
            return false;
        }

        // 不能仅凭自动账号前缀判断；必须确实持有当前租户内的微信身份。
        return !UserAuth::where('user_id', $user->id)
            ->whereIn('terminal', [UserTerminalEnum::WECHAT_MMP, UserTerminalEnum::WECHAT_OA, UserTerminalEnum::PC])
            ->where('openid', '<>', '')
            ->findOrEmpty()->isEmpty();
    }
}
