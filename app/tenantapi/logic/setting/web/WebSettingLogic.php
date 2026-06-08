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

namespace app\tenantapi\logic\setting\web;


use app\common\logic\BaseLogic;
use app\common\service\AgreementService;
use app\common\service\ConfigService;
use app\common\service\FileService;


/**
 * 网站设置
 * Class WebSettingLogic
 * @package app\tenantapi\logic\setting
 */
class WebSettingLogic extends BaseLogic
{

    /**
     * @notes 获取网站信息
     * @return array
     * @author 段誉
     * @date 2021/12/28 15:43
     */
    public static function getWebsiteInfo(): array
    {
        $pcLoginBg = ConfigService::get('website', 'pc_login_bg', ConfigService::get('tenant', 'login_image', ''));
        $pcLoginBgPoster = ConfigService::get('website', 'pc_login_bg_poster', '');
        $pcHomeBg = ConfigService::get('website', 'pc_home_bg', '');
        $pcHomeBgPoster = ConfigService::get('website', 'pc_home_bg_poster', '');
        $pcHomeBgList = self::fileUrlList($pcHomeBg);
        $pcHomeBgPosterList = self::fileUrlList($pcHomeBgPoster);
        $pcHomeImmersiveTitle = ConfigService::get('website', 'pc_home_immersive_title', 'OPC社区专属，AI创业平台');
        $pcHomeImmersiveSubtitle = ConfigService::get('website', 'pc_home_immersive_subtitle', '一个人就是一支团队');
        return [
            'name' => ConfigService::get('tenant', 'name'),
            'web_favicon' => FileService::getFileUrl(ConfigService::get('tenant', 'web_favicon')),
            'web_logo' => FileService::getFileUrl(ConfigService::get('tenant', 'web_logo')),
            'login_image' => FileService::getFileUrl(ConfigService::get('tenant', 'login_image')),
            
            'shop_name' => ConfigService::get('website', 'shop_name'),
            'shop_logo' => FileService::getFileUrl(ConfigService::get('website', 'shop_logo')),

            'pc_logo' => FileService::getFileUrl(ConfigService::get('website', 'pc_logo')),
            'pc_title' => ConfigService::get('website', 'pc_title', ''),
            'pc_ico' => FileService::getFileUrl(ConfigService::get('website', 'pc_ico')),
            'pc_desc' => ConfigService::get('website', 'pc_desc', ''),
            'pc_keywords' => ConfigService::get('website', 'pc_keywords', ''),
            'pc_login_bg_type' => ConfigService::get('website', 'pc_login_bg_type', 'image'),
            'pc_login_bg' => $pcLoginBg ? FileService::getFileUrl($pcLoginBg) : '',
            'pc_login_bg_url' => $pcLoginBg ? FileService::getFileUrl($pcLoginBg) : '',
            'pc_login_bg_poster' => $pcLoginBgPoster ? FileService::getFileUrl($pcLoginBgPoster) : '',
            'pc_login_bg_poster_url' => $pcLoginBgPoster ? FileService::getFileUrl($pcLoginBgPoster) : '',
            'pc_home_style' => ConfigService::get('website', 'pc_home_style', 'default'),
            'pc_home_bg_type' => ConfigService::get('website', 'pc_home_bg_type', 'none'),
            'pc_home_bg' => $pcHomeBgList,
            'pc_home_bg_url' => $pcHomeBgList,
            'pc_home_bg_poster' => $pcHomeBgPosterList,
            'pc_home_bg_poster_url' => $pcHomeBgPosterList,
            'pc_home_immersive_title' => $pcHomeImmersiveTitle,
            'pc_home_immersive_subtitle' => $pcHomeImmersiveSubtitle,
            'h5_favicon' => FileService::getFileUrl(ConfigService::get('website', 'h5_favicon')),
        ];
    }


