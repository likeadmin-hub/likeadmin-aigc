<?php

namespace app\common\service\user;

use app\common\model\user\UserAccountLog;
use app\common\service\ConfigService;
use app\common\service\point\UserPointService;

class RegisterBonusService
{
    public static function grantIfEnabled(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $status = (int)ConfigService::get('login', 'register_bonus_status', 0);
        $points = (float)ConfigService::get('login', 'register_bonus_points', 0);
        if ($status !== 1 || $points <= 0) {
            return;
        }
        $sourceSn = self::sourceSn($userId);
        $exists = UserAccountLog::where('user_id', $userId)
            ->where('source_sn', $sourceSn)
            ->findOrEmpty();
        if (!$exists->isEmpty()) {
            return;
        }
        UserPointService::grantRegisterBonus(
            $userId,
            $points,
            $sourceSn,
            '新用户注册赠送' . \app\common\service\PointUnitService::unit(),
            ['scene' => 'register_bonus']
        );
    }

    private static function sourceSn(int $userId): string
    {
        return 'register_bonus_' . $userId;
    }
}
