<?php

namespace app\common\service\user;

use app\common\model\user\User;

class UserAccountService
{
    public const DUPLICATE_MESSAGE = '账号已存在';

    public static function exists(string $account): bool
    {
        // Keep BaseModel's tenant table routing and reserve deleted accounts too.
        return User::withTrashed()->where('tenant_id', (int)request()->tenantId)->where('account', $account)->count() > 0;
    }

    public static function isDuplicateAccount(\Throwable $error): bool
    {
        // Do not hide unrelated unique failures (sn, grants, invitation ledgers).
        return preg_match('/\b1062\b.*\bfor key [\x27\x22`](?:[^\x27\x22`]+\.)?(?:uk_tenant_account|account)[\x27\x22`]/i', $error->getMessage()) === 1;
    }
}
