<?php

namespace app\common\service;

/**
 * Tenant-owned content for the public PC official site.
 * The schema is deliberately fixed: tenants can operate content without
 * creating arbitrary page structures that the public renderer cannot secure.
 */
class OfficialSiteService
{
    private const TYPE = 'official_site';
    private const KEY = 'config';

    public static function get(): array
    {
        $stored = ConfigService::get(self::TYPE, self::KEY, []);
        $config = self::normalize(is_array($stored) ? $stored : []);
        // Existing tenants receive the default template on first read.
        if (!is_array($stored) || $stored === []) {
            ConfigService::set(self::TYPE, self::KEY, self::toStorage($config));
        }
        return $config;
    }

    public static function save(array $params): array
    {
        $config = self::normalize($params);
        ConfigService::set(self::TYPE, self::KEY, self::toStorage($config));
        self::syncWebsiteBasics($config['basic']);
        return self::get();
    }

    /** Public config deliberately omits back-office and sales-sensitive fields. */
    public static function public(): array
    {
        $config = self::get();
        $config['join_available'] = !empty(\app\common\service\brand\TenantBrandService::packageRows((int)request()->tenantId, true));
        $config['basic']['logo'] = self::fileUrl($config['basic']['logo']);
        $config['basic']['favicon'] = self::fileUrl($config['basic']['favicon']);
        foreach ($config['modules'] as &$module) {
            $module['media'] = self::fileUrl((string)($module['media'] ?? ''));
            foreach ($module['cards'] as &$card) {
                $card['media'] = self::fileUrl((string)($card['media'] ?? ''));
                unset($card['internal_note'], $card['admin_only']);
            }
            unset($card);
        }
        unset($module);
        return $config;
    }

    private static function normalize(array $input): array
    {
        $defaults = self::defaults();
        $enabled = array_key_exists('enabled', $input) ? (int)!empty($input['enabled']) : 1;
        $basic = array_merge($defaults['basic'], is_array($input['basic'] ?? null) ? $input['basic'] : []);
        $basic = [
            'name' => self::text($basic['name'], 60),
            'logo' => self::text($basic['logo'], 255),
            'favicon' => self::text($basic['favicon'], 255),
            'title' => self::text($basic['title'], 80),
            'description' => self::text($basic['description'], 255),
            'keywords' => self::text($basic['keywords'], 255),
            'workbench_text' => self::text($basic['workbench_text'], 30),
            'join_text' => self::text($basic['join_text'], 30),
        ];

        $inputModules = is_array($input['modules'] ?? null) ? $input['modules'] : [];
        $moduleMap = [];
        foreach ($inputModules as $module) {
            if (is_array($module) && isset($module['key'])) $moduleMap[(string)$module['key']] = $module;
        }
        $modules = [];
        foreach ($defaults['modules'] as $default) {
            $raw = array_merge($default, $moduleMap[$default['key']] ?? []);
            $raw['key'] = $default['key'];
            $raw['enabled'] = (int)!empty($raw['enabled']);
            $raw['sort'] = (int)($raw['sort'] ?? $default['sort']);
            $raw['title'] = self::text($raw['title'] ?? '', 100);
            $raw['description'] = self::text($raw['description'] ?? '', 500);
            $raw['media'] = self::text($raw['media'] ?? '', 255);
            $raw['button_text'] = self::text($raw['button_text'] ?? '', 40);
            $raw['button_link'] = self::safeLink((string)($raw['button_link'] ?? ''));
            $raw['cards'] = self::normalizeCards($raw['cards'] ?? $default['cards']);
            $modules[] = $raw;
        }
        usort($modules, static fn(array $a, array $b) => $b['sort'] <=> $a['sort']);

        return ['enabled' => $enabled, 'basic' => $basic, 'modules' => $modules];
    }

    private static function normalizeCards($cards): array
    {
        if (!is_array($cards)) return [];
        $result = [];
        foreach (array_slice($cards, 0, 24) as $index => $card) {
            if (!is_array($card)) continue;
            $status = (string)($card['status'] ?? 'live');
            if (!in_array($status, ['live', 'planned', 'enterprise'], true)) $status = 'planned';
            $result[] = [
                'title' => self::text($card['title'] ?? '', 80),
                'description' => self::text($card['description'] ?? '', 300),
                'media' => self::text($card['media'] ?? '', 255),
                'status' => $status,
                'link' => $status === 'live' ? self::safeLink((string)($card['link'] ?? '/ai')) : '',
                'sort' => (int)($card['sort'] ?? (100 - $index)),
            ];
        }
        usort($result, static fn(array $a, array $b) => $b['sort'] <=> $a['sort']);
        return $result;
    }

    private static function toStorage(array $config): array
    {
        foreach ($config['modules'] as &$module) {
            $module['media'] = FileService::setFileUrl($module['media']);
            foreach ($module['cards'] as &$card) $card['media'] = FileService::setFileUrl($card['media']);
            unset($card);
        }
        unset($module);
        $config['basic']['logo'] = FileService::setFileUrl($config['basic']['logo']);
        $config['basic']['favicon'] = FileService::setFileUrl($config['basic']['favicon']);
        return $config;
    }

