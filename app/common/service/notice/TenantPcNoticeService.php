<?php

namespace app\common\service\notice;

use app\common\model\notice\TenantPcNotice;
use RuntimeException;
use think\facade\Db;

class TenantPcNoticeService
{
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    public static function detail(int $tenantId, int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        $notice = TenantPcNotice::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
        return $notice->isEmpty() ? [] : $notice->toArray();
    }

    public static function save(int $tenantId, array $params): TenantPcNotice
    {
        $title = trim((string)($params['title'] ?? ''));
        $summary = trim((string)($params['summary'] ?? ''));
        $content = trim((string)($params['content'] ?? ''));

        if ($title === '') {
            throw new RuntimeException('请输入公告标题');
        }
        if (mb_strlen($title) > 120) {
            throw new RuntimeException('公告标题最多120个字符');
        }
        if (mb_strlen($summary) > 255) {
            throw new RuntimeException('公告摘要最多255个字符');
        }
        if ($content === '') {
            throw new RuntimeException('请输入公告正文');
        }

        $data = [
            'tenant_id' => $tenantId,
            'title' => $title,
            'summary' => $summary,
            'content' => $content,
            'image' => trim((string)($params['image'] ?? '')),
            'is_popup' => (int)($params['is_popup'] ?? 0) ? 1 : 0,
            'status' => (int)($params['status'] ?? 1) ? self::STATUS_ENABLED : self::STATUS_DISABLED,
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];

        return Db::transaction(function () use ($tenantId, $params, $data) {
            $id = (int)($params['id'] ?? 0);
            if ($id > 0) {
                $notice = TenantPcNotice::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
                if ($notice->isEmpty()) {
                    throw new RuntimeException('公告不存在');
                }
                $notice->save($data);
                return $notice;
            }

            $data['publish_time'] = time();
            $data['create_time'] = time();
            return TenantPcNotice::create($data);
        });
    }

    public static function delete(int $tenantId, int $id): void
    {
        $notice = TenantPcNotice::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
        if ($notice->isEmpty()) {
            throw new RuntimeException('公告不存在');
        }
        $notice->delete();
    }

    public static function status(int $tenantId, int $id, int $status): void
    {
        $notice = TenantPcNotice::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
        if ($notice->isEmpty()) {
            throw new RuntimeException('公告不存在');
        }
        $notice->save([
            'status' => $status ? self::STATUS_ENABLED : self::STATUS_DISABLED,
            'update_time' => time(),
        ]);
    }
}
