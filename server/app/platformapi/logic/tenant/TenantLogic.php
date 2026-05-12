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
namespace app\platformapi\logic\tenant;

use app\common\enum\user\UserTerminalEnum;
use app\common\logic\BaseLogic;
use app\common\model\tenant\Tenant;
use app\common\model\user\User;
use app\common\service\tenant\TenantUrlService;
use Exception;
use think\facade\Db;

/**
 * 用户逻辑层
 * Class TenantLogic
 * @package app\platformapi\logic\user
 */
class TenantLogic extends BaseLogic
{
    /**
     * @notes 新增租户
     * @param array $params
     * @return Tenant|\think\Model
     * @throws Exception
     * @author JXDN
     * @date 2024/09/03 14:42
     */
    public static function add(array $params)
    {
        $domain_alias = preg_replace('/^https?:\/\/|\/$/', '', (string)($params['domain_alias'] ?? ''));
        $accessMode = TenantUrlService::normalizeAccessMode((string)($params['access_mode'] ?? TenantUrlService::ACCESS_SUBDOMAIN));
        $sn = $params['host_name'] ?? Tenant::createUserSn();
        $exists = (new Tenant())->where('sn', $sn)->find();
        if (!empty($exists)) {
            throw new Exception('主机名已被占用，请更换');
        }
        return Tenant::create([
            'sn'                  => $sn,
            'name'                => $params['name'],
            'avatar'              => $params['avatar'],
            'tel'                 => $params['tel'],
            'domain_alias'        => $domain_alias,
            'domain_alias_enable' => $accessMode === TenantUrlService::ACCESS_ALIAS ? 0 : 1,
            'access_mode'         => $accessMode,
            'disable'             => $params['disable'] ?? 0,
            'notes'               => $params['notes'] ?? '',
            'tactics'             => $params['tactics'] ?? 0,
            'allow_custom_storage' => $params['allow_custom_storage'] ?? 0,
        ]);
    }

    /**
     * @notes 用户详情
     * @param int $userId
     * @return array|false
     * @author JXDN
     * @date 2024/09/11 15:48
     */
    public static function detail(int $userId)
    {
        try {
            $field = "id,sn,name,avatar,tel,domain_alias,domain_alias_enable,access_mode,disable,create_time,notes,allow_custom_storage,point_balance";

            $user = Tenant::where(['id' => $userId])->field($field)->findOrEmpty();
            $user['user_total'] = User::where(['tenant_id' => $userId])->count();

            $domain = TenantUrlService::tenantRootDomain(request()->domain());
            return TenantUrlService::attach($user->toArray(), $domain);
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }

    }

    /**
     * @notes 更新租户信息
     * @param array $params
     * @return bool
     * @author JXDN
     * @date 2024/09/03 14:28
     */
    public static function edit(array $params)
    {
        try {
            $domain_alias = preg_replace('/^https?:\/\/|\/$/', '', (string)($params['domain_alias'] ?? ''));
            $accessMode = TenantUrlService::normalizeAccessMode((string)($params['access_mode'] ?? TenantUrlService::ACCESS_SUBDOMAIN));
            Tenant::update([
                'name'                => $params['name'],
                'avatar'              => $params['avatar'],
                'disable'             => $params['disable'] ?? 0,
                'tel'                 => $params['tel'],
                'domain_alias'        => $domain_alias,
                'domain_alias_enable' => $accessMode === TenantUrlService::ACCESS_ALIAS ? 0 : 1,
                'access_mode'         => $accessMode,
                'notes'               => $params['notes'] ?? '',
                'allow_custom_storage' => $params['allow_custom_storage'] ?? 0,
            ], ['id' => $params['id']]);
            self::syncCustomStorageMenu((int)$params['id'], (int)($params['allow_custom_storage'] ?? 0) === 1);
            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function syncCustomStorageMenu(int $tenantId, bool $allow): void
    {
        $tenant = Tenant::where('id', $tenantId)->field('id,sn')->findOrEmpty();
        if ($tenant->isEmpty()) {
            return;
        }
        $isShow = $allow ? 1 : 0;
        $keys = [
            'core_tenant_storage',
            'core_tenant_storage_setup',
            'core_tenant_storage_change',
            'core_tenant_storage_detail',
        ];
        Db::name('tenant_system_menu')
            ->where('tenant_id', $tenantId)
            ->whereIn('source_menu_key', $keys)
            ->update(['is_show' => $isShow, 'update_time' => time()]);

        $table = 'tenant_system_menu_' . $tenant['sn'];
        try {
            Db::name($table)
                ->whereIn('source_menu_key', $keys)
                ->update(['is_show' => $isShow, 'update_time' => time()]);
        } catch (\Throwable) {
        }
    }

    /**
     * @notes 删除租户
     * @param array $params
     * @return bool
     * @author JXDN
     * @date 2024/09/03 17:04
     */
    public static function delete(array $params)
    {
        try {
            Tenant::destroy($params['id']);
            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    /**
     * @notes 检查是否为https
     * @return bool
     * @author JXDN
     * @date 2024/09/11 14:39
     */
    public static function checkHttp()
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            return true;
        } else {
            return false;
        }
    }

    public static function getRootDmain($url)
    {
        return TenantUrlService::tenantRootDomain($url);
    }
}
