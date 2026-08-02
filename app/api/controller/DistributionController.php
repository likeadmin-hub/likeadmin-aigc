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
            $level = (int)$this->request->get('level', 0);
            $query = \think\facade\Db::name('distribution_relation')->alias('r')
                ->leftJoin('user u', 'u.id = r.user_id AND u.tenant_id = r.tenant_id')
                ->where('r.tenant_id', $tenantId)
                ->field('r.*,u.nickname,u.account,u.mobile,u.avatar,u.create_time as user_create_time');
            if (in_array($level, [1, 2], true)) {
                $query->where('r.level' . $level . '_user_id', $this->userId);
            } else {
                $query->where(function ($query) {
                    $query->where('r.level1_user_id', $this->userId)->whereOr('r.level2_user_id', $this->userId);
                });
            }
            $keyword = trim((string)$this->request->get('keyword', ''));
            if ($keyword !== '') {
                $query->whereLike('u.nickname|u.account|u.mobile', '%' . $keyword . '%');
            }
            $startTime = strtotime((string)$this->request->get('start_time', ''));
            $endTime = strtotime((string)$this->request->get('end_time', ''));
            if ($startTime !== false) $query->where('r.create_time', '>=', $startTime);
            if ($endTime !== false) $query->where('r.create_time', '<=', $endTime);
            $count = (clone $query)->count();
            return $this->success('获取成功', ['count' => $count, 'lists' => $query->order('r.id desc')->page(max(1, $page), max(1, min(100, $size)))->select()->toArray()]);
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
                (int)$this->request->get('page_size', $this->request->get('size', 20)),
                $this->request->get()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    private static function inviteUrl(int $tenantId, string $inviteCode): string
    {
        return rtrim((string)request()->domain(), '/') . '/?invite_code=' . rawurlencode($inviteCode);
    }
}
