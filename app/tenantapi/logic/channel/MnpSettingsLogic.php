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

namespace app\tenantapi\logic\channel;

use app\common\logic\BaseLogic;
use app\common\model\tenant\Tenant;
use app\common\service\ConfigService;
use app\common\service\FileService;
use app\common\service\tenant\TenantUrlService;

/**
 * 小程序设置逻辑
 * Class MnpSettingsLogic
 * @package app\tenantapi\logic\channel
 */
class MnpSettingsLogic extends BaseLogic
{
    /**
     * @notes 获取小程序配置
     * @return array
     * @author ljj
     * @date 2022/2/16 9:38 上午
     */
    public function getConfig()
    {
        $domainName = $this->resolveTenantDomain();
        $qrCode = ConfigService::get('mnp_setting', 'qr_code', '');
        $qrCode = empty($qrCode) ? $qrCode : FileService::getFileUrl($qrCode);
        $config = [
            'name'                  => ConfigService::get('mnp_setting', 'name', ''),
            'original_id'           => ConfigService::get('mnp_setting', 'original_id', ''),
            'qr_code'               => $qrCode,
            'app_id'                => ConfigService::get('mnp_setting', 'app_id', ''),
            'app_secret'            => ConfigService::get('mnp_setting', 'app_secret', ''),
            'request_domain'        => 'https://'.$domainName,
            'socket_domain'         => 'wss://'.$domainName,
            'upload_file_domain'     => 'https://'.$domainName,
            'download_file_domain'   => 'https://'.$domainName,
            'udp_domain'            => 'udp://'.$domainName,
            'business_domain'       => $domainName,
        ];

        return $config;
    }

    /**
     * Resolve the host that the current tenant's mini-program should call.
     * The tenant API can run behind a shared reverse proxy, so SERVER_NAME is
     * not a reliable source and may point at the platform host.
     */
    private function resolveTenantDomain(): string
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId > 0) {
            $tenant = Tenant::where('id', $tenantId)->findOrEmpty();
            if (!$tenant->isEmpty()) {
                $tenantData = $tenant->toArray();
                $links = TenantUrlService::links($tenantData);
                $aliasHost = TenantUrlService::normalizeHost((string)($links['alias']['pc'] ?? ''));
                if ($aliasHost !== '') {
                    return $aliasHost;
                }
                $subdomainHost = TenantUrlService::normalizeHost((string)($links['subdomain']['pc'] ?? ''));
                if ($subdomainHost !== '') {
                    return $subdomainHost;
                }
            }
        }

        return TenantUrlService::normalizeHost((string)(
            $_SERVER['HTTP_HOST'] ?? request()->domain() ?? $_SERVER['SERVER_NAME'] ?? ''
        ));
    }

    /**
     * @notes 设置小程序配置
     * @param $params
     * @author ljj
     * @date 2022/2/16 9:51 上午
     */
    public function setConfig($params)
    {
        $qrCode = isset($params['qr_code']) ? FileService::setFileUrl($params['qr_code']) : '';

        ConfigService::set('mnp_setting','name', $params['name'] ?? '');
        ConfigService::set('mnp_setting','original_id',$params['original_id'] ?? '');
        ConfigService::set('mnp_setting','qr_code',$qrCode);
        ConfigService::set('mnp_setting','app_id',$params['app_id']);
        ConfigService::set('mnp_setting','app_secret',$params['app_secret']);
    }
}
