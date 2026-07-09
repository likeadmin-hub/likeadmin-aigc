<?php

namespace app\tenantapi\lists\power;

use app\common\enum\user\AccountLogEnum;
use app\common\lists\ListsExtendInterface;
use app\common\lists\ListsSearchInterface;
use app\common\model\app\App;
use app\common\model\tenant\Tenant;
use app\common\model\tenant\TenantPointLog;
use app\common\service\point\TenantPointService;
use app\common\service\PointUnitService;
use app\tenantapi\lists\BaseAdminDataLists;

class TenantPowerConsumeLogLists extends BaseAdminDataLists implements ListsSearchInterface, ListsExtendInterface
{
    public function setSearch(): array
    {
        return [];
    }

    public function lists(): array
    {
        $lists = $this->baseQuery()
            ->field(self::fields())
            ->group('tpl.id')
            ->order('tpl.id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        return self::formatRows($lists);
    }

    public function count(): int
    {
        return (int)$this->baseQuery()->distinct(true)->count('tpl.id');
    }

    public function extend(): array
    {
        $tenantId = (int)$this->adminInfo['tenant_id'];
        $todayStart = strtotime(date('Y-m-d'));
        $summaryQuery = TenantPointLog::where([
            'tenant_id' => $tenantId,
            'change_type' => TenantPointService::TYPE_CONSUME,
            'action' => TenantPointService::ACTION_DEC,
        ]);
        $todayQuery = (clone $summaryQuery)->where('create_time', '>=', $todayStart);
        $balance = Tenant::withoutGlobalScope()->where('id', $tenantId)->value('point_balance');

        return [
            'point_unit' => PointUnitService::unit(),
            'summary' => [
                'total_count' => (int)(clone $summaryQuery)->count(),
                'total_amount' => self::formatAmount((float)(clone $summaryQuery)->sum('change_amount')),
                'today_count' => (int)(clone $todayQuery)->count(),
                'today_amount' => self::formatAmount((float)(clone $todayQuery)->sum('change_amount')),
                'point_balance' => self::formatAmount((float)$balance),
            ],
            'source_type_options' => [
                ['label' => 'AI应用消耗', 'value' => 'app'],
                ['label' => '应用开通/续费', 'value' => 'app_plan'],
                ['label' => '其他业务消耗', 'value' => 'other'],
            ],
        ];
    }

    public static function detail(int $tenantId, int $id): array
    {
        $row = self::buildQuery($tenantId, [])
            ->field(self::fields())
            ->where('tpl.id', $id)
            ->group('tpl.id')
            ->findOrEmpty()
            ->toArray();

        return $row ? (self::formatRows([$row])[0] ?? []) : [];
    }

    private function baseQuery()
    {
        return self::buildQuery((int)$this->adminInfo['tenant_id'], $this->params);
    }

    private static function buildQuery(int $tenantId, array $params)
    {
        $query = TenantPointLog::alias('tpl')
            ->leftJoin('user_account_log ual', 'ual.tenant_id = tpl.tenant_id AND ual.source_sn = tpl.source_sn AND ual.action = ' . AccountLogEnum::DEC . ' AND ual.change_type = ' . AccountLogEnum::UM_DEC_APP_CONSUME . ' AND (ual.delete_time IS NULL OR ual.delete_time = 0)')
            ->leftJoin('user u', 'u.id = ual.user_id')
            ->where('tpl.tenant_id', $tenantId)
            ->where('tpl.change_type', TenantPointService::TYPE_CONSUME)
            ->where('tpl.action', TenantPointService::ACTION_DEC);

        if (!empty($params['start_time'])) {
            $query->where('tpl.create_time', '>=', strtotime($params['start_time']));
        }
        if (!empty($params['end_time'])) {
            $endTime = strtotime($params['end_time']);
            if ($endTime && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$params['end_time'])) {
                $endTime += 86399;
            }
            $query->where('tpl.create_time', '<=', $endTime);
        }
        if (!empty($params['source_sn'])) {
            $query->where('tpl.source_sn', 'like', '%' . trim((string)$params['source_sn']) . '%');
        }
        if (!empty($params['log_sn'])) {
            $query->where('tpl.sn', 'like', '%' . trim((string)$params['log_sn']) . '%');
        }
        if (!empty($params['remark'])) {
            $query->where('tpl.remark', 'like', '%' . trim((string)$params['remark']) . '%');
        }
        if (!empty($params['keyword'])) {
            $keyword = trim((string)$params['keyword']);
            $query->where(function ($query) use ($keyword) {
                $query->whereLike('tpl.sn|tpl.source_sn|tpl.remark|tpl.extra|u.nickname|u.account|u.mobile|u.sn', '%' . $keyword . '%');
            });
        }
        if (!empty($params['user_info'])) {
            $keyword = trim((string)$params['user_info']);
            $query->whereLike('u.nickname|u.account|u.mobile|u.sn', '%' . $keyword . '%');
        }
        if (!empty($params['app_code'])) {
            $appCode = trim((string)$params['app_code']);
            $query->where('tpl.extra', 'like', '%"app_code":"' . addslashes($appCode) . '"%');
        }
        if (!empty($params['task_id'])) {
            $taskId = (int)$params['task_id'];
            $query->where(function ($query) use ($taskId) {
                $query->where('tpl.extra', 'like', '%"task_id":' . $taskId . '%')
                    ->whereOr('tpl.extra', 'like', '%"task_id":"' . $taskId . '"%');
            });
        }
        if (!empty($params['source_type'])) {
            $sourceType = (string)$params['source_type'];
            if ($sourceType === 'app') {
                $query->where('tpl.extra', 'like', '%"app_code":%')->where('tpl.extra', 'not like', '%"plan_id":%');
            } elseif ($sourceType === 'app_plan') {
                $query->where('tpl.extra', 'like', '%"plan_id":%');
            } elseif ($sourceType === 'other') {
                $query->where('tpl.extra', 'not like', '%"app_code":%')->where('tpl.extra', 'not like', '%"plan_id":%');
            }
        }
        if (array_key_exists('min_amount', $params) && trim((string)$params['min_amount']) !== '') {
            $query->where('tpl.change_amount', '>=', (float)$params['min_amount']);
        }
        if (array_key_exists('max_amount', $params) && trim((string)$params['max_amount']) !== '') {
            $query->where('tpl.change_amount', '<=', (float)$params['max_amount']);
        }

        return $query;
    }

