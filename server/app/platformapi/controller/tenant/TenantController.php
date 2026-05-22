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
namespace app\platformapi\controller\tenant;

use app\common\model\dept\TenantDept;
use app\platformapi\controller\BaseAdminController;
use app\platformapi\lists\tenant\TenantLists;
use app\platformapi\logic\setting\pay\PayConfigLogic;
use app\platformapi\logic\setting\pay\PayWayLogic;
use app\platformapi\logic\tenant\TenantAdminLogic;
use app\platformapi\logic\tenant\TenantLogic;
use app\platformapi\logic\tenant\TenantSystemMenuLogic;
use app\platformapi\service\TenantCreatService;
use app\platformapi\validate\tenant\TenantValidate;
use app\common\service\point\TenantPointService;
use app\common\service\tenant\TenantSsoService;
use app\common\service\app\DefaultAppService;
use app\common\service\billing\PackageProvisionService;
use app\tenantapi\logic\article\ArticleLogic;
use app\tenantapi\logic\decorate\DecorateDataLogic;
use app\tenantapi\logic\notice\NoticeLogic;
use think\facade\Db;

/**
 * 用户控制器
 * Class TenantController
 * @package app\platformapi\controller\user
 */
class TenantController extends BaseAdminController
{

    /**
     * @notes 用户列表
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:16
     */
    public function lists()
    {
        return $this->dataLists(new TenantLists());
    }


    /**
     * @notes 获取用户详情
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:34
     */
    public function detail()
    {
        $params = (new TenantValidate())->goCheck('detail');
        $result = TenantLogic::detail($params['id']);
        if (false === $result) {
            return $this->fail(TenantLogic::getError());
        }
        return $this->success('获取成功', $result);
    }

    /**
     * @notes 新增租户信息 同步初始化对应租户信息
     * @return \think\response\Json
     * @author yfdong
     * @date 2024/09/07 12:23
     */
    public function add()
    {
        $params = (new TenantValidate())->post()->goCheck('add');
        try {
            // 开始事务
            DB::startTrans();
            // 验证参数
            // 创建租户基本信息
            $tenant = TenantLogic::add($params);
            // 判断用户是否采用分表模式
            if (isset($params['tactics']) && $params['tactics'] == '1') {
                (new TenantCreatService)->createTenantTable($tenant['sn']);
                (new TenantCreatService)->initializationTenantData($tenant['id'],$tenant['sn'],$params);
            }else{
                // 初始化租户文章列表
                ArticleLogic::initialization($tenant['id']);
                // 初始化租户管理员账号
                $managerInfo = TenantAdminLogic::initialization($tenant['id'], $tenant['sn'], $params);
                // 初始化管理员部门信息
                TenantDept::initialization($tenant['id'], $managerInfo['id']);
                // 创建租户菜单权限
                TenantSystemMenuLogic::initialization($tenant['id']);
                // 初始化支付方式配置
                PayConfigLogic::initialization($tenant['id']);
                // 初始化支付配置是否开启
                PayWayLogic::initialization($tenant['id']);
                // 初始化短信场景配置
                NoticeLogic::initialization($tenant['id']);
                // 创建默认装修数据
                DecorateDataLogic::initialization($tenant['id']);
            }
            DefaultAppService::syncTenantDefaults(
                (int)$tenant['id'],
                (string)$tenant['sn'],
                isset($params['tactics']) && (string)$params['tactics'] === '1'
            );
            PackageProvisionService::syncTenant(
                (int)$tenant['id'],
                (string)$tenant['sn'],
                isset($params['tactics']) && (string)$params['tactics'] === '1'
            );
            TenantLogic::syncCustomStorageMenu((int)$tenant['id'], (int)($params['allow_custom_storage'] ?? 0) === 1);
            // 提交事务
            DB::commit();
            // 返回成功
            return $this->success('新增成功', [], 1, 1);
        } catch (\Exception $e) {
            // 回滚事务
            DB::rollBack();
            // 处理异常并返回错误信息
            return $this->fail('新增失败：' . $e->getMessage());
        }
    }

    /**
     * @notes 编辑用户信息
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:34
     */
    public function edit()
    {
        $params = (new TenantValidate())->post()->goCheck('edit');
        $result = TenantLogic::edit($params);
        if (true === $result) {
            return $this->success('操作成功', [], 1, 1);
        }
        return $this->fail(TenantLogic::getError());
    }

    /**
     * @notes 删除租户
     * @return \think\response\Json
     * @author JXDN
     * @date 2024/09/03 17:02
     */
    public function delete()
    {
        $params = (new TenantValidate())->post()->goCheck('delete');
        $result = TenantLogic::delete($params);
        if (true === $result) {
            return $this->success('删除成功', [], 1, 1);
        }
        return $this->fail(TenantLogic::getError());
    }

    public function rechargePoints()
    {
        $tenantId = (int)$this->request->post('tenant_id', 0);
        $points = (float)$this->request->post('points', 0);
        $remark = (string)$this->request->post('remark', '');
        try {
            TenantPointService::recharge($tenantId, $points, (int)($this->adminInfo['admin_id'] ?? 0), $remark);
            return $this->success('充值成功', [], 1, 1);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function pointLogs()
    {
        $tenantId = (int)$this->request->get('tenant_id', 0);
        return $this->success('获取成功', TenantPointService::logs($tenantId));
    }

    public function sso()
    {
        $tenantId = (int)$this->request->post('tenant_id', 0);
        $target = (string)$this->request->post('target', 'admin');
        $redirect = (string)$this->request->post('redirect', '');
        try {
            return $this->success('获取成功', TenantSsoService::createTicket($tenantId, $this->adminId, $target, $redirect));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}
