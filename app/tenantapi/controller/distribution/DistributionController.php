<?php

namespace app\tenantapi\controller\distribution;

use app\common\service\distribution\DistributionService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class DistributionController extends BaseAdminController
{
    public function overview()
    {
        return $this->success('获取成功', DistributionService::dashboard($this->tenantId));
    }

    public function config()
    {
        return $this->success('获取成功', DistributionService::config($this->tenantId));
    }

    public function saveConfig()
    {
        try { return $this->success('保存成功', DistributionService::saveConfig($this->tenantId, $this->request->post())); }
        catch (Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function packageRule()
    {
        return $this->success('获取成功', DistributionService::packageRule($this->tenantId, (string)$this->request->get('order_type', ''), (int)$this->request->get('package_id', 0)));
    }

    /** All configured SKU rules. Packages without a row inherit the global rule. */
    public function packageRules()
    {
        $type = (string)$this->request->get('order_type', '');
        $rows = DistributionService::packageRulesForTenant($this->tenantId, $type);
        $result = ['recharge' => [], 'member' => [], 'lists' => $rows];
        foreach ($rows as $row) {
            $rule = (array)($row['rule'] ?? []);
            $item = array_merge($row, $rule);
            unset($item['rule']);
            $key = ($row['order_type'] ?? '') === DistributionService::ORDER_MEMBERSHIP ? 'member' : 'recharge';
            $result[$key][] = $item;
        }
        return $this->success('获取成功', $result);
    }

    public function savePackageRule()
    {
        try {
            $data = $this->request->post();
            return $this->success('保存成功', DistributionService::savePackageRule($this->tenantId, (string)($data['order_type'] ?? ''), (int)($data['package_id'] ?? 0), $data));
        } catch (Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function commissions()
    {
        return $this->success('获取成功', DistributionService::commissionRows($this->tenantId, $this->request->get(), (int)$this->request->get('page_no', 1), (int)$this->request->get('page_size', 20)));
    }

    /** Alias retained for the 分销订单 menu. Every row carries the immutable order commission snapshot. */
    public function orders()
    {
        return $this->commissions();
    }

    public function promoters()
    {
        $page = (int)$this->request->get('page_no', $this->request->get('page', 1));
        $size = (int)$this->request->get('page_size', $this->request->get('size', 20));
        $query = \think\facade\Db::name('distribution_relation')->alias('r')
            ->leftJoin('user u', 'u.id = r.user_id')
            ->leftJoin('distribution_account a', 'a.tenant_id = r.tenant_id AND a.user_id = r.user_id')
            ->where('r.tenant_id', $this->tenantId)
            ->field('r.*,u.nickname,u.account,u.mobile,u.avatar,u.create_time as user_create_time,a.pending_amount,a.available_amount,a.frozen_amount,a.negative_amount,a.total_income,a.total_withdrawn');
        $keyword = trim((string)$this->request->get('keyword', $this->request->get('user_info', '')));
        if ($keyword !== '') {
            $query->whereLike('u.nickname|u.account|u.mobile|r.invite_code', '%' . $keyword . '%');
        }
        $status = $this->request->get('status', '');
        if ($status !== '') {
            $query->where('r.is_frozen', (int)$status === 1 ? 0 : 1);
        }
        $count = (clone $query)->count();
        $rows = $query->order('r.id desc')->page(max(1, $page), max(1, min(100, $size)))->select()->toArray();
        $userIds = array_values(array_filter(array_unique(array_map('intval', array_column($rows, 'user_id')))));
        $teamCounts = [1 => [], 2 => [], 3 => []];
        if (!empty($userIds)) {
            foreach ([1, 2, 3] as $level) {
                $field = 'level' . $level . '_user_id';
                $teamCounts[$level] = \think\facade\Db::name('distribution_relation')
                    ->where('tenant_id', $this->tenantId)
                    ->whereIn($field, $userIds)
                    ->group($field)
                    ->column('COUNT(*)', $field);
            }
        }
        foreach ($rows as &$row) {
            $userId = (int)$row['user_id'];
            $row['level1_count'] = (int)($teamCounts[1][$userId] ?? 0);
            $row['level2_count'] = (int)($teamCounts[2][$userId] ?? 0);
            $row['level3_count'] = (int)($teamCounts[3][$userId] ?? 0);
        }
        unset($row);
        return $this->success('获取成功', ['count' => $count, 'lists' => $rows]);
    }

    public function relations()
    {
        $page = (int)$this->request->get('page_no', $this->request->get('page', 1));
        $size = (int)$this->request->get('page_size', $this->request->get('size', 20));
        $query = \think\facade\Db::name('distribution_relation')->alias('r')
            ->leftJoin('user u', 'u.id = r.user_id')
            ->leftJoin('user p1', 'p1.id = r.level1_user_id')
            ->leftJoin('user p2', 'p2.id = r.level2_user_id')
            ->leftJoin('user p3', 'p3.id = r.level3_user_id')
            ->where('r.tenant_id', $this->tenantId)
            ->field('r.*,u.nickname,u.account,u.mobile,u.avatar,p1.nickname as level1_name,p2.nickname as level2_name,p3.nickname as level3_name');
        $keyword = trim((string)$this->request->get('user_info', $this->request->get('keyword', '')));
        if ($keyword !== '') {
            $query->whereLike('u.nickname|u.account|u.mobile|r.invite_code', '%' . $keyword . '%');
        }
        $userId = (int)$this->request->get('user_id', 0);
        if ($userId > 0) {
            $query->where(function ($q) use ($userId) {
                $q->where('r.user_id', $userId)->whereOr('r.level1_user_id', $userId)->whereOr('r.level2_user_id', $userId)->whereOr('r.level3_user_id', $userId);
            });
        }
        $level = (int)$this->request->get('level', 0);
        if ($level >= 1 && $level <= 3) {
            $query->where('r.level' . $level . '_user_id', '>', 0);
        }
        $count = (clone $query)->count();
        return $this->success('获取成功', ['count' => $count, 'lists' => $query->order('r.id desc')->page(max(1, $page), max(1, min(100, $size)))->select()->toArray()]);
    }

    public function withdrawals()
    {
        return $this->success('获取成功', DistributionService::withdrawalRows($this->tenantId, 0, false, (int)$this->request->get('page_no', 1), (int)$this->request->get('page_size', 20), $this->request->get()));
    }

    /** Full collection details are deliberately a separate endpoint for a distinct permission. */
    public function withdrawalDetail()
    {
        $id = (int)$this->request->get('id', 0);
        $row = \think\facade\Db::name('distribution_withdrawal')->where(['tenant_id' => $this->tenantId, 'id' => $id])->find();
        if (!$row) { return $this->fail('提现申请不存在'); }
        $row['withdraw_snapshot'] = json_decode((string)$row['withdraw_snapshot'], true) ?: [];
        return $this->success('获取成功', $row);
    }

    public function reviewWithdrawal()
    {
        try {
            $data = $this->request->post();
            return $this->success('操作成功', DistributionService::reviewWithdrawal(
                $this->tenantId, (int)($data['id'] ?? $data['withdrawal_id'] ?? 0), $this->adminId, (string)($data['action'] ?? ''),
                (string)($data['reason'] ?? $data['remark'] ?? ''), ['payment_sn' => $data['payment_sn'] ?? '', 'payment_proof' => $data['payment_proof'] ?? '']
            ));
        } catch (Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Alias used by the tenant UI action endpoint. */
    public function review()
    {
        return $this->reviewWithdrawal();
    }

    public function adjustRelation()
    {
        try {
            $data = $this->request->post();
            $userId = DistributionService::resolveUserId($this->tenantId, $data['user_id'] ?? $data['user_info'] ?? 0);
            $parentUserId = DistributionService::resolveUserId($this->tenantId, $data['parent_user_id'] ?? $data['parent_user_info'] ?? 0);
            return $this->success('调整成功', DistributionService::adjustRelation($this->tenantId, $userId, $parentUserId, $this->adminId, (string)($data['reason'] ?? $data['remark'] ?? '')));
        } catch (Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function freezePromoter()
    {
        try {
            $userId = (int)$this->request->post('user_id', 0);
            DistributionService::ensurePromoter($this->tenantId, $userId);
            \think\facade\Db::name('distribution_relation')->where(['tenant_id' => $this->tenantId, 'user_id' => $userId])->update(['is_frozen' => (int)$this->request->post('is_frozen', 1) ? 1 : 0, 'update_time' => time()]);
            return $this->success('操作成功');
        } catch (Exception $e) { return $this->fail($e->getMessage()); }
    }
}
