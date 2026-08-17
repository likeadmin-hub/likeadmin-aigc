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

namespace app\platformapi\validate\setting;

use app\common\validate\BaseValidate;

/**
 * 网站设置验证器
 * Class WebSettingValidate
 * @package app\platformapi\validate\setting
 */
class WebSettingValidate extends BaseValidate
{
    protected $rule = [
        'name' => 'require|max:30',
        'web_favicon' => 'require',
        'web_logo_light' => 'require',
        'web_logo_dark' => 'require',
        'login_image' => 'require',
        'point_unit' => 'max:12',
        'copyright_config' => 'array',
        'enabled' => 'require|in:0,1',
        'url' => 'requireIf:enabled,1|max:1000|checkTutorialUrl',
        'icon' => 'max:500',
    ];

    protected $message = [
        'name.require' => '请填写网站名称',
        'name.max' => '网站名称最长为12个字符',
        'web_favicon.require' => '请上传网站图标',
        'web_logo_light.require' => '请上传网站亮色主题logo',
        'web_logo_dark.require' => '请上传网站暗色主题logo',
        'login_image.require' => '请上传登录页广告图',
        'enabled.require' => '请选择是否启用新手教程',
        'enabled.in' => '新手教程启用状态不正确',
        'url.requireIf' => '启用新手教程后必须填写教程链接',
        'url.max' => '新手教程链接最长为1000个字符',
        'icon.max' => '新手教程图标地址最长为500个字符',
    ];

    protected $scene = [
        'website' => ['name', 'web_favicon', 'web_logo_light','web_logo_dark', 'login_image', 'point_unit', 'copyright_config', 'shop_name', 'shop_logo', 'pc_logo'],
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
