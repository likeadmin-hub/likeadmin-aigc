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
use app\common\service\storage\StorageConfigService;
use app\common\service\tenant\TenantContractService;
use app\common\service\tenant\TenantDomainAliasService;
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
        $domain_alias = TenantUrlService::normalizeHost((string)($params['domain_alias'] ?? ''));
        $aliases = (array)($params['domain_aliases'] ?? []);
        $sn = $params['host_name'] ?? Tenant::createUserSn();
        $exists = (new Tenant())->where('sn', $sn)->find();
        if (!empty($exists)) {
            throw new Exception('主机名已被占用，请更换');
        }
        $normalizedAliases = TenantDomainAliasService::normalizeAliasList($aliases, $domain_alias);
        TenantDomainAliasService::validateAliases($normalizedAliases, 0, true);
        $domain_alias = (string)($normalizedAliases[0]['domain'] ?? $domain_alias);
        $expireTime = self::normalizeTime($params['contract_expire_time'] ?? 0);
        $contractStatus = $expireTime > 0
            ? ($expireTime > time() ? TenantContractService::STATUS_ACTIVE : TenantContractService::STATUS_EXPIRED)
            : TenantContractService::STATUS_UNSIGNED;
        $tenant = Tenant::create([
            'sn'                  => $sn,
            'name'                => $params['name'],
            'avatar'              => $params['avatar'],
            'tel'                 => $params['tel'],
            'domain_alias'        => $domain_alias,
            'domain_alias_enable' => 0,
            'access_mode'         => TenantUrlService::ACCESS_ALIAS,
            'disable'             => $params['disable'] ?? 0,
            'notes'               => $params['notes'] ?? '',
            'tactics'             => 0,
            'allow_custom_storage' => $params['allow_custom_storage'] ?? 0,
            'allow_local_storage'  => $params['allow_local_storage'] ?? 1,
            'contract_package_id'  => (int)($params['contract_package_id'] ?? 0),
            'contract_package_name' => (string)($params['contract_package_name'] ?? ''),
            'contract_start_time'  => (int)($params['contract_start_time'] ?? time()),
            'contract_expire_time' => $expireTime,
            'contract_renew_time'  => (int)($params['contract_renew_time'] ?? time()),
            'contract_status'      => $contractStatus,
            'source_tenant_id'     => (int)($params['source_tenant_id'] ?? 0),
            'parent_tenant_id'     => (int)($params['parent_tenant_id'] ?? 0),
        ]);
        $primaryAlias = TenantDomainAliasService::syncTenantAliases((int)$tenant['id'], $normalizedAliases, $domain_alias, true);
        if ($primaryAlias !== $domain_alias) {
            $tenant->save(['domain_alias' => $primaryAlias]);
        }
        return $tenant;
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
            $field = "id,sn,name,avatar,tel,domain_alias,domain_alias_enable,access_mode,disable,create_time,notes,allow_custom_storage,allow_local_storage,point_balance,contract_package_id,contract_package_name,contract_start_time,contract_expire_time,contract_renew_time,contract_status,parent_tenant_id,source_tenant_id";

            $user = Tenant::where(['id' => $userId])->field($field)->findOrEmpty();
            $user['user_total'] = User::where(['tenant_id' => $userId])->count();
            $user['domain_aliases'] = TenantDomainAliasService::getAliasesByTenantId($userId);
            $user['contract_summary'] = TenantContractService::summary($user);

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
            $tenantId = (int)$params['id'];
            $domain_alias = TenantUrlService::normalizeHost((string)($params['domain_alias'] ?? ''));
            $aliases = (array)($params['domain_aliases'] ?? []);
            $domain_alias = TenantDomainAliasService::syncTenantAliases($tenantId, $aliases, $domain_alias, true);
        $expireTime = self::normalizeTime($params['contract_expire_time'] ?? 0);
            $contractStatus = $expireTime > 0
                ? ($expireTime > time() ? TenantContractService::STATUS_ACTIVE : TenantContractService::STATUS_EXPIRED)
                : TenantContractService::STATUS_UNSIGNED;
            Tenant::update([
                'name'                => $params['name'],
                'avatar'              => $params['avatar'],
                'disable'             => $params['disable'] ?? 0,
                'tel'                 => $params['tel'],
                'domain_alias'        => $domain_alias,
                'domain_alias_enable' => 0,
                'access_mode'         => TenantUrlService::ACCESS_ALIAS,
                'notes'               => $params['notes'] ?? '',
                'allow_custom_storage' => $params['allow_custom_storage'] ?? 0,
                'allow_local_storage'  => $params['allow_local_storage'] ?? 1,
                'contract_package_id'  => (int)($params['contract_package_id'] ?? 0),
                'contract_package_name' => (string)($params['contract_package_name'] ?? ''),
                'contract_start_time'  => (int)($params['contract_start_time'] ?? time()),
                'contract_expire_time' => $expireTime,
                'contract_renew_time'  => (int)($params['contract_renew_time'] ?? time()),
                'contract_status'      => $contractStatus,
                'source_tenant_id'     => (int)($params['source_tenant_id'] ?? 0),
                'parent_tenant_id'     => (int)($params['parent_tenant_id'] ?? 0),
            ], ['id' => $params['id']]);
            StorageConfigService::clearCache($tenantId);
            self::syncCustomStorageMenu($tenantId, (int)($params['allow_custom_storage'] ?? 0) === 1);
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

    public static function normalizeDomainHost(string $domain): string
    {
        return TenantUrlService::normalizeHost($domain);
    }

    private static function normalizeTime(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        $time = strtotime($value);
        return $time ? (int)$time : 0;
    }
}
