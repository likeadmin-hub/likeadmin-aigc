<?php
declare(strict_types=1);
namespace app\common\service\app;

final class BuiltinGenreCovers
{
    /** Refresh bundled pictures without replacing a tenant's own artwork. */
    public static function apply(array $genres, string $app, array $defaults): array
    {
        $defaults = array_column($defaults, null, 'code');
        foreach ($genres as &$genre) {
            $code = (string)($genre['code'] ?? '');
            $default = $defaults[$code] ?? null;
            if (!$default || ($genre['name'] ?? '') !== $default['name']) continue;
            $cover = (string)($genre['cover_url'] ?? '');
            $path = ltrim((string)(parse_url($cover, PHP_URL_PATH) ?? ''), '/');
            $legacy = ['resource/image/apps/'.$app.'/types/'.$code.'.webp',
                'apps/'.$app.'/static/genres/'.$code.'.png', 'images/creative-genres/'.$code.'.png'];
            if ($cover === '' || in_array($path, $legacy, true)) {
                $genre['cover_url'] = $default['cover_url'] ?? $cover;
            }
        }
        unset($genre);
        return $genres;
    }
}
