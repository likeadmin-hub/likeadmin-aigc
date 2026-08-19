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

namespace app\tenantapi\validate\setting;

use app\common\validate\BaseValidate;

/**
 * 网站设置验证器
 * Class WebSettingValidate
 * @package app\tenantapi\validate\setting
 */
class WebSettingValidate extends BaseValidate
{
    protected $rule = [
        'name' => 'require|max:30',
        'web_favicon' => 'require',
        'web_logo' => 'require',
        'login_image' => 'max:500',
        'shop_name' => 'require',
        'shop_logo' => 'require',
        'h5_favicon' => 'require',
        'pc_logo' => 'require',
        'pc_title' => 'require|max:30',
        'pc_ico' => 'require',
        'pc_desc' => 'max:255',
        'pc_keywords' => 'max:255',
        'pc_login_bg_type' => 'in:image,video,none',
        'pc_login_bg' => 'max:500',
        'pc_login_bg_poster' => 'max:500',
        'pc_home_style' => 'in:default,immersive',
        'pc_home_bg_type' => 'in:image,video,none',
        'pc_home_bg' => 'array',
        'pc_home_bg_poster' => 'array',
        'pc_home_immersive_title' => 'max:80',
        'pc_home_immersive_subtitle' => 'max:120',
        'enabled' => 'require|in:0,1',
        'url' => 'requireIf:enabled,1|max:1000|checkTutorialUrl',
        'icon' => 'max:500',
    ];

    protected $message = [
        'name.require' => '请填写网站名称',
        'name.max' => '网站名称最长为12个字符',
        'web_favicon.require' => '请上传网站图标',
        'web_logo.require' => '请上传网站logo',
        'shop_name.require' => '请填写前台名称',
        'shop_logo.require' => '请上传前台logo',
        'h5_favicon.require' => '请上传前台网站图标',
        'pc_logo.require' => '请上传PC端logo',
        'pc_title.require' => '请填写PC端网站标题',
        'pc_ico.require' => '请上传PC端网站图标',
        'pc_login_bg_type.in' => '请选择正确的PC登录背景类型',
        'pc_home_style.in' => '请选择正确的PC首页风格',
        'pc_home_bg_type.in' => '请选择正确的PC首页背景类型',
        'pc_home_immersive_title.max' => '首页大标题最长为80个字符',
        'pc_home_immersive_subtitle.max' => '首页小标题最长为120个字符',
        'enabled.require' => '请选择是否启用新手教程',
        'enabled.in' => '新手教程启用状态不正确',
        'url.requireIf' => '启用新手教程后必须填写教程链接',
        'url.max' => '新手教程链接最长为1000个字符',
        'icon.max' => '新手教程图标地址最长为500个字符',
    ];

    protected $scene = [
        'website' => ['name', 'web_favicon', 'web_logo', 'login_image', 'shop_name', 'shop_logo', 'h5_favicon', 'pc_logo', 'pc_title', 'pc_ico', 'pc_desc', 'pc_keywords', 'pc_login_bg_type', 'pc_login_bg', 'pc_login_bg_poster', 'pc_home_style', 'pc_home_bg_type', 'pc_home_bg', 'pc_home_bg_poster', 'pc_home_immersive_title', 'pc_home_immersive_subtitle'],
        'siteStatistics' => [''],
        'tutorial' => ['enabled', 'url', 'icon'],
    ];

    public function checkTutorialUrl($value): bool|string
    {
        $url = trim((string)$value);
        if ($url === '') {
            return true;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '请输入有效的新手教程链接';
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ?: '新手教程链接仅支持 http 或 https';
    }
}
