<?php
namespace app\common\service\decorate\agent;

use InvalidArgumentException;
use RuntimeException;

/** Immutable generated registry contract; no database, provider or tenant state. */
class DecorationAgentCatalog
{
    private static ?array $catalog = null;

    public static function all(): array
    {
        if (self::$catalog === null) {
            $file = __DIR__ . '/catalog/mobile-catalog.json';
            $json = file_get_contents($file);
            $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $expected = $object->hash ?? '';
            unset($object->hash);
            $canonical = function ($value) use (&$canonical) {
                if (is_object($value)) {
                    $vars = get_object_vars($value); ksort($vars, SORT_STRING);
                    $next = new \stdClass();
                    foreach ($vars as $key => $entry) $next->$key = $canonical($entry);
                    return $next;
                }
                return is_array($value) ? array_map($canonical, $value) : $value;
            };
            $hash = hash('sha256', json_encode($canonical($object), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if (!hash_equals($expected, $hash)) throw new RuntimeException('Decoration Agent catalog checksum mismatch');
            self::$catalog = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }
        return self::$catalog;
    }

    public static function hash(): string { return self::all()['hash']; }
    public static function version(): string { return self::all()['version']; }
    public static function summary(): array { return self::all(); }
    public static function pageMeta(): array { return self::all()['page_meta']; }
    public static function definition(string $name): array
    {
        foreach (self::all()['components'] as $definition) if ($definition['name'] === $name) return $definition;
        throw new InvalidArgumentException('Unknown mobile decoration component: ' . $name);
    }
    public static function create(string $name, string $id): array
    {
        if ($id === '') throw new InvalidArgumentException('Component id is required');
        $widget = self::definition($name)['defaults'];
        $widget['id'] = $id;
        return $widget;
    }
}