    private static function fields(): string
    {
        return 'tpl.id,tpl.sn,tpl.change_type,tpl.action,tpl.change_amount,tpl.left_amount,tpl.source_sn,tpl.remark,tpl.extra,tpl.create_time,ual.sn as user_log_sn,ual.user_id,ual.change_amount as user_change_amount,ual.left_amount as user_left_amount,ual.remark as user_log_remark,u.sn as user_sn,u.nickname,u.account,u.mobile';
    }

    private static function formatRows(array $rows): array
    {
        $appCodes = [];
        foreach ($rows as $row) {
            $extra = self::normalizeExtra($row['extra'] ?? []);
            if (!empty($extra['app_code'])) {
                $appCodes[] = (string)$extra['app_code'];
            }
        }
        $appNames = $appCodes ? App::whereIn('code', array_values(array_unique($appCodes)))->column('name', 'code') : [];

        foreach ($rows as &$row) {
            $extra = self::normalizeExtra($row['extra'] ?? []);
            $appCode = (string)($extra['app_code'] ?? '');
            $row['extra'] = $extra;
            $row['app_code'] = $appCode;
            $row['app_name'] = $appCode !== '' ? ($appNames[$appCode] ?? $appCode) : '';
            $row['source_type_text'] = self::sourceTypeText($extra);
            $row['billing_side_text'] = self::billingSideText((string)($extra['billing_side'] ?? ''));
            $row['change_amount_text'] = '-' . self::formatAmount((float)$row['change_amount']) . ' ' . PointUnitService::unit();
            $row['left_amount_text'] = self::formatAmount((float)$row['left_amount']) . ' ' . PointUnitService::unit();
            $row['user_change_amount_text'] = !empty($row['user_change_amount']) ? '-' . self::formatAmount((float)$row['user_change_amount']) . ' ' . PointUnitService::unit() : '';
            $row['user_left_amount_text'] = isset($row['user_left_amount']) && $row['user_left_amount'] !== null ? self::formatAmount((float)$row['user_left_amount']) . ' ' . PointUnitService::unit() : '';
            $row['create_time_text'] = !empty($row['create_time']) ? date('Y-m-d H:i:s', (int)$row['create_time']) : '-';
            $row['user'] = [
                'id' => (int)($row['user_id'] ?? 0),
                'sn' => (string)($row['user_sn'] ?? ''),
                'nickname' => (string)($row['nickname'] ?? ''),
                'account' => (string)($row['account'] ?? ''),
                'mobile' => (string)($row['mobile'] ?? ''),
            ];
            $row['source_detail_items'] = self::sourceDetailItems($row, $extra);
        }
        unset($row);

        return $rows;
    }

