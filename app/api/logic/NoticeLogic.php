<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------
namespace app\api\logic;

use app\common\logic\BaseLogic;
use app\common\model\notice\TenantPcNotice;
use app\common\model\notice\TenantPcNoticeRead;
use think\facade\Db;

/**
 * PC 用户通知
 * Class NoticeLogic
 * @package app\api\logic
 */
class NoticeLogic extends BaseLogic
{
    public static function lists(int $tenantId, int $userId, array $params = []): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            return [];
        }

        $pageNo = max((int)($params['page_no'] ?? 1), 1);
        $pageSize = min(max((int)($params['page_size'] ?? 20), 1), 100);
        $rows = TenantPcNotice::where([
                ['tenant_id', '=', $tenantId],
                ['status', '=', 1],
            ])
            ->field('id,title,summary,image,is_popup,publish_time,create_time')
            ->order(['sort' => 'desc', 'publish_time' => 'desc', 'id' => 'desc'])
            ->page($pageNo, $pageSize)
            ->select()
            ->toArray();

        if (!$rows) {
            return [];
        }

        $noticeIds = array_column($rows, 'id');
        $readRows = TenantPcNoticeRead::where([
                ['tenant_id', '=', $tenantId],
                ['user_id', '=', $userId],
            ])
            ->whereIn('notice_id', $noticeIds)
            ->column('read_time', 'notice_id');

        foreach ($rows as &$row) {
            $readTime = (int)($readRows[$row['id']] ?? 0);
            $row['is_read'] = $readTime > 0 ? 1 : 0;
            $row['read_time'] = $readTime;
        }
        unset($row);

        return $rows;
    }

    public static function detail(int $tenantId, int $userId, int $id): array
    {
        if ($tenantId <= 0 || $userId <= 0 || $id <= 0) {
            return [];
        }

        $notice = self::enabledNoticeQuery($tenantId)
            ->where('id', $id)
            ->findOrEmpty();
        if ($notice->isEmpty()) {
            return [];
        }

        self::markRead($tenantId, $id, $userId);
        $data = $notice->toArray();
        $data['is_read'] = 1;
        return $data;
    }

    /**
     * @notes 未读消息摘要
     * @param int $tenantId
     * @param int $userId
     * @return array
     */
    public static function unread(int $tenantId, int $userId): array
    {
        if ($userId <= 0) {
            return [
                'unread' => 0,
                'has_unread' => false,
            ];
        }

        $unread = $tenantId > 0 ? TenantPcNotice::alias('n')
            ->where([
                ['n.tenant_id', '=', $tenantId],
                ['n.status', '=', 1],
            ])
            ->leftJoin('tenant_pc_notice_read r', 'r.notice_id = n.id AND r.tenant_id = n.tenant_id AND r.user_id = ' . (int)$userId)
            ->whereRaw('(r.id IS NULL OR r.read_time = 0)')
            ->count() : 0;

        return [
            'unread' => (int)$unread,
            'has_unread' => $unread > 0,
        ];
    }

    public static function read(int $tenantId, int $userId, int $id): void
    {
        if ($tenantId <= 0 || $userId <= 0 || $id <= 0) {
            return;
        }

        $exists = self::enabledNoticeQuery($tenantId)
            ->where('id', $id)
            ->count();
        if (!$exists) {
            return;
        }

        self::markRead($tenantId, $id, $userId);
    }

    public static function popup(int $tenantId, int $userId): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            return [];
        }

        $notice = TenantPcNotice::alias('n')
            ->where([
                ['n.tenant_id', '=', $tenantId],
                ['n.status', '=', 1],
            ])
            ->leftJoin('tenant_pc_notice_read r', 'r.notice_id = n.id AND r.tenant_id = n.tenant_id AND r.user_id = ' . (int)$userId)
            ->where('n.is_popup', 1)
            ->whereRaw('(r.id IS NULL OR r.popup_time = 0)')
            ->field('n.*')
            ->order(['n.sort' => 'desc', 'n.publish_time' => 'desc', 'n.id' => 'desc'])
            ->findOrEmpty();

        if ($notice->isEmpty()) {
            return [];
        }

        self::markPopup($tenantId, (int)$notice['id'], $userId);
        $data = $notice->toArray();
        $data['is_read'] = 0;
        $data['auto_popup'] = 1;
        return $data;
    }

    private static function enabledNoticeQuery(int $tenantId)
    {
        return TenantPcNotice::where([
            ['tenant_id', '=', $tenantId],
            ['status', '=', 1],
        ]);
    }

    private static function markRead(int $tenantId, int $noticeId, int $userId): void
    {
        self::upsertReadState($tenantId, $noticeId, $userId, true, false);
    }

    private static function markPopup(int $tenantId, int $noticeId, int $userId): void
    {
        self::upsertReadState($tenantId, $noticeId, $userId, false, true);
    }

    private static function upsertReadState(int $tenantId, int $noticeId, int $userId, bool $read, bool $popup): void
    {
        Db::transaction(function () use ($tenantId, $noticeId, $userId, $read, $popup) {
            $now = time();
            $record = TenantPcNoticeRead::where([
                    'tenant_id' => $tenantId,
                    'notice_id' => $noticeId,
                    'user_id' => $userId,
                ])
                ->lock(true)
                ->findOrEmpty();

            if ($record->isEmpty()) {
                TenantPcNoticeRead::create([
                    'tenant_id' => $tenantId,
                    'notice_id' => $noticeId,
                    'user_id' => $userId,
                    'read_time' => $read ? $now : 0,
                    'popup_time' => $popup ? $now : 0,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                return;
            }

            $data = ['update_time' => $now];
            if ($read && (int)$record['read_time'] <= 0) {
                $data['read_time'] = $now;
            }
            if ($popup && (int)$record['popup_time'] <= 0) {
                $data['popup_time'] = $now;
            }

            if (count($data) > 1) {
                $record->save($data);
            }
        });
    }
}
