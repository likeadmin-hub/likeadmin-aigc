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
use app\common\model\article\Article;
use app\common\model\decorate\DecorateTabbar;
use app\common\service\decorate\DecorateTemplateService;

/**
 * 装修页-数据
 * Class DecorateDataLogic
 * @package app\tenantapi\logic\decorate
 */
class DecorateDataLogic extends BaseLogic
{

    /**
     * @notes 获取文章列表
     * @param $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/22 16:49
     */
    public static function getArticleLists($limit): array
    {
        $field = 'id,title,desc,abstract,image,author,content,
        click_virtual,click_actual,create_time';

        $tenantId = (int)(request()->tenantId ?? 0);
        $query = Article::withoutGlobalScope()->where('is_show', 1);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        } else {
            $query->where('tenant_id', 0);
        }

        $articles = $query
            ->field($field)
            ->order(['id' => 'desc'])
            ->limit($limit)
            ->append(['click'])
            ->hidden(['click_virtual', 'click_actual'])
            ->select()->toArray();

        // A newly created tenant may not have added articles yet. The platform
        // defaults are safe to display as a read-only fallback in that case.
        if ($articles === [] && $tenantId > 0) {
            $articles = Article::withoutGlobalScope()
                ->where(['is_show' => 1, 'tenant_id' => 0])
                ->field($field)
                ->order(['id' => 'desc'])
                ->limit($limit)
                ->append(['click'])
                ->hidden(['click_virtual', 'click_actual'])
                ->select()->toArray();
        }

        return $articles;
    }

    /**
     * @notes pc设置
     * @return array
     * @author mjf
     * @date 2024/3/14 18:13
     */
    public static function pc(): array
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        $pcPage = DecorateTemplateService::activePublishedPage(
            $tenantId,
            DecorateTemplateService::TERMINAL_PC,
            'pc_home',
            4,
            DecorateTemplateService::CHANNEL_COMMON
        );
        $updateTime = !empty($pcPage['update_time']) ? $pcPage['update_time'] : date('Y-m-d H:i:s');
        return [
            'update_time' => $updateTime,
            'pc_url' => request()->domain() . '/pc'
        ];
    }

    /**
     * @notes 初始化装修配置
     * @param mixed $tenant_id
     * @return void
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author JXDN
     * @date 2024/09/10 15:46
     */
    public static function initialization(mixed $tenant_id)
    {
        $tenantId = (int)$tenant_id;
        if ($tenantId <= 0) {
            return;
        }

        // The template service owns tenant-scoped page creation. Calling it
        // first makes repeated tenant initialization idempotent.
        DecorateTemplateService::ensureDefaultTemplate($tenantId);

        // Keep the legacy tabbar table in sync for existing endpoints, but
        // only seed it once for a tenant.
        $tabbar_field = "name,selected,unselected,link,is_show,tenant_id";
        if ((int)DecorateTabbar::where(['tenant_id' => $tenantId])->count() === 0) {
            $tabbarList = DecorateTabbar::withoutGlobalScope()->where(['tenant_id' => 0])->field($tabbar_field)->select()->toArray();
            foreach ($tabbarList as $item) {
                $item['tenant_id'] = $tenantId;
                DecorateTabbar::create($item);
            }
        }
    }
}
