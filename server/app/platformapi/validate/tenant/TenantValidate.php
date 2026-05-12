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
namespace app\platformapi\validate\tenant;


use app\common\model\tenant\Tenant;
use app\common\service\tenant\TenantUrlService;
use app\common\validate\BaseValidate;

/**
 * 用户验证
 * Class TenantValidate
 * @package app\platformapi\validate\user
 */
class TenantValidate extends BaseValidate
{

    protected $rule = [
        'id'   => 'require|checkUser',
        'name' => 'require',
        'access_mode' => 'checkAccessMode',
        'domain_alias' => 'checkDomainAlias'
    ];

    protected $message = [
        'id.require'   => '请选择用户',
        'name.require' => '请输入用户名',
    ];


    /**
     * @notes 详情场景
     * @return TenantValidate
     * @author 段誉
     * @date 2022/9/22 16:35
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 租户信息校验
     * @param $value
     * @param $rule
     * @param $data
     * @return bool|string
     * @author 段誉
     * @date 2022/9/22 17:03
     */
    public function checkUser($value, $rule, $data)
    {
        $userIds = Tenant::findOrEmpty($value);
        if ($userIds->isEmpty()) {
            return '租户不存在';
        }
        return true;
    }

    /**
     * @notes 域名校验
     * @param $value
     * @param $rule
     * @param $data
     * @return string|true
     * @author JXDN
     * @date 2024/09/11 15:30
     */
    public function checkDomainAlias($value, $rule, $data)
    {
        $value = $this->normalizeDomainAlias($value);
        if (!$this->isAliasRequired($data) && $value === '') {
            return true;
        }
        if ($this->isAliasRequired($data) && $value === '') {
            return '请设置域名别名';
        }
        $tenant = Tenant::where(['domain_alias' => $value])->findOrEmpty();
        if (!$tenant->isEmpty()) {
            return '租户别名已存在';
        }
        return true;
    }

    /**
     * @notes 域名校验
     * @param $value
     * @param $rule
     * @param $data
     * @return string|true
     * @author JXDN
     * @date 2024/09/11 15:30
     */
    public function checkDomainAliasEdit($value, $rule, $data)
    {
        $value = $this->normalizeDomainAlias($value);
        if (!$this->isAliasRequired($data) && $value === '') {
            return true;
        }
        if ($this->isAliasRequired($data) && $value === '') {
            return '请设置域名别名';
        }
        $tenant = Tenant::where('domain_alias', $value)
            ->where('id', '<>', $data['id']) // 排除当前租户
            ->findOrEmpty();
        if (!$tenant->isEmpty()) {
            return '租户别名已存在';
        }
        return true;
    }

    public function checkAccessMode($value, $rule, $data)
    {
        if ($value === null || $value === '') {
            return true;
        }
        return in_array((string)$value, TenantUrlService::ACCESS_MODES, true) ? true : '访问方式错误';
    }

    private function isAliasRequired(array $data): bool
    {
        return ($data['access_mode'] ?? '') === TenantUrlService::ACCESS_ALIAS
            || (int)($data['domain_alias_enable'] ?? 1) === 0;
    }

    private function normalizeDomainAlias($value): string
    {
        $value = preg_replace('/^https?:\/\/|\/$/', '', (string)$value);
        return trim((string)$value);
    }


    /**
     * @notes 添加场景
     * @return TenantValidate
     * @author 段誉
     * @date 2022/5/25 18:16
     */
    public function sceneAdd()
    {
        return $this->remove('id', true);
    }

    /**
     * @notes 编辑场景
     * @return TenantValidate
     * @author JXDN
     * @date 2024/09/11 15:31
     */
    public function sceneEdit()
    {
        return $this->only(['id', 'name', 'access_mode'])->append('domain_alias', 'checkDomainAliasEdit');
    }

    /**
     * @notes 删除场景
     * @return TenantValidate
     * @author 段誉
     * @date 2022/5/25 18:16
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }
}
