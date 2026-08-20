<?php

namespace app\platformapi\lists\tenant;

use app\common\enum\user\AccountLogEnum;
use app\common\lists\ListsExtendInterface;
use app\common\lists\ListsSearchInterface;
use app\common\model\app\App;
use app\common\model\tenant\TenantPointLog;
use app\common\service\point\TenantPointService;
use app\common\service\PointUnitService;
use app\platformapi\lists\BaseAdminDataLists;

class TenantPowerConsumeLists extends BaseAdminDataLists implements ListsSearchInterface, ListsExtendInterface
{
    public function setSearch(): array
    {
        return [];
    }

    public function lists(): array
    {
        $rows = $this->baseQuery()
            ->field(self::fields())
            ->group('tpl.id')
            ->order('tpl.id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        return self::formatRows($rows);
    }

    public function count(): int
    {
        return (int)$this->baseQuery()->distinct(true)->count('tpl.id');
    }

    public function extend(): array
    {
        $summaryQuery = self::summaryQuery();
        $todayQuery = (clone $summaryQuery)->where('tpl.create_time', '>=', strtotime(date('Y-m-d')));

        return [
            'point_unit' => PointUnitService::unit(),
            'summary' => [
                'total_count' => (int)(clone $summaryQuery)->count(),
                'total_amount' => self::formatAmount((float)(clone $summaryQuery)->sum('tpl.change_amount')),
                'today_count' => (int)(clone $todayQuery)->count(),
                'today_amount' => self::formatAmount((float)(clone $todayQuery)->sum('tpl.change_amount')),
                'tenant_count' => (int)(clone $summaryQuery)->distinct(true)->count('tpl.tenant_id'),
            ],
        ];
    }

    private function baseQuery()
    {
        $query = self::summaryQuery()
            ->leftJoin('user_account_log ual', 'ual.tenant_id = tpl.tenant_id AND CONVERT(ual.source_sn USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(tpl.source_sn USING utf8mb4) COLLATE utf8mb4_unicode_ci AND ual.action = ' . AccountLogEnum::DEC . ' AND ual.change_type = ' . AccountLogEnum::UM_DEC_APP_CONSUME . ' AND (ual.delete_time IS NULL OR ual.delete_time = 0)')
            ->leftJoin('user u', 'u.id = ual.user_id');

        self::applyFilters($query, $this->params, true);
        return $query;
    }

    private static function summaryQuery()
    {
        return TenantPointLog::withoutGlobalScope()->alias('tpl')
            ->leftJoin('tenant t', 't.id = tpl.tenant_id')
            ->where('tpl.change_type', TenantPointService::TYPE_CONSUME)
            ->where('tpl.action', TenantPointService::ACTION_DEC);
    }

    private static function applyFilters($query, array $params, bool $withUser): void
    {
        if (!empty($params['tenant_info'])) {
            $keyword = trim((string)$params['tenant_info']);
            $query->whereLike('t.name|t.sn|t.tel|t.domain_alias', '%' . $keyword . '%');
        }
        if (!empty($params['keyword'])) {
            $keyword = trim((string)$params['keyword']);
            $fields = 'tpl.sn|tpl.source_sn|tpl.remark|tpl.extra|t.name|t.sn';
            if ($withUser) {
                $fields .= '|u.nickname|u.account|u.mobile|u.sn';
            }
            $query->whereLike($fields, '%' . $keyword . '%');
        }
        if (!empty($params['app_code'])) {
            $appCode = trim((string)$params['app_code']);
            $query->where('tpl.extra', 'like', '%"app_code":"' . addslashes($appCode) . '"%');
        }
        if (!empty($params['source_sn'])) {
            $query->where('tpl.source_sn', 'like', '%' . trim((string)$params['source_sn']) . '%');
        }
        if (!empty($params['log_sn'])) {
            $query->where('tpl.sn', 'like', '%' . trim((string)$params['log_sn']) . '%');
        }
        if (!empty($params['task_id'])) {
            $taskId = (int)$params['task_id'];
            $query->where(function ($query) use ($taskId) {
                $query->where('tpl.extra', 'like', '%"task_id":' . $taskId . '%')
                    ->whereOr('tpl.extra', 'like', '%"task_id":"' . $taskId . '"%');
            });
        }
        if (!empty($params['start_time'])) {
            $query->where('tpl.create_time', '>=', strtotime((string)$params['start_time']));
        }
        if (!empty($params['end_time'])) {
            $endTime = strtotime((string)$params['end_time']);
            if ($endTime && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$params['end_time'])) {
                $endTime += 86399;
            }
            $query->where('tpl.create_time', '<=', $endTime);
        }
        if (array_key_exists('min_amount', $params) && trim((string)$params['min_amount']) !== '') {
            $query->where('tpl.change_amount', '>=', (float)$params['min_amount']);
        }
        if (array_key_exists('max_amount', $params) && trim((string)$params['max_amount']) !== '') {
            $query->where('tpl.change_amount', '<=', (float)$params['max_amount']);
        }
    }

