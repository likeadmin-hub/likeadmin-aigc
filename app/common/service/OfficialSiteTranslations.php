<?php
namespace app\common\service;

/** Tenant-owned display copy only; never translate configuration values or URLs. */
class OfficialSiteTranslations
{
    private const FIELDS = ['title','description','label','tab_label','eyebrow','footnote','highlight_text','button_text','start_text','footer_heading','footer_text','nav_resources','models_empty_text','join_text','keywords','source'];

    public static function clean(array $config, $input): array
    {
        $sources = [];
        $visit = static function ($value, string $key = '') use (&$visit, &$sources): void {
            if (is_array($value)) {
                foreach ($value as $field => $item) {
                    if (!in_array($field, ['translations','field_schema','model_catalog'], true)) $visit($item, (string)$field);
                }
            } elseif (is_string($value) && in_array($key, self::FIELDS, true)) {
                $source = preg_replace('/\s+/u', ' ', trim($value));
                if ($source !== '') $sources[$source] = true;
            }
        };
        $visit($config);
        $result = ['en'=>[], 'zh-TW'=>[]];
        if (!is_array($input)) return $result;
        foreach ($result as $locale => $_) {
            if (!is_array($input[$locale] ?? null)) continue;
            foreach ($input[$locale] as $source => $text) {
                if (!is_string($source) || !is_string($text)) continue;
                $source = preg_replace('/\s+/u', ' ', trim($source));
                $text = trim(strip_tags($text));
                if (isset($sources[$source]) && $text !== '' && mb_strlen($text) <= 3000) $result[$locale][$source] = $text;
                if (count($result[$locale]) >= 2000) break;
            }
        }
        return $result;
    }
}
