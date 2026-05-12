<?php

namespace app\common\service\recharge;

use app\common\model\recharge\RechargePackage;
use RuntimeException;
use think\facade\Db;

class RechargePackageService
{
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 0;

    public static function defaultPackages(int $tenantId): void
    {
        $defaults = [
            ['name' => '体验包', 'points' => 10, 'amount' => 10, 'market_amount' => 0, 'sort' => 100],
            ['name' => '轻量包', 'points' => 30, 'amount' => 30, 'market_amount' => 0, 'sort' => 90],
            ['name' => '标准包', 'points' => 50, 'amount' => 50, 'market_amount' => 0, 'sort' => 80],
            ['name' => '进阶包', 'points' => 100, 'amount' => 100, 'market_amount' => 0, 'sort' => 70],
            ['name' => '专业包', 'points' => 300, 'amount' => 300, 'market_amount' => 0, 'sort' => 60],
            ['name' => '团队包', 'points' => 500, 'amount' => 500, 'market_amount' => 0, 'sort' => 50],
        ];

        foreach ($defaults as $item) {
            $exists = RechargePackage::where(['tenant_id' => $tenantId, 'name' => $item['name']])->count();
            if ($exists) {
                continue;
            }
            RechargePackage::create([
                'tenant_id' => $tenantId,
                'name' => $item['name'],
                'points' => self::formatAmount($item['points']),
                'amount' => self::formatAmount($item['amount']),
                'market_amount' => self::formatAmount($item['market_amount']),
                'is_recommend' => $item['points'] === 100 ? 1 : 0,
                'status' => self::STATUS_ENABLED,
                'sort' => $item['sort'],
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    public static function enabledPackages(int $tenantId, float $minAmount = 0): array
    {
        $query = RechargePackage::where(['tenant_id' => $tenantId, 'status' => self::STATUS_ENABLED])
            ->order(['sort' => 'desc', 'id' => 'asc']);
        if ($minAmount > 0) {
            $query->where('points', '>=', $minAmount);
        }
        return $query->select()->toArray();
    }

    public static function detail(int $tenantId, int $id): array
    {
        $package = RechargePackage::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
        return $package->isEmpty() ? [] : $package->toArray();
    }

    public static function save(int $tenantId, array $params): RechargePackage
    {
        $name = trim((string)($params['name'] ?? ''));
        $points = (float)($params['points'] ?? 0);
        $amount = (float)($params['amount'] ?? 0);
        if ($name === '') {
            throw new RuntimeException('请输入套餐名称');
        }
        if ($points <= 0) {
            throw new RuntimeException('套餐点数必须大于0');
        }
        if ($amount < 0) {
            throw new RuntimeException('套餐售价不能小于0');
        }

        $data = [
            'tenant_id' => $tenantId,
            'name' => $name,
            'points' => self::formatAmount($points),
            'amount' => self::formatAmount($amount),
            'market_amount' => self::formatAmount((float)($params['market_amount'] ?? 0)),
            'is_recommend' => (int)($params['is_recommend'] ?? 0) ? 1 : 0,
            'status' => (int)($params['status'] ?? 1) ? self::STATUS_ENABLED : self::STATUS_DISABLED,
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];

        return Db::transaction(function () use ($tenantId, $params, $data) {
            $id = (int)($params['id'] ?? 0);
            if ($id > 0) {
                $package = RechargePackage::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
                if ($package->isEmpty()) {
                    throw new RuntimeException('算力套餐不存在');
                }
                $package->save($data);
                return $package;
            }
            $data['create_time'] = time();
            return RechargePackage::create($data);
        });
    }

    public static function delete(int $tenantId, int $id): void
    {
        $package = RechargePackage::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
        if ($package->isEmpty()) {
            throw new RuntimeException('算力套餐不存在');
        }
        $package->delete();
    }

    public static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