    private static function fields(): string
    {
        return 'tpl.id,tpl.sn,tpl.tenant_id,tpl.change_amount,tpl.left_amount,tpl.source_sn,tpl.remark,tpl.extra,tpl.create_time,t.name as tenant_name,t.sn as tenant_sn,t.tel as tenant_tel,t.domain_alias,ual.sn as user_log_sn,ual.user_id,ual.change_amount as user_change_amount,ual.left_amount as user_left_amount,u.sn as user_sn,u.nickname,u.account,u.mobile';
    }

    public static function formatRows(array $rows): array
    {
        $appCodes = [];
        foreach ($rows as $row) {
            $extra = self::normalizeExtra($row['extra'] ?? []);
            if (!empty($extra['app_code'])) {
                $appCodes[] = (string)$extra['app_code'];
            }
        }
        $appNames = $appCodes ? App::withoutGlobalScope()->whereIn('code', array_values(array_unique($appCodes)))->column('name', 'code') : [];

        foreach ($rows as &$row) {
            $extra = self::normalizeExtra($row['extra'] ?? []);
            $appCode = (string)($extra['app_code'] ?? '');
            $row['extra'] = $extra;
            $row['app_code'] = $appCode;
            $row['app_name'] = $appCode !== '' ? ($appNames[$appCode] ?? $appCode) : '';
            $row['billing_side_text'] = '租户成本';
            $row['price_source_text'] = self::priceSourceText((string)($extra['price_source'] ?? ''));
            $row['change_amount_text'] = '-' . self::formatAmount((float)$row['change_amount']) . ' ' . PointUnitService::unit();
            $row['left_amount_text'] = self::formatAmount((float)$row['left_amount']) . ' ' . PointUnitService::unit();
            $row['user_change_amount_text'] = isset($row['user_change_amount']) && $row['user_change_amount'] !== null
                ? '-' . self::formatAmount((float)$row['user_change_amount']) . ' ' . PointUnitService::unit()
                : '';
            $row['create_time_text'] = self::formatTime($row['create_time'] ?? null);
            $row['user_text'] = self::userText($row);
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
        $items = [
            ['label' => '租户', 'value' => (string)($row['tenant_name'] ?: $row['tenant_sn'])],
            ['label' => '租户编号', 'value' => (string)$row['tenant_sn']],
            ['label' => '租户成本流水', 'value' => (string)$row['sn']],
            ['label' => '来源单号', 'value' => (string)$row['source_sn']],
            ['label' => '消耗说明', 'value' => (string)$row['remark']],
            ['label' => '计费侧', 'value' => '租户成本'],
            ['label' => '成本来源', 'value' => self::priceSourceText((string)($extra['price_source'] ?? ''))],
        ];
        if (!empty($row['app_name'])) {
            $items[] = ['label' => '应用', 'value' => (string)$row['app_name'] . ($row['app_code'] ? ' (' . $row['app_code'] . ')' : '')];
        }
        if (!empty($row['user_id'])) {
            $items[] = ['label' => 'C端用户', 'value' => self::userText($row)];
            $items[] = ['label' => 'C端扣费流水', 'value' => (string)($row['user_log_sn'] ?? '')];
            $items[] = ['label' => 'C端用户扣费', 'value' => (string)($row['user_change_amount_text'] ?? '')];
        }
        $labels = [
            'task_id' => '任务ID',
            'result_id' => '结果ID',
            'channel' => '通道',
            'provider' => '供应商',
            'model' => '模型',
            'quality' => '清晰度/规格',
            'ratio' => '比例',
            'duration' => '时长',
            'quantity' => '数量',
            'market_product_id' => '市场商品ID',
            'market_sku_id' => '成本SKU',
        ];
        foreach ($labels as $key => $label) {
            if (array_key_exists($key, $extra) && $extra[$key] !== '' && $extra[$key] !== null) {
                $value = $extra[$key];
                $items[] = ['label' => $label, 'value' => is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE)];
            }
        }
        return $items;
    }

    private static function userText(array $row): string
    {
        return (string)($row['nickname'] ?: $row['account'] ?: $row['mobile'] ?: $row['user_sn'] ?: '');
    }

    private static function priceSourceText(string $source): string
    {
        return match ($source) {
            'platform_market' => '总平台算力市场成本',
            'tenant_market' => '租户市场成本',
            'power_market_sku' => '总平台算力市场SKU成本',
            'power_market_app_api' => '总平台应用API成本',
            'power_market_video' => '总平台视频成本',
            'power_market_text_model' => '总平台文本模型成本',
            'model_config' => '总平台模型配置成本',
            'legacy' => '历史计费规则',
            default => $source ?: '总平台成本规则',
        };
    }

    private static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private static function formatTime($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $timestamp = is_numeric($value)
            ? (int)$value
            : strtotime(trim((string)$value));

        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '-';
    }
}
