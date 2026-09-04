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
use app\common\model\decorate\DecorateTemplate;
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

        $page = self::getOrCreatePage($type)->toArray();
        // Legacy editor/detail callers should work on the draft snapshot just
        // like the new template editor. Keep the legacy fields as a fallback
        // for installations that predate draft/published columns.
        $page['data'] = $page['draft_data'] ?: $page['data'] ?: '[]';
        $page['meta'] = $page['draft_meta'] ?: $page['meta'] ?: '';
        return $page;
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
        $tenantId = (int)(request()->tenantId ?? 0);
        $type = (int)($params['type'] ?? 0);
        DecorateTemplateService::ensureDefaultTemplate($tenantId);
        $activeTemplate = DecorateTemplate::where([
            'tenant_id' => $tenantId,
            'is_active' => 1,
        ])->findOrEmpty();
        $pageData = DecoratePage::where([
            'tenant_id' => $tenantId,
            'id' => (int)$params['id'],
        ]);
        if (!$activeTemplate->isEmpty()) {
            $pageData->where('template_id', (int)$activeTemplate['id']);
        }
        $pageData = $pageData->findOrEmpty();
        if ($pageData->isEmpty() && $type > 0) {
            $pageQuery = DecoratePage::where([
                'tenant_id' => $tenantId,
                'type' => $type,
            ]);
            if (!$activeTemplate->isEmpty()) {
                $pageQuery->where('template_id', (int)$activeTemplate['id']);
            }
            $pageData = $pageQuery->findOrEmpty();
        }
        if ($pageData->isEmpty()) {
            $pageData = self::getOrCreatePage($type);
        }

        $pageData->type = $type;
        // Legacy routes remain available, but edits must stay in the draft
        // snapshot until the owning template is explicitly published.
        $pageData->draft_data = $params['data'];
        $pageData->draft_meta = $params['meta'] ?? '';
        $pageData->save();
        if ((int)($pageData['template_id'] ?? 0) > 0) {
            DecorateTemplateService::markDraft($tenantId, (int)$pageData['template_id']);
        }
        return true;
    }

    private static function getOrCreatePage(int $type): DecoratePage
    {
        if ($type <= 0) {
            $type = 1;
        }

        $tenantId = (int)(request()->tenantId ?? 0);
        // Legacy routes do not carry a template id. Resolve their page against
        // the tenant's active template first so an old type-based request can
        // never edit a page belonging to another template.
        DecorateTemplateService::ensureDefaultTemplate($tenantId);
        $activeTemplate = DecorateTemplate::where([
            'tenant_id' => $tenantId,
            'is_active' => 1,
        ])->findOrEmpty();
        $pageQuery = DecoratePage::where([
            'tenant_id' => $tenantId,
            'type' => $type,
        ]);
        if (!$activeTemplate->isEmpty()) {
            $pageQuery->where('template_id', (int)$activeTemplate['id']);
        }
        $page = $pageQuery->findOrEmpty();
        if (!$page->isEmpty()) {
            return $page;
        }

        $template = DecoratePage::withoutGlobalScope()
            ->where(['tenant_id' => 0, 'type' => $type])
            ->findOrEmpty();

        $data = [
            'tenant_id' => $tenantId,
            'type' => $type,
            'name' => self::defaultName($type),
            'data' => '[]',
            'meta' => '',
            'draft_data' => '[]',
            'draft_meta' => '',
            'published_data' => '[]',
            'published_meta' => '',
        ];
        if (!$activeTemplate->isEmpty()) {
            $data['template_id'] = (int)$activeTemplate['id'];
        }
        if (!$template->isEmpty()) {
            $data['name'] = $template['name'];
            $data['data'] = $template['data'];
            $data['meta'] = $template['meta'];
            $data['draft_data'] = $template['data'];
            $data['draft_meta'] = $template['meta'];
            $data['published_data'] = $template['data'];
            $data['published_meta'] = $template['meta'];
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
