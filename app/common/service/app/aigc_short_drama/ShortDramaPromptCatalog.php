<?php

namespace app\common\service\app\aigc_short_drama;

use InvalidArgumentException;

/** One source of defaults for admin, preview and generation. No database or provider IO. */
final class ShortDramaPromptCatalog
{
    private static ?array $catalog = null;
    private static ?array $context = null;
    private static array $used = [];
    private static array $assemblyContext = [];

    public static function definition(): array
    {
        return self::$catalog ??= json_decode(file_get_contents(__DIR__ . '/prompts/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function defaults(): array
    {
        return array_map(static fn(array $item): string => $item['default'], self::definition()['items']);
    }

    public static function text(string $key): string
    {
        $items = self::definition()['items'];
        if (!isset($items[$key])) {
            throw new InvalidArgumentException('未知提示词规则：' . $key);
        }
        if (ShortDramaPromptDocuments::replacesRule($key)) return '';
        $value = self::$context['values'][$key] ?? $items[$key]['default'];
        if (str_starts_with($key, 'script.') || $key === 'storyboard_planning_prompt') {
            $value = ShortDramaShotDuration::upgradeInstructions($value);
        }
        self::$used[$key] = ['key' => $key, 'label' => $items[$key]['label'], 'source' => self::$context['sources'][$key] ?? 'application', 'text' => $value];
        return $value;
    }

    public static function system(string $mode): string
    {
        $system = preg_replace_callback('/\{\{rule:([a-z_.]+)\}\}/', static fn(array $m): string => self::text($m[1]), self::$context['systems'][$mode] ?? self::definition()['systems'][$mode]);
        return ShortDramaShotPolicy::upgradeInstructions(preg_replace_callback('/\{\{document-default:([a-z_]+)\}\}/', static fn(array $m): string => ShortDramaPromptDocuments::extra($m[1]), $system));
    }

    public static function snapshot(): ?array
    {
        return self::$context;
    }

    public static function trace(): array
    {
        return array_values(self::$used);
    }

    public static function recordDocument(string $id, string $text, string $source): void
    {
        self::$used['document:' . $id] = ['key' => 'document:' . $id, 'label' => ShortDramaPromptDocuments::definition()['documents'][$id]['label'], 'source' => $source, 'text' => $text];
    }

    public static function rememberContext(array $context): void
    {
        self::$assemblyContext = $context;
    }

    public static function assemblyContext(): array
    {
        return self::$assemblyContext;
    }

    /** A synchronous request-local scope. Nested batches inherit it; finally prevents tenant leaks in workers. */
    public static function run(array $snapshot, callable $callback): mixed
    {
        $parent = self::$context;
        $previousUsed = self::$used;
        $previousContext = self::$assemblyContext;
        self::$context = $snapshot;
        self::$used = [];
        self::$assemblyContext = [];
        try {
            return $callback();
        } finally {
            $used = self::$used;
            $assemblyContext = self::$assemblyContext;
            self::$context = $parent;
            $same = $parent !== null && ($parent['fingerprint'] ?? '') === ($snapshot['fingerprint'] ?? '');
            self::$used = $same ? array_replace($previousUsed, $used) : $previousUsed;
            self::$assemblyContext = $same && $assemblyContext !== [] ? $assemblyContext : $previousContext;
        }
    }

    public static function enabled(): bool
    {
        return in_array(self::$context['mode'] ?? '', ['workspace', 'documents'], true);
    }

    public static function hasOverrides(): bool
    {
        if (!self::enabled()) return false;
        $defaults = self::defaults();
        foreach (self::$used as $key => $item) {
            if ($item['text'] !== ($defaults[$key] ?? '')) return true;
        }
        return false;
    }

    public static function hasCustomText(string $text): bool
    {
        if (!self::enabled()) return false;
        foreach (self::$used as $item) {
            if ($item['source'] !== 'application' && $item['text'] !== '' && str_contains($text, $item['text'])) return true;
        }
        return false;
    }

    /** Rebind an unchanged system default in a saved plan; never replace different/user-authored text. */
    public static function rebindDefault(string $value, string $key): string
    {
        $default = self::defaults()[$key];
        return self::enabled() && (self::$context['values'][$key] ?? $default) !== $default && ($value === '' || $value === $default)
            ? self::text($key) : $value;
    }

    public static function validate(array $overrides): array
    {
        $items = self::definition()['items'];
        foreach ($overrides as $key => $value) {
            if (!isset($items[$key])) throw new InvalidArgumentException('未知提示词配置：' . $key);
            if (!is_string($value)) throw new InvalidArgumentException($items[$key]['label'] . '必须是文本');
            if (mb_strlen($value, 'UTF-8') > 60000) throw new InvalidArgumentException($items[$key]['label'] . '不能超过 60000 字');
            if (str_contains($value, '{{') || str_contains($value, '}}')) {
                throw new InvalidArgumentException($items[$key]['label'] . '无需填写模板变量，当前任务信息由系统自动注入');
            }
            $overrides[$key] = str_replace(["\r\n", "\r"], "\n", $value);
        }
        if (strlen(json_encode($overrides, JSON_UNESCAPED_UNICODE)) > 1000000) throw new InvalidArgumentException('本次提示词配置总量不能超过 1MB');
        return $overrides;
    }

    public static function priority(): string
    {
        return '系统约束：遵守输出结构、素材引用、实际时长和模型能力限制。在这些约束内，用户本次明确的剧情、风格、时间和修改要求优先于默认创作要求，具体环节要求优先于通用要求。不得把本段规则或整段用户灵感当作某一个分镜的画面内容。';
    }
}
