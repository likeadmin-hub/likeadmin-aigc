<?php
namespace app\common\service;

/** Fields consumed by the current official site, including its standalone pages. */
class OfficialSiteFields
{
    public static function schema(): array
    {
        $media = ['media', 'media_type', 'poster'];
        $copy = ['title', 'description'];
        $button = ['button_text', 'button_link'];
        $icon = ['icon', 'icon_url'];
        $info = array_merge($copy, $button, $media);
        return [
            'basic' => ['name', 'logo', 'favicon', 'placeholder', 'theme', 'accent_color', 'motion_enabled',
                'start_text', 'start_link', 'footer_heading', 'footer_wordmark', 'nav_resources',
                'models_empty_text', 'record_number', 'footer_text', 'title', 'description', 'keywords', 'join_text'],
            'home' => ['hero', 'partners', 'showcase', 'scenes', 'products', 'models', 'tools', 'cases', 'faq', 'news', 'cta'],
            'api' => array_column(OfficialSiteApiTemplate::modules(), 'key'),
            'oem' => ['oem_hero','oem_intro','oem_benefits','oem_clients','oem_features','oem_packages'],
            'modules' => [
                'api_navigation' => [],
                'api_hero' => array_merge($info, ['eyebrow']),
                'api_steps' => array_merge($copy, ['eyebrow']),
                'api_generation' => array_merge($copy, $button, ['eyebrow']),
                'api_features' => array_merge($copy, ['eyebrow']),
                'api_membership' => array_merge($copy, ['eyebrow']),
                'api_clients' => $copy,
                'api_faq' => $copy,
                'api_contact' => array_merge($copy, $button, ['eyebrow']),
                'api_footer' => ['title'],
                'oem_hero' => array_merge($copy, $media, ['eyebrow','badge']),
                'oem_intro' => array_merge($copy, $button),
                'oem_benefits' => array_merge($copy, ['eyebrow','footnote']),
                'oem_clients' => ['eyebrow','title'],
                'oem_features' => ['title'],
                'oem_packages' => array_merge($copy, ['eyebrow','button_text']),
                'promo' => array_merge(['title'], $button),
                'hero' => $info,
                'partners' => ['title'],
                'showcase' => array_merge($copy, $button, ['autoplay_seconds']),
                'scenes' => array_merge($copy, ['autoplay_seconds']),
                'products' => $copy,
                'models' => array_merge($copy, $button),
                'tools' => $copy,
                'cases' => array_merge($copy, ['highlight_text', 'autoplay_seconds']),
                'faq' => $copy,
                'news' => $copy,
                'cta' => $info,
                'open' => $info, 'enterprise' => $info, 'help' => $info,
                'footer' => ['title'],
                'pricing' => ['eyebrow', 'title', 'description'],
                'join' => ['eyebrow', 'title', 'description'],
            ],
            'cards' => [
                'api_navigation' => ['title','link'],
                'api_hero' => array_merge($copy, $icon, ['link']),
                'api_steps' => array_merge($copy, $media, ['preset','button_text','link']),
                'api_generation' => array_merge($copy, $media, ['tab_label','eyebrow','preset']),
                'api_features' => array_merge($copy, $media, ['preset','preview_media','button_text','link']),
                'api_clients' => ['title','media','link'],
                'api_faq' => $copy,
                'api_footer' => ['title','eyebrow','link'],
                'oem_benefits' => array_merge($copy, $icon, $media, ['tab_label']),
                'oem_clients' => array_merge($copy, $media, ['icon_url','secondary_icon_url']),
                'oem_features' => array_merge($copy, $media, ['tab_label','icon_url','preview_layout','background_media','background_media_type','background_poster','preview_media','preview_media_type','preview_poster']),
                'hero' => array_merge(['title'], $media),
                'partners' => array_merge(['title', 'link'], $icon),
                'showcase' => array_merge($copy, $media, $icon, ['link']),
                'scenes' => array_merge($copy, $media, ['button_text', 'link']),
                'products' => array_merge($copy, $media, $icon, ['tab_label', 'eyebrow', 'button_text', 'link', 'status']),
                'models' => array_merge($copy, $media, ['model_id', 'icon_url']),
                'tools' => array_merge($copy, $media, $icon, ['button_text', 'link']),
                'cases' => array_merge($copy, ['source', 'rating', 'avatar']),
                'faq' => $copy,
                'news' => array_merge($copy, $media, ['eyebrow', 'link']),
                'open' => array_merge($copy, $media, ['link']),
                'enterprise' => array_merge($copy, $media, ['link']),
                'help' => array_merge($copy, $media, ['link']),
                'footer' => ['title', 'link', 'status'],
            ],
        ];
    }

    public static function clean(array $config): array
    {
        $schema = self::schema();
        $config['basic'] = array_intersect_key($config['basic'], array_flip($schema['basic']));
        foreach ($config['modules'] as &$module) {
            $key = $module['key'];
            // Keep structural fields so existing readers can iterate uniformly.
            $module = array_intersect_key($module, array_flip(array_merge(
                ['key', 'enabled', 'sort', 'cards', 'slides'], $schema['modules'][$key] ?? []
            )));
            $fields = $schema['cards'][$key] ?? [];
            $module['cards'] = $fields ? array_map(static fn($card) => array_intersect_key(
                $card, array_flip(array_merge(['enabled', 'sort'], $fields))
            ), $module['cards']) : [];
        }
        unset($module);
        foreach ($config['navigation'] as &$entry) {
            if ($entry['key'] === 'models') $entry['groups'] = [];
            else unset($entry['title']);
        }
        unset($entry);
        return $config;
    }
}
