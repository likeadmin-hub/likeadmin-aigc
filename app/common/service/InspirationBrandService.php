<?php
declare(strict_types=1);
namespace app\common\service;

/** Read-only compatibility for the former reference-template identifiers. */
final class InspirationBrandService
{
    public static function style($value)
    {
        return $value === 'imagine' ? 'inspiration' : $value;
    }

    public static function configuration(array $config): array
    {
        foreach (['home_style', 'pc_home_style'] as $key) {
            if (array_key_exists($key, $config)) $config[$key] = self::style($config[$key]);
        }
        if (!array_key_exists('inspiration_background', $config) && array_key_exists('imagine_background', $config)) {
            $config['inspiration_background'] = $config['imagine_background'];
        }
        unset($config['imagine_background']);
        return $config;
    }

    /** Keep cached frontend bundles readable without changing canonical stored configuration. */
    public static function publicPresentation(array $data, bool $modern): array
    {
        if ($modern) return $data;
        if (($data['home_style'] ?? '') === 'inspiration') $data['home_style'] = 'imagine';
        if (($data['pc_home_style'] ?? '') === 'inspiration') $data['pc_home_style'] = 'imagine';
        if (($data['website']['pc_home_style'] ?? '') === 'inspiration') $data['website']['pc_home_style'] = 'imagine';
        if (array_key_exists('inspiration_background', $data)) $data['imagine_background'] = $data['inspiration_background'];
        return $data;
    }

    public static function assetPath(string $value): string
    {
        // Rename only bundled covers; tenant-owned paths must never be rewritten.
        return preg_replace('~^/?imagine-home/(character|fashion|snooker|sunglasses|social)\.jpeg(?=[?#]|$)~', '/inspiration-home/$1.jpeg', $value);
    }

    public static function isReferenceUrl(string $value): bool
    {
        $host = strtolower((string)parse_url($value, PHP_URL_HOST));
        foreach (['imagine.art', 'imagine.animagic.art', 'cdn-imagine.vyro.ai'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) return true;
        }
        return false;
    }

    public static function hasBrandName(string $value): bool
    {
        return (bool)preg_match('/\bimagine(?:art|apps)?\b/i', $value);
    }
}