    private static function normalizeExtra($extra): array
    {
        if (is_array($extra)) {
            return $extra;
        }
        if (is_string($extra) && $extra !== '') {
            $decoded = json_decode($extra, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private static function sourceDetailItems(array $row, array $extra): array
    {
        $labels = [
            'app_code' => '应用编码',
            'task_id' => '任务ID',
            'result_id' => '结果ID',
            'channel' => '通道',
            'provider' => '供应商',
            'model' => '模型',
            'quality' => '清晰度/规格',
            'ratio' => '比例',
            'duration' => '时长',
            'quantity' => '数量',
            'plan_id' => '套餐ID',
            'plan_name' => '套餐名称',
            'order_type' => '订单类型',
            'operator_type' => '操作人类型',
            'operator_id' => '操作人ID',
            'billing_side' => '计费侧',
        ];
        $items = [
            ['label' => '流水编号', 'value' => (string)$row['sn']],
            ['label' => '来源单号', 'value' => (string)$row['source_sn']],
            ['label' => '消耗说明', 'value' => (string)$row['remark']],
        ];
        if (!empty($row['app_name'])) {
            $items[] = ['label' => '应用名称', 'value' => (string)$row['app_name']];
        }
        if (!empty($row['user_id'])) {
            $items[] = ['label' => '用户ID', 'value' => (string)$row['user_id']];
            $items[] = ['label' => '用户编号', 'value' => (string)($row['user_sn'] ?? '')];
            $items[] = ['label' => '用户昵称', 'value' => (string)($row['nickname'] ?? '')];
            $items[] = ['label' => '用户账号', 'value' => (string)($row['account'] ?? '')];
            $items[] = ['label' => '用户手机号', 'value' => (string)($row['mobile'] ?? '')];
            $items[] = ['label' => '用户流水号', 'value' => (string)($row['user_log_sn'] ?? '')];
            $items[] = ['label' => '用户扣减金额', 'value' => (string)($row['user_change_amount_text'] ?? '')];
            $items[] = ['label' => '用户扣减后余额', 'value' => (string)($row['user_left_amount_text'] ?? '')];
        }
        foreach ($labels as $key => $label) {
            if (array_key_exists($key, $extra) && $extra[$key] !== '' && $extra[$key] !== null) {
                $value = $key === 'billing_side' ? self::billingSideText((string)$extra[$key]) : $extra[$key];
                $items[] = ['label' => $label, 'value' => is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE)];
            }
        }
        return $items;
    }

    private static function sourceTypeText(array $extra): string
    {
        if (!empty($extra['plan_id'])) {
            return '应用开通/续费';
        }
        if (!empty($extra['app_code'])) {
            return 'AI应用消耗';
        }
        return '其他业务消耗';
    }

    private static function billingSideText(string $side): string
    {
        return match ($side) {
            'tenant_cost' => '租户成本',
            'user_charge' => '用户售价',
            default => $side ?: '租户消耗',
        };
    }

    private static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
