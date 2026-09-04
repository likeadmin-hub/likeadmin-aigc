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
namespace app\tenantapi\validate\user;


use app\common\model\user\User;
use app\common\validate\BaseValidate;

/**
 * 用户验证
 * Class TenantValidate
 * @package app\tenantapi\validate\user
 */
class UserValidate extends BaseValidate
{

    protected $rule = [
        'id' => 'require|checkUser',
        'field' => 'require|checkField',
        'value' => 'require',
        'tenant_id' => 'require',
        'password' => 'require|length:6,32',
        'password_confirm' => 'require|confirm',
        'plan_id' => 'require|integer|gt:0',
    ];

    protected $message = [
        'id.require' => '请选择用户',
        'field.require' => '请选择操作',
        'value.require' => '请输入内容',
        'tenant_id.require' => '请选择租户标识',
        'password.require' => '请输入新密码',
        'password.length' => '密码长度须在6-32位字符',
        'password_confirm.require' => '请输入确认密码',
        'password_confirm.confirm' => '两次输入的密码不一致',
        'plan_id.require' => '请选择会员套餐',
        'plan_id.integer' => '会员套餐参数错误',
        'plan_id.gt' => '请选择有效的会员套餐',
    ];


    /**
     * @notes 详情场景
     * @return UserValidate
     * @author 段誉
     * @date 2022/9/22 16:35
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    /** 租户端编辑身份由登录上下文确定，客户端不提交 tenant_id。 */
    public function sceneSetInfo()
    {
        return $this->only(['id', 'field', 'value']);
    }

    /**
     * @notes 修改用户密码场景
     * @return UserValidate
     */
    public function sceneResetPassword()
    {
        return $this->only(['id', 'password', 'password_confirm']);
    }

    /**
     * @notes 设置用户会员套餐场景
     * @return UserValidate
     */
    public function sceneSetMembership()
    {
        return $this->only(['id', 'plan_id']);
    }


    /**
     * @notes 用户信息校验
     * @param $value
     * @param $rule
     * @param $data
     * @return bool|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/22 17:03
     */
    public function checkUser($value, $rule, $data)
    {
        $userIds = is_array($value) ? $value : [$value];

        foreach ($userIds as $item) {
            if (!User::find($item)) {
                return '用户不存在！';
            }
        }
        return true;
    }

    /**
     * @notes 校验平台端查询用户信息的情况
     * @param $value
     * @param $rule
     * @param $data
     * @return UserValidate
     * @author yfdong
     * @date 2024/09/04 23:46
     */
    public function sceneManager(){
        return $this->only(['tenant_id']);
    }



    /**
     * @notes 校验是否可更新信息
     * @param $value
     * @param $rule
     * @param $data
     * @return bool|string
     * @author 段誉
     * @date 2022/9/22 16:37
     */
    public function checkField($value, $rule, $data)
    {
        $allowField = ['account', 'sex', 'mobile', 'real_name'];

        if (!in_array($value, $allowField)) {
            return '用户信息不允许更新';
        }

        switch ($value) {
            case 'account':
                //验证手机号码是否存在
                $account = User::where([
                    ['id', '<>', $data['id']],
                    ['account', '=', $data['value']]
                ])->findOrEmpty();

                if (!$account->isEmpty()) {
                    return '账号已被使用';
                }
                break;

            case 'mobile':
                if (false == $this->validate($data['value'], 'mobile', $data)) {
                    return '手机号码格式错误';
                }

                //验证手机号码是否存在
                $mobile = User::where([
                    ['id', '<>', $data['id']],
                    ['mobile', '=', $data['value']]
                ])->findOrEmpty();

                if (!$mobile->isEmpty()) {
                    return '手机号码已存在';
                }
                break;
        }
        return true;
    }


}
