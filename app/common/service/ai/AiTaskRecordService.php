<?php

namespace app\common\service\ai;

use think\facade\Db;

class AiTaskRecordService
{
    public static function lists(array $params, int $tenantId = 0): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, (int)($params['page_size'] ?? 15));
        $query = self::baseQuery($params, $tenantId);
        $count = (clone $query)->count();
        $rows = $query
            ->field('t.id,t.tenant_id,t.user_id,t.prompt,t.negative_prompt,t.style,t.ratio,t.quantity,t.status,t.error,t.create_time,t.update_time,t.finish_time,te.name tenant_name,te.sn tenant_sn,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->order('t.id', 'desc')
            ->limit(($pageNo - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['app_code'] = 'aigc_image';
            $row['app_name'] = 'AIGC生图';
            $row['task_type'] = 'image_generate';
            $row['task_sn'] = 'aigc_image_' . $row['id'];
            $row['point_estimated'] = (float)$row['quantity'];
            $row['point_actual'] = $row['status'] === 'success' ? (float)$row['quantity'] : 0;
            $row['initiator_name'] = $row['user_nickname'] ?: ($row['user_account'] ?: ('用户#' . $row['user_id']));
            $row['create_time_text'] = self::formatTime((int)$row['create_time']);
            $row['finish_time_text'] = self::formatTime((int)$row['finish_time']);
        }

        return [
            'lists' => $rows,
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
            'extend' => [],
        ];
    }

    public static function detail(int $id, int $tenantId = 0): array
    {
        $query = self::baseQuery(['id' => $id], $tenantId);
        $row = $query
            ->field('t.*,te.name tenant_name,te.sn tenant_sn,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->find();
        if (empty($row)) {
            return [];
        }
        $data = is_array($row) ? $row : $row->toArray();
        $data['app_code'] = 'aigc_image';
        $data['app_name'] = 'AIGC生图';
        $data['task_type'] = 'image_generate';
        $data['task_sn'] = 'aigc_image_' . $data['id'];
        $data['point_estimated'] = (float)$data['quantity'];
        $data['point_actual'] = $data['status'] === 'success' ? (float)$data['quantity'] : 0;
        $data['create_time_text'] = self::formatTime((int)$data['create_time']);
        $data['finish_time_text'] = self::formatTime((int)$data['finish_time']);
        $data['request_params'] = [
            'prompt' => $data['prompt'],
            'negative_prompt' => $data['negative_prompt'],
            'style' => $data['style'],
            'ratio' => $data['ratio'],
            'quantity' => $data['quantity'],
        ];
        return $data;
    }

    private static function baseQuery(array $params, int $tenantId = 0)
    {
        $query = Db::name('aigc_image_task')
            ->alias('t')
            ->leftJoin('tenant te', 'te.id = t.tenant_id')
            ->leftJoin('user u', 'u.id = t.user_id AND u.tenant_id = t.tenant_id')
            ->where('t.delete_time', 0);

        if ($tenantId > 0) {
            $query->where('t.tenant_id', $tenantId);
        }
        if (!empty($params['id'])) {
            $query->where('t.id', (int)$params['id']);
        }
        if (!empty($params['tenant_id']) && $tenantId <= 0) {
            $query->where('t.tenant_id', (int)$params['tenant_id']);
        }
        if (!empty($params['user_id'])) {
            $query->where('t.user_id', (int)$params['user_id']);
        }
        if (!empty($params['status'])) {
            $query->where('t.status', (string)$params['status']);
        }
        if (!empty($params['keyword'])) {
            $keyword = trim((string)$params['keyword']);
            $query->where(function ($query) use ($keyword) {
                $query->whereLike('t.prompt', '%' . $keyword . '%')
                    ->whereOr('t.id', (int)$keyword)
                    ->whereOrLike('te.name', '%' . $keyword . '%')
                    ->whereOrLike('te.sn', '%' . $keyword . '%')
                    ->whereOrLike('u.nickname', '%' . $keyword . '%')
                    ->whereOrLike('u.account', '%' . $keyword . '%')
                    ->whereOrLike('u.mobile', '%' . $keyword . '%');
            });
        }
        if (!empty($params['create_time_start'])) {
            $query->where('t.create_time', '>=', strtotime((string)$params['create_time_start']));
        }
        if (!empty($params['create_time_end'])) {
            $query->where('t.create_time', '<=', strtotime((string)$params['create_time_end'] . ' 23:59:59'));
        }

        return $query;
    }

    private static function formatTime(int $time): string
    {
        return $time > 0 ? date('Y-m-d H:i:s', $time) : '';
    }
}
