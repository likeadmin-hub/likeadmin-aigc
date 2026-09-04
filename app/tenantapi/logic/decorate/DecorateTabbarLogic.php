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
namespace app\tenantapi\logic\decorate;

use app\common\logic\BaseLogic;
use app\common\model\decorate\DecorateTabbar;
use app\common\service\decorate\DecorateTemplateService;
use app\common\service\ConfigService;
use app\common\service\FileService;


/**
 * 装修配置-底部导航
 * Class DecorateTabbarLogic
 * @package app\tenantapi\logic\decorate
 */
class DecorateTabbarLogic extends BaseLogic
{

    /**
     * @notes 获取底部导航详情
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/7 16:58
     */
    public static function detail(): array
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        $templateId = (int)request()->get('template_id/d', request()->get('id/d', 0));
        if ($templateId > 0) {
            // Template settings are the source of truth for the new editor.
            // Keep the legacy table fallback inside the template service so an
            // older template can still be opened without losing its tabbar.
            $templateDetail = DecorateTemplateService::detail($tenantId, $templateId, DecorateTemplateService::TERMINAL_MOBILE);
            $tabbar = $templateDetail['settings']['mobile_tabbar'] ?? [];
            if (is_array($tabbar) && (!empty($tabbar['list']) || !empty($tabbar['style']))) {
                return [
                    'style' => (array)($tabbar['style'] ?? []),
                    'list' => (array)($tabbar['list'] ?? []),
                ];
            }
        }
        $list = DecorateTabbar::getTabbarLists($tenantId);
        $style = ConfigService::get('tabbar', 'style', config('project.decorate.tabbar_style'));
        return ['style' => $style, 'list' => $list];
    }


    /**
     * @notes 底部导航保存
     * @param $params
     * @return bool
     * @throws \Exception
     * @author 段誉
     * @date 2022/9/7 17:19
     */
    public static function save($params): bool
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) {
            throw new \RuntimeException('租户上下文无效');
        }
        $templateId = (int)($params['template_id'] ?? $params['id'] ?? 0);
        $tabbars = $params['list'] ?? [];
        $style = $params['style'] ?? [];

        if ($templateId > 0) {
            // A template owns its draft tabbar. Do not write the global legacy
            // table/config here: doing so would make editing an inactive
            // template change another template's effective settings.
            DecorateTemplateService::saveSettings($tenantId, $templateId, [
                'mobile_tabbar' => [
                    'style' => $style,
                    'list' => $tabbars,
                ],
            ]);
            return true;
        }

        $model = new DecorateTabbar();
        // 删除旧配置数据
        $model->where(['tenant_id' => $tenantId])->delete();

        // 保存数据
        $data = [];
        foreach ($tabbars as $item) {
            $data[] = [
                'name' => $item['name'],
                'selected' => FileService::setFileUrl($item['selected']),
                'unselected' => FileService::setFileUrl($item['unselected']),
                'link' => $item['link'],
                'is_show' => $item['is_show'] ?? 0,
                'tenant_id' => $tenantId,
            ];
        }
        $model->saveAll($data);

        if (!empty($style)) {
            ConfigService::set('tabbar', 'style', $style);
        }
        return true;
    }

}
