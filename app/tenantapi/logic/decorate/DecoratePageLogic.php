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
use app\common\model\decorate\DecoratePage;
use app\common\service\decorate\DecorateTemplateService;


/**
 * 装修页面
 * Class DecoratePageLogic
 * @package app\tenantapi\logic\theme
 */
class DecoratePageLogic extends BaseLogic
{


    /**
     * @notes 获取详情
     * @param $id
     * @return array
     * @author 段誉
     * @date 2022/9/14 18:41
     */
    public static function getDetail($params)
    {
        $id = (int)($params['id'] ?? 0);
        $type = (int)($params['type'] ?? 0);
        if ($type <= 0 && $id > 0) {
            $type = $id;
        }

        return self::getOrCreatePage($type)->toArray();
    }


    /**
     * @notes 保存装修配置
     * @param $params
     * @return bool
     * @author 段誉
     * @date 2022/9/15 9:37
     */
    public static function save($params)
    {
        $type = (int)($params['type'] ?? 0);
        $pageData = DecoratePage::where(['id' => (int)$params['id']])->findOrEmpty();
        if ($pageData->isEmpty() && $type > 0) {
            $pageData = DecoratePage::where(['type' => $type])->findOrEmpty();
        }
        if ($pageData->isEmpty()) {
            $pageData = self::getOrCreatePage($type);
        }

        $pageData->type = $type;
        $pageData->data = $params['data'];
        $pageData->meta = $params['meta'] ?? '';
        $pageData->save();
        if (isset(request()->tenantId)) {
            DecorateTemplateService::ensureDefaultTemplate((int)request()->tenantId);
        }
        return true;
    }

    private static function getOrCreatePage(int $type): DecoratePage
    {
        if ($type <= 0) {
            $type = 1;
        }

        $page = DecoratePage::where(['type' => $type])->findOrEmpty();
        if (!$page->isEmpty()) {
            return $page;
        }

        $template = DecoratePage::withoutGlobalScope()
            ->where(['tenant_id' => 0, 'type' => $type])
            ->findOrEmpty();

        $data = [
            'tenant_id' => (int)(request()->tenantId ?? 0),
            'type' => $type,
            'name' => self::defaultName($type),
            'data' => '[]',
            'meta' => '',
        ];
        if (!$template->isEmpty()) {
            $data['name'] = $template['name'];
            $data['data'] = $template['data'];
            $data['meta'] = $template['meta'];
        }

        return DecoratePage::create($data);
    }

    private static function defaultName(int $type): string
    {
        $names = [
            1 => '系统首页',
            2 => '个人中心',
            3 => '客服设置',
            4 => 'PC设置',
            5 => '系统风格',
        ];
        return $names[$type] ?? $names[1];
    }



}
