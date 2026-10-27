<?php
namespace app\common\service\app\aigc_short_drama;

/** Self-contained, lossless sharing inside one provider request; never a summary or remote cache. */
final class ShortDramaScriptContext
{
    public const CONTRACT_VERSION = 13;
    public const INSTRUCTION = '上下文中的 {"$ref":"#/路径"} 引用本次 JSON 内完整保留的相同资料，路径各段对应对象字段或数组下标；读取引用目标等同于读取原资料，不代表内容缺失，也不依赖上次请求。'
        . '原始要求、当前集、当前场景、指定分镜和已完成事实仍须完整遵守；全剧或其他场次的信息仅用于衔接，不得提前演出。';

    private const DIRECT = [
        '#/creative_context/user_prompt', '#/creative_context/revision_message',
        '#/creative_context/revision_target', '#/creative_context/revision_policy',
        '#/creative_context/series_context/current_episode',
        '#/creative_context/series_context/continuity/state',
        '#/creative_context/series_context/continuity/open_hooks',
        '#/scene_beat', '#/required_shots', '#/previous_shots', '#/scene_progress',
    ];

    public static function enabled(array $request): bool
    {
        return (int)($request['_input_contract_version'] ?? 0) >= self::CONTRACT_VERSION;
    }

    public static function appendInstruction(string $system, string $instruction, array $request): string
    {
        if (self::enabled($request) && str_contains($system, $instruction)) return $system;
        return $system . "\n" . $instruction;
    }

    /** Run AFTER working-memory projection so references cannot point to a subsequently removed record. */
    public static function messages(array $messages, array $request): array
    {
        $trusted = !empty($messages['_share_script_context']);
        unset($messages['_share_script_context']);
        if (!$trusted || !self::enabled($request)) return $messages;
        $text = $messages['content'];
        try { $context = ShortDramaStructuredResponse::decode(['content' => $text]); }
        catch (\RuntimeException) { return $messages; }
        $prefix = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!str_starts_with($text, $prefix) || !is_string($context['creative_context'] ?? null)) return $messages;
        $shared = self::scene($context, $request, $context['creative_context']);
        if ($shared === $context) return $messages;
        $original = $messages;
        $messages['content'] = json_encode($shared, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . substr($text, strlen($prefix));
        $messages['system_prompt'] .= "\n" . self::INSTRUCTION;
        // Tiny inputs may cost more to explain than to share. Keep the cheaper complete request.
        if (strlen($messages['system_prompt'] . $messages['content']) >= strlen($original['system_prompt'] . $text)
            || mb_strlen($messages['system_prompt'] . $messages['content']) >= mb_strlen($original['system_prompt'] . $text)) return $original;
        return $messages;
    }

    /** Only the assembler's private JSON marker authorizes decoding; custom templates stay verbatim. */
    public static function scene(array $context, array $request, ?string $trustedContext): array
    {
        if (!self::enabled($request) || $trustedContext === null
            || ($context['creative_context'] ?? null) !== $trustedContext) return $context;
        try { $creative = json_decode($trustedContext, false, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return $context; }
        if (!$creative instanceof \stdClass) return $context;
        $candidate = $context;
        $candidate['creative_context'] = $creative;
        // Do not reinterpret reference-like data supplied by a template or user.
        if (self::hasReference($candidate)) return $context;

        // Keep the current scene explicit; copies in the full scene list may reference it.
        $ordered = [];
        foreach ($candidate as $key => $value) {
            if ($key === 'scene_continuity' && isset($candidate['scene_beat'])) $ordered['scene_beat'] = $candidate['scene_beat'];
            $ordered[$key] = $value;
        }
        $seen = [];
        return self::share($ordered, '#', $seen);
    }

    private static function hasReference(mixed $value): bool
    {
        if (!is_array($value) && !$value instanceof \stdClass) return false;
        foreach ($value as $key => $child) {
            if ($key === '$ref' || self::hasReference($child)) return true;
        }
        return false;
    }

    private static function share(mixed $value, string $path, array &$seen): mixed
    {
        if (!is_array($value) && !$value instanceof \stdClass) return $value;
        $direct = false;
        foreach (self::DIRECT as $anchor) {
            if ($path === $anchor || str_starts_with($path, $anchor . '/') || str_starts_with($anchor, $path . '/')) {
                $direct = true;
                break;
            }
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Small values cost less inline and keep identities easy for a model to read.
        if (strlen($json) >= 384) {
            $hash = hash('sha256', $json);
            if (!$direct && isset($seen[$hash]) && $seen[$hash]['json'] === $json) {
                $reference = ['$ref' => $seen[$hash]['path']];
                if (strlen(json_encode($reference, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) < strlen($json)) return $reference;
            }
            $seen[$hash] ??= ['path' => $path, 'json' => $json];
        }
        $result = is_array($value) ? $value : clone $value;
        foreach ($value as $key => $child) {
            $pointer = str_replace(['~', '/'], ['~0', '~1'], (string)$key);
            $child = self::share($child, $path . '/' . $pointer, $seen);
            if (is_array($result)) $result[$key] = $child;
            else $result->{$key} = $child;
        }
        return $result;
    }
}
