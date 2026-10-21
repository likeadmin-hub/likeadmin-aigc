<?php
namespace app\tenantapi\logic\finance;
use app\common\service\redeem\RedeemCardService;
final class RedeemCardLogic
{
    public static function generate(int $tenant, int $admin, array $params): array { return RedeemCardService::generate($tenant,$admin,$params); }
    public static function lists(int $tenant, string $kind, array $params): array { return RedeemCardService::listing($tenant,$kind,$params); }
    public static function disable(int $tenant, array $params): void { RedeemCardService::disable($tenant,$params); }
}
