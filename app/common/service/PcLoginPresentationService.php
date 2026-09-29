<?php
namespace app\common\service;

/** Tenant-scoped presentation only; authentication configuration is independent. */
class PcLoginPresentationService
{
    public static function normalizeSlides($value, ?callable $resolve = null): array
    {
        if (!is_array($value)) return [];
        $slides = [];
        foreach (array_slice($value, 0, 8) as $slide) {
            if (!is_array($slide)) continue;
            $image = is_string($slide['image'] ?? null) ? trim($slide['image']) : '';
            if ($image === '' || strlen($image) > 1000 || preg_match('/^(?:data|javascript):/i', $image)) continue;
            $slides[] = [
                'image' => $resolve ? $resolve($image) : $image,
                'title' => mb_substr(trim(is_string($slide['title'] ?? null) ? $slide['title'] : ''), 0, 60),
                'background' => self::color($slide['background'] ?? '', '#efebf7'),
                'background_dark' => self::color($slide['background_dark'] ?? '', '#24202e'),
            ];
        }
        return $slides;
    }

    private static function color($value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) ? $value : $fallback;
    }

    public static function get(): array
    {
        return [
            'pc_login_slides' => self::normalizeSlides(ConfigService::get('website', 'pc_login_slides', []), [FileService::class, 'getFileUrl']),
            'pc_login_caption' => (string)ConfigService::get('website', 'pc_login_caption', ''),
        ];
    }

    public static function save(array $params): void
    {
        // Older clients omit these keys: do not erase existing carousel settings.
        if (array_key_exists('pc_login_slides', $params)) {
            ConfigService::set('website', 'pc_login_slides', self::normalizeSlides($params['pc_login_slides'], [FileService::class, 'setFileUrl']));
        }
        if (array_key_exists('pc_login_caption', $params)) {
            ConfigService::set('website', 'pc_login_caption', mb_substr(trim((string)$params['pc_login_caption']), 0, 60));
        }
    }
}