    /**
     * @notes 设置网站信息
     * @param array $params
     * @author 段誉
     * @date 2021/12/28 15:43
     */
    public static function setWebsiteInfo(array $params)
    {
        $h5favicon = FileService::setFileUrl($params['h5_favicon']);
        $favicon = FileService::setFileUrl($params['web_favicon']);
        $logo = FileService::setFileUrl($params['web_logo']);
        $login = FileService::setFileUrl($params['login_image']);
        $shopLogo = FileService::setFileUrl($params['shop_logo']);
        $pcLogo = FileService::setFileUrl($params['pc_logo']);
        $pcIco = FileService::setFileUrl($params['pc_ico'] ?? '');
        $pcLoginBgType = $params['pc_login_bg_type'] ?? 'image';
        if (!in_array($pcLoginBgType, ['image', 'video', 'none'], true)) {
            $pcLoginBgType = 'image';
        }
        $pcLoginBg = $pcLoginBgType === 'none' ? '' : FileService::setFileUrl($params['pc_login_bg'] ?? '');
        $pcLoginBgPoster = FileService::setFileUrl($params['pc_login_bg_poster'] ?? '');
        $pcHomeStyle = $params['pc_home_style'] ?? 'default';
        if (!in_array($pcHomeStyle, ['default', 'immersive'], true)) {
            $pcHomeStyle = 'default';
        }
        $pcHomeBgType = $params['pc_home_bg_type'] ?? 'none';
        if (!in_array($pcHomeBgType, ['image', 'video', 'none'], true)) {
            $pcHomeBgType = 'none';
        }
        $pcHomeBg = $pcHomeBgType === 'none' ? [] : self::setFileList($params['pc_home_bg'] ?? []);
        $pcHomeBgPoster = $pcHomeBgType === 'video'
            ? self::setFileList($params['pc_home_bg_poster'] ?? [])
            : [];

        ConfigService::set('tenant', 'name', $params['name']);
        ConfigService::set('tenant', 'web_favicon', $favicon);
        ConfigService::set('tenant', 'web_logo', $logo);
        ConfigService::set('tenant', 'login_image', $login);

        ConfigService::set('website', 'pc_logo', $pcLogo);
        ConfigService::set('website', 'pc_title', $params['pc_title']);
        ConfigService::set('website', 'pc_ico', $pcIco);
        ConfigService::set('website', 'pc_desc', $params['pc_desc'] ?? '');
        ConfigService::set('website', 'pc_keywords', $params['pc_keywords'] ?? '');
        ConfigService::set('website', 'pc_login_bg_type', $pcLoginBgType);
        ConfigService::set('website', 'pc_login_bg', $pcLoginBg);
        ConfigService::set('website', 'pc_login_bg_poster', $pcLoginBgPoster);
        ConfigService::set('website', 'pc_home_style', $pcHomeStyle);
        ConfigService::set('website', 'pc_home_bg_type', $pcHomeBgType);
        ConfigService::set('website', 'pc_home_bg', $pcHomeBg);
        ConfigService::set('website', 'pc_home_bg_poster', $pcHomeBgPoster);
        ConfigService::set('website', 'pc_home_immersive_title', $params['pc_home_immersive_title'] ?? 'OPC社区专属，AI创业平台');
        ConfigService::set('website', 'pc_home_immersive_subtitle', $params['pc_home_immersive_subtitle'] ?? '一个人就是一支团队');

        ConfigService::set('website', 'shop_name', $params['shop_name']);
        ConfigService::set('website', 'shop_logo', $shopLogo);
        ConfigService::set('website', 'h5_favicon', $h5favicon);
    }

    private static function normalizeFileList($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn($item) => is_string($item) && $item !== ''));
        }
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        return [];
    }

    private static function setFileList($value): array
    {
        return array_map(static fn($item) => FileService::setFileUrl($item), self::normalizeFileList($value));
    }

    private static function fileUrlList($value): array
    {
        return array_map(static fn($item) => FileService::getFileUrl($item), self::normalizeFileList($value));
    }


    /**
     * @notes 获取版权备案
     * @return array
     * @author 段誉
     * @date 2021/12/28 16:09
     */
    public static function getCopyright() : array
    {
        return ConfigService::get('copyright', 'config', []);
    }


    /**
     * @notes 设置版权备案
     * @param array $params
     * @return bool
     * @author 段誉
     * @date 2022/8/8 16:33
     */
    public static function setCopyright(array $params)
    {
        try {
            if (!is_array($params['config'])) {
                throw new \Exception('参数异常');
            }
            ConfigService::set('copyright', 'config', $params['config'] ?? []);
            return true;
        } catch (\Exception $e) {
            self::$error = $e->getMessage();
            return false;
        }
    }


    /**
     * @notes 设置政策协议
     * @param array $params
     * @author ljj
     * @date 2022/2/15 10:59 上午
     */
    public static function setAgreement(array $params)
    {
        AgreementService::saveAgreementConfig($params);
    }


    /**
     * @notes 获取政策协议
     * @return array
     * @author ljj
     * @date 2022/2/15 11:15 上午
     */
    public static function getAgreement() : array
    {
        return AgreementService::getAgreementConfig();
    }

    /**
     * @notes 获取站点统计配置
     * @return array
     * @author yfdong
     * @date 2024/09/20 22:25
     */
    public static function getSiteStatistics()
    {
        return [
            'clarity_code' => ConfigService::get('siteStatistics', 'clarity_code')
        ];
    }

    /**
     * @notes 设置站点统计配置
     * @param array $params
     * @return void
     * @author yfdong
     * @date 2024/09/20 22:31
     */
    public static function setSiteStatistics(array $params)
    {
        ConfigService::set('siteStatistics', 'clarity_code', $params['clarity_code']);
    }
}