    private static function syncWebsiteBasics(array $basic): void
    {
        ConfigService::set('website', 'pc_logo', FileService::setFileUrl($basic['logo']));
        ConfigService::set('website', 'pc_title', $basic['title']);
        ConfigService::set('website', 'pc_ico', FileService::setFileUrl($basic['favicon']));
        ConfigService::set('website', 'pc_desc', $basic['description']);
        ConfigService::set('website', 'pc_keywords', $basic['keywords']);
    }

    private static function fileUrl(string $value): string
    {
        return $value === '' ? '' : FileService::getFileUrl($value);
    }

    private static function safeLink(string $link): string
    {
        return preg_match('#^/(?!/)[A-Za-z0-9_/?=&.-]*$#', $link) ? $link : '';
    }

    private static function text($value, int $length): string
    {
        return mb_substr(trim((string)$value), 0, $length);
    }

    private static function defaults(): array
    {
        return [
            'basic' => [
                'name' => 'OPC AI', 'logo' => '', 'favicon' => '', 'title' => 'OPC AI - 企业级智能创作平台',
                'description' => '面向创作团队和企业的多模态 AI 工作平台', 'keywords' => 'AI,智能体,工作流,企业知识库',
                'workbench_text' => '进入工作台', 'join_text' => '加盟 OPC 平台',
            ],
            'modules' => [
                ['key' => 'hero', 'enabled' => 1, 'sort' => 100, 'title' => '让 AI 成为增长团队的一部分', 'description' => '从内容创作、数字员工到企业智能化，以统一工作台连接模型、工具、知识和自动化流程。', 'media' => '', 'button_text' => '开始创作', 'button_link' => '/ai', 'cards' => []],
                ['key' => 'products', 'enabled' => 1, 'sort' => 90, 'title' => '产品能力', 'description' => '以可控、可运营的方式接入先进 AI 能力。', 'media' => '', 'button_text' => '查看全部能力', 'button_link' => '/products', 'cards' => [
                    ['title' => '多模态创作', 'description' => '图像、视频、文案、数字人和商品视觉一站完成。', 'status' => 'live', 'link' => '/ai', 'sort' => 100],
                    ['title' => 'AI Agent', 'description' => '面向销售、运营、客服与研发的任务型数字员工。', 'status' => 'planned', 'link' => '', 'sort' => 90],
                    ['title' => '企业知识智能', 'description' => '文档、网页和业务资料形成可信问答与协作能力。', 'status' => 'enterprise', 'link' => '', 'sort' => 80],
                    ['title' => '自动化工作流', 'description' => '通过模型、应用和业务系统编排端到端流程。', 'status' => 'planned', 'link' => '', 'sort' => 70],
                ]],
                ['key' => 'scenes', 'enabled' => 1, 'sort' => 80, 'title' => '应用场景', 'description' => '从个人生产力到企业智能化，按业务目标组合能力。', 'media' => '', 'button_text' => '探索应用场景', 'button_link' => '/scenes', 'cards' => [
                    ['title' => '电商增长', 'description' => '商品图、详情页、短视频与多渠道运营自动化。', 'status' => 'live', 'link' => '/ai/tools', 'sort' => 100],
                    ['title' => '品牌内容工厂', 'description' => '将选题、脚本、素材、审核与分发串为内容流水线。', 'status' => 'planned', 'link' => '', 'sort' => 90],
                    ['title' => '智能客服与销售', 'description' => '基于企业知识和客户上下文提供可追溯服务。', 'status' => 'enterprise', 'link' => '', 'sort' => 80],
                ]],
                ['key' => 'cases', 'enabled' => 1, 'sort' => 70, 'title' => '精选案例', 'description' => '用可复用的方法让 AI 走进每一个业务环节。', 'media' => '', 'button_text' => '查看案例', 'button_link' => '/cases', 'cards' => [
                    ['title' => '全域商品内容', 'description' => '围绕商品建模、视觉生成和素材复用提升上新效率。', 'status' => 'live', 'link' => '/ai', 'sort' => 100],
                    ['title' => '企业知识助理', 'description' => '把散落资料转成权限可控、持续更新的团队助手。', 'status' => 'enterprise', 'link' => '', 'sort' => 90],
                ]],
                ['key' => 'pricing', 'enabled' => 1, 'sort' => 60, 'title' => '灵活的服务方案', 'description' => '按团队规模、应用权限和企业治理需要选择服务。', 'media' => '', 'button_text' => '查看价格方案', 'button_link' => '/pricing', 'cards' => []],
                ['key' => 'join', 'enabled' => 1, 'sort' => 50, 'title' => '加盟 OPC 平台', 'description' => '以自己的品牌销售 AI 应用，自助购买套餐并快速开通独立租户。', 'media' => '', 'button_text' => '立即加盟', 'button_link' => '/join-opc', 'cards' => []],
            ],
        ];
    }
}
