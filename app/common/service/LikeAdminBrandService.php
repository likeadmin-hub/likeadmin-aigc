<?php
declare(strict_types=1);
namespace app\common\service;

/** Read-only compatibility for the former reference-template identifiers. */
final class LikeAdminBrandService
{
    public static function style($value)
    {
        return in_array($value, ['imagine', 'inspiration'], true) ? 'likeadmin' : $value;
    }

    public static function configuration(array $config): array
    {
        foreach (['home_style', 'pc_home_style'] as $key) {
            if (array_key_exists($key, $config)) $config[$key] = self::style($config[$key]);
        }
        if (!array_key_exists('likeadmin_background', $config)) {
            foreach (['inspiration_background', 'imagine_background'] as $legacy) {
                if (array_key_exists($legacy, $config)) { $config['likeadmin_background'] = $config[$legacy]; break; }
            }
        }
        unset($config['inspiration_background'], $config['imagine_background']);
        return $config;
    }

    /** Keep cached frontend bundles readable without changing canonical stored configuration. */
    public static function publicPresentation(array $data, $schema): array
    {
        // bool callers are the previous v1/v2 service contract.
        $version = is_bool($schema) ? ($schema ? 2 : 1) : (int)$schema;
        $data = self::configuration($data);
        if (isset($data['website']) && is_array($data['website'])) $data['website'] = self::configuration($data['website']);
        if ($version >= 3) return $data;
        $style = $version >= 2 ? 'inspiration' : 'imagine';
        foreach (['home_style', 'pc_home_style'] as $key) {
            if (($data[$key] ?? '') === 'likeadmin') $data[$key] = $style;
        }
        if (($data['website']['pc_home_style'] ?? '') === 'likeadmin') $data['website']['pc_home_style'] = $style;
        if (array_key_exists('likeadmin_background', $data)) {
            $data[$version >= 2 ? 'inspiration_background' : 'imagine_background'] = $data['likeadmin_background'];
            unset($data['likeadmin_background']);
        }
        return $data;
    }

    public static function assetPath(string $value): string
    {
        // Rename only bundled covers; tenant-owned paths must never be rewritten.
        return preg_replace('~^/?(?:imagine|inspiration)-home/(character|fashion|snooker|sunglasses|social)\.jpeg(?=[?#]|$)~', '/likeadmin-home/$1.jpeg', $value);
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
