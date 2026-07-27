<?php

namespace app\api\controller;

use app\common\service\distribution\DistributionService;
use Exception;

class DistributionController extends BaseApiController
{
    public function center()
    {
        try {
            $tenantId = (int)$this->request->tenantId;
            $overview = DistributionService::userOverview($tenantId, $this->userId);
            $relation = $overview['relation'];
            $account = $overview['dashboard']['account'];
            return $this->success('获取成功', [
                'invite_code' => (string)$relation['invite_code'],
                'invite_url' => self::inviteUrl($tenantId, (string)$relation['invite_code']),
                'team_counts' => $overview['team'],
                'account' => [
                    'pending_amount' => (string)($account['pending_amount'] ?? '0.00'),
                    'available_amount' => (string)($account['available_amount'] ?? '0.00'),
                    'frozen_amount' => (string)($account['frozen_amount'] ?? '0.00'),
                    'total_amount' => (string)($account['total_income'] ?? '0.00'),
                    'withdrawn_amount' => (string)($account['total_withdrawn'] ?? '0.00'),
                    'negative_amount' => (string)($account['negative_amount'] ?? '0.00'),
                ],
                'withdrawal_records' => DistributionService::withdrawalRows($tenantId, $this->userId, false, 1, 10)['lists'],
                'config' => $overview['config'],
            ]);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function relations()
    {
        try {
            $page = (int)$this->request->get('page_no', $this->request->get('page', 1));
            $size = (int)$this->request->get('page_size', $this->request->get('size', 20));
            $tenantId = (int)$this->request->tenantId;
            $query = \think\facade\Db::name('distribution_relation')->where('tenant_id', $tenantId);
            $query->where(function ($query) {
                $query->where('level1_user_id', $this->userId)->whereOr('level2_user_id', $this->userId)->whereOr('level3_user_id', $this->userId);
            });
            $count = (clone $query)->count();
            return $this->success('获取成功', ['count' => $count, 'lists' => $query->order('id desc')->page(max(1, $page), max(1, min(100, $size)))->select()->toArray()]);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function commissions()
    {
        try {
            return $this->success('获取成功', DistributionService::commissionRows(
                (int)$this->request->tenantId,
                $this->request->get(),
                (int)$this->request->get('page_no', $this->request->get('page', 1)),
                (int)$this->request->get('page_size', $this->request->get('size', 20)),
                $this->userId
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function withdrawAccounts()
    {
        try {
            return $this->success('获取成功', ['lists' => DistributionService::withdrawAccounts((int)$this->request->tenantId, $this->userId, true)]);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function saveWithdrawAccount()
    {
        try {
            return $this->success('保存成功', DistributionService::saveWithdrawAccount((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function applyWithdrawal()
    {
        try {
            return $this->success('申请成功', DistributionService::applyWithdrawal(
                (int)$this->request->tenantId,
                $this->userId,
                (int)$this->request->post('withdraw_account_id', 0),
                (float)$this->request->post('amount', 0)
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function withdrawalRecords()
    {
        try {
            return $this->success('获取成功', DistributionService::withdrawalRows(
                (int)$this->request->tenantId, $this->userId, false,
                (int)$this->request->get('page_no', $this->request->get('page', 1)),
                (int)$this->request->get('page_size', $this->request->get('size', 20))
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    private static function inviteUrl(int $tenantId, string $inviteCode): string
    {
        return rtrim((string)request()->domain(), '/') . '/?tenant_id=' . $tenantId . '&invite_code=' . rawurlencode($inviteCode);
    }
}
