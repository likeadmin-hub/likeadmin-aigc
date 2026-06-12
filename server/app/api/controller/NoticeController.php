<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------
namespace app\api\controller;

use app\api\logic\NoticeLogic;

/**
 * 用户通知
 * Class NoticeController
 * @package app\api\controller
 */
class NoticeController extends BaseApiController
{
    /**
     * @notes 未读消息摘要
     * @return \think\response\Json
     */
    public function unread()
    {
        return $this->data(NoticeLogic::unread((int)$this->request->tenantId, $this->userId));
    }
}
