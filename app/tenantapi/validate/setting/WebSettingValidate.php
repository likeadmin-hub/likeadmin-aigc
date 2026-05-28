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
        'pc_logo' => 'require',
        'pc_login_bg_type' => 'in:image,video,none',
        'pc_login_bg' => 'max:500',
        'pc_login_bg_poster' => 'max:500',
    ];

    protected $message = [
        'name.require' => '请填写网站名称',
        'name.max' => '网站名称最长为12个字符',
        'web_favicon.require' => '请上传网站图标',
        'web_logo.require' => '请上传网站logo',
        'shop_name.require' => '请填写前台名称',
        'shop_logo.require' => '请上传前台logo',
        'pc_logo.require' => '请上传PC端logo',
        'pc_login_bg_type.in' => '请选择正确的PC登录背景类型',
    ];

    protected $scene = [
        'website' => ['name', 'web_favicon', 'web_logo', 'login_image', 'shop_name', 'shop_logo', 'pc_logo', 'pc_login_bg_type', 'pc_login_bg', 'pc_login_bg_poster'],
        'siteStatistics' => [''],
    ];
}
