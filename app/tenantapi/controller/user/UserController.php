<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------
namespace app\tenantapi\controller\user;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\user\UserLists;
use app\tenantapi\logic\user\UserLogic;
use app\tenantapi\validate\user\AdjustUserMoney;
use app\tenantapi\validate\user\UserValidate;
use app\common\service\membership\MembershipService;
use Exception;

/**
 * 用户控制器
 * Class TenantController
 * @package app\tenantapi\controller\user
 */
class UserController extends BaseAdminController
{

    /**
     * @notes 用户列表
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:16
     */
    public function lists()
    {
        return $this->dataLists(new UserLists());
    }


    /**
     * @notes 获取用户详情
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:34
     */
    public function detail()
    {
        $params = (new UserValidate())->goCheck('detail');
        $detail = UserLogic::detail((int)$params['id'], $this->tenantId);
        return $this->success('', $detail);
    }


    /**
     * @notes 编辑用户信息
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:34
     */
    public function edit()
    {
        $params = (new UserValidate())->post()->goCheck('setInfo');
        UserLogic::setUserInfo($params, $this->tenantId);
        return $this->success('操作成功', [], 1, 1);
    }


    /**
     * @notes 修改用户密码
     * @return \think\response\Json
     */
    public function resetPassword()
    {
        $params = (new UserValidate())->post()->goCheck('resetPassword');
        UserLogic::resetPassword($params, $this->tenantId);
        return $this->success('密码修改成功', [], 1, 1);
    }

    /**
     * @notes 获取当前租户可设置的会员套餐
     * @return \think\response\Json
     */
    public function membershipPlans()
    {
        return $this->success('获取成功', MembershipService::plans($this->tenantId, true));
    }

    /**
     * @notes 直接设置用户会员套餐
     * @return \think\response\Json
     */
    public function setMembership()
    {
        $params = (new UserValidate())->post()->goCheck('setMembership');
        try {
            $result = MembershipService::assignPlan(
                $this->tenantId,
                (int)$params['id'],
                (int)$params['plan_id']
            );
            return $this->success('套餐设置成功', $result, 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }


    /**
     * @notes 调整用户点数
     * @return \think\response\Json
     * @author 段誉
     * @date 2023/2/23 14:33
     */
    public function adjustMoney()
    {
        $params = (new AdjustUserMoney())->post()->goCheck();
        $res = UserLogic::adjustUserMoney($params, $this->tenantId);
        if (true === $res) {
            return $this->success('操作成功', [], 1, 1);
        }
        return $this->fail($res);
    }

}
