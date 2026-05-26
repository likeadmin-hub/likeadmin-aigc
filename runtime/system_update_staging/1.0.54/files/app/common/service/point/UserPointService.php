<?php

namespace app\common\service\point;

use app\common\enum\user\AccountLogEnum;
use app\common\logic\AccountLogLogic;
use app\common\model\user\UserAccountLog;
use app\common\model\user\User;
use RuntimeException;

class UserPointService
{
    public static function assertEnough(int $userId, float $points): void
    {
        if ($points <= 0) {
            throw new RuntimeException('点数必须大于0');
        }
        $user = User::where('id', $userId)->findOrEmpty();
        if ($user->isEmpty()) {
            throw new RuntimeException('用户不存在');
        }
        if ((float)$user['user_money'] < $points) {
            throw new RuntimeException('点数不足，请充值');
        }
    }

    public static function consume(int $userId, float $points, string $sourceSn, string $remark = '', array $extra = []): void
    {
        if ($points <= 0) {
            throw new RuntimeException('点数必须大于0');
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
            throw new RuntimeException('点数不足，请充值');
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
            throw new RuntimeException('点数必须大于0');
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
            throw new RuntimeException('点数必须大于0');
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
