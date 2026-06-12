<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------
namespace app\api\logic;

use app\common\logic\BaseLogic;
use app\common\model\notice\NoticeRecord;
use app\common\model\notice\TenantNoticeRecord;

/**
 * PC 用户通知
 * Class NoticeLogic
 * @package app\api\logic
 */
class NoticeLogic extends BaseLogic
{
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

        $tenantUnread = 0;
        if ($tenantId > 0) {
            $tenantUnread = (new TenantNoticeRecord())
                ->where([
                    ['tenant_id', '=', $tenantId],
                    ['user_id', '=', $userId],
                    ['read', '=', 0],
                ])
                ->count();
        }

        $platformUnread = (new NoticeRecord())
            ->where([
                ['user_id', '=', $userId],
                ['read', '=', 0],
            ])
            ->count();

        $unread = (int)$tenantUnread + (int)$platformUnread;
        return [
            'unread' => $unread,
            'has_unread' => $unread > 0,
        ];
    }
}
