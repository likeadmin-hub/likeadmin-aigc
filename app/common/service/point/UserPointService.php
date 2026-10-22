<?php

namespace app\common\service\point;

use app\common\enum\user\AccountLogEnum;
use app\common\logic\AccountLogLogic;
use app\common\model\user\UserAccountLog;
use app\common\model\user\User;
use app\common\service\PointUnitService;
use RuntimeException;

class UserPointService
{
    public static function assertEnough(int $userId, float $points): void
    {
        if ($points <= 0) {
            throw new RuntimeException(PointUnitService::unit() . '必须大于0');
        }
        $user = User::where('id', $userId)->findOrEmpty();
        if ($user->isEmpty()) {
            throw new RuntimeException('用户不存在');
        }
        if ((float)$user['user_money'] < $points) {
            throw new RuntimeException(PointUnitService::unit() . '不足，请充值');
        }
    }

    public static function consume(int $userId, float $points, string $sourceSn, string $remark = '', array $extra = []): void
    {
        if ($points <= 0) {
            throw new RuntimeException(PointUnitService::unit() . '必须大于0');
        }
        if (self::hasLog($userId, AccountLogEnum::UM_DEC_APP_CONSUME, AccountLogEnum::DEC, $sourceSn, $remark)) {
            return;
        }
        $user = User::where('id', $userId)->lock(true)->findOrEmpty();
        if ($user->isEmpty()) {
            throw new RuntimeException('用户不存在');
        }
        if (self::hasLog($userId, AccountLogEnum::UM_DEC_APP_CONSUME, AccountLogEnum::DEC, $sourceSn, $remark)) {
            return;
        }
        if ((float)$user['user_money'] < $points) {
            throw new RuntimeException(PointUnitService::unit() . '不足，请充值');
        }
        $user->user_money = number_format((float)$user['user_money'] - $points, 2, '.', '');
        $user->save();
        AccountLogLogic::add(
            $userId,
            AccountLogEnum::UM_DEC_APP_CONSUME,
            AccountLogEnum::DEC,
            $points,
            $sourceSn,
            $remark,
            $extra
        );
    }

    public static function refund(int $userId, float $points, string $sourceSn, string $remark = '', array $extra = []): void
    {
        if ($points <= 0) {
            throw new RuntimeException(PointUnitService::unit() . '必须大于0');
        }
        if (self::hasLog($userId, AccountLogEnum::UM_INC_APP_REFUND, AccountLogEnum::INC, $sourceSn, $remark)) {
            return;
        }
        $user = User::where('id', $userId)->lock(true)->findOrEmpty();
        if ($user->isEmpty()) {
            throw new RuntimeException('用户不存在');
        }
        if (self::hasLog($userId, AccountLogEnum::UM_INC_APP_REFUND, AccountLogEnum::INC, $sourceSn, $remark)) {
            return;
        }
        $user->user_money = number_format((float)$user['user_money'] + $points, 2, '.', '');
        $user->save();
        AccountLogLogic::add(
            $userId,
            AccountLogEnum::UM_INC_APP_REFUND,
            AccountLogEnum::INC,
            $points,
            $sourceSn,
            $remark,
            $extra
        );
    }

    public static function grantRegisterBonus(int $userId, float $points, string $sourceSn, string $remark = '', array $extra = []): void
    {
        if ($points <= 0) {
            throw new RuntimeException(PointUnitService::unit() . '必须大于0');
        }
        $user = User::where('id', $userId)->lock(true)->findOrEmpty();
        if ($user->isEmpty()) {
            throw new RuntimeException('用户不存在');
        }
        $user->user_money = number_format((float)$user['user_money'] + $points, 2, '.', '');
        $user->save();
        AccountLogLogic::add(
            $userId,
            AccountLogEnum::UM_INC_REGISTER_BONUS,
            AccountLogEnum::INC,
            $points,
            $sourceSn,
            $remark,
            $extra
        );
    }

    /**
     * 发放会员套餐赠送点数。
     *
     * 通过来源号幂等，避免后台请求重试导致重复到账。
     */
    public static function grantMembershipBonus(int $userId, float $points, string $sourceSn, string $remark = '', array $extra = []): void
    {
        if ($points <= 0) {
            return;
        }
        if (self::hasLog($userId, AccountLogEnum::UM_INC_MEMBERSHIP_BONUS, AccountLogEnum::INC, $sourceSn, $remark)) {
            return;
        }
        $user = User::where('id', $userId)->lock(true)->findOrEmpty();
        if ($user->isEmpty()) {
            throw new RuntimeException('用户不存在');
        }
        if (self::hasLog($userId, AccountLogEnum::UM_INC_MEMBERSHIP_BONUS, AccountLogEnum::INC, $sourceSn, $remark)) {
            return;
        }
        $user->user_money = number_format((float)$user['user_money'] + $points, 2, '.', '');
        if (false === $user->save()) throw new RuntimeException('会员赠点入账失败');
        $log = AccountLogLogic::add(
            $userId,
            AccountLogEnum::UM_INC_MEMBERSHIP_BONUS,
            AccountLogEnum::INC,
            $points,
            $sourceSn,
            $remark,
            $extra
        );
        if (false === $log) throw new RuntimeException('会员赠点流水记录失败');
    }

    /** Card issuance is tenant credit, not a paid recharge/refund or provider expense. */
    public static function grantRedeemCard(int $tenantId, int $userId, float $points, string $sourceSn, array $extra = []): void
    {
        if ($points <= 0 || $sourceSn === '') throw new RuntimeException('卡密点数或来源无效');
        $user = User::where(['tenant_id' => $tenantId, 'id' => $userId])->lock(true)->findOrEmpty();
        if ($user->isEmpty()) throw new RuntimeException('用户不存在');
        $exists = UserAccountLog::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'change_type' => AccountLogEnum::UM_INC_REDEEM_CARD, 'source_sn' => $sourceSn])->findOrEmpty();
        if (!$exists->isEmpty()) return;
        $user->user_money = number_format((float)$user['user_money'] + $points, 2, '.', '');
        if (false === $user->save()) throw new RuntimeException('卡密点数入账失败');
        if (false === AccountLogLogic::add($userId, AccountLogEnum::UM_INC_REDEEM_CARD, AccountLogEnum::INC, $points, $sourceSn, '卡密兑换', $extra)) {
            throw new RuntimeException('卡密点数流水记录失败');
        }
    }

    private static function hasLog(int $userId, int $changeType, int $action, string $sourceSn, string $remark = ''): bool
    {
        if ($sourceSn === '') {
            return false;
        }
        return UserAccountLog::where([
            'user_id' => $userId,
            'change_type' => $changeType,
            'action' => $action,
            'source_sn' => $sourceSn,
            'remark' => $remark,
        ])->findOrEmpty()->isEmpty() === false;
    }
}
