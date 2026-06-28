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
     * @notes 消息公告列表
     * @return \think\response\Json
     */
    public function lists()
    {
        return $this->data(NoticeLogic::lists(
            (int)$this->request->tenantId,
            $this->userId,
            $this->request->get()
        ));
    }

    /**
     * @notes 消息公告详情
     * @return \think\response\Json
     */
    public function detail()
    {
        return $this->data(NoticeLogic::detail(
            (int)$this->request->tenantId,
            $this->userId,
            (int)$this->request->get('id', 0)
        ));
    }

    /**
     * @notes 未读消息摘要
     * @return \think\response\Json
     */
    public function unread()
    {
        return $this->data(NoticeLogic::unread((int)$this->request->tenantId, $this->userId));
    }

    /**
     * @notes 标记消息已读
     * @return \think\response\Json
     */
    public function read()
    {
        NoticeLogic::read(
            (int)$this->request->tenantId,
            $this->userId,
            (int)$this->request->post('id', 0)
        );
        return $this->success('操作成功');
    }

    /**
     * @notes 获取进入自动弹窗公告
     * @return \think\response\Json
     */
    public function popup()
    {
        return $this->data(NoticeLogic::popup((int)$this->request->tenantId, $this->userId));
    }
}
