<?php
declare(strict_types=1);

namespace app\common\service\power;

/** Native output switches. Reference audio and source-video sound have different semantics. */
final class MarketVideoAudioOutput
{
    public const SWITCHES = ['generate_audio', 'audio', 'sound', 'generate_audio_switch', 'enable_audio'];

    public static function field(array $schema, string $mode = ''): ?string
    {
        $properties = MarketGenerationSchema::properties($schema);
        foreach (self::SWITCHES as $field) {
            $definition = $properties[$field] ?? [];
            if (($definition['type'] ?? '') === 'boolean' && MarketGenerationSchema::applies($definition, $mode)) {
                return $field;
            }
        }
        return null;
    }

    public static function allowsSound(array $locks): bool
    {
        foreach ([$locks, (array)($locks['parameters'] ?? [])] as $params) {
            foreach (self::SWITCHES as $field) {
                if (in_array($params[$field] ?? null, [false, 0, '0', 'false'], true)) {
                    return false;
                }
            }
        }
        return true;
    }

    public static function enable(array $snapshot, array $request): array
    {
        if (!self::allowsSound((array)($snapshot['locked_params'] ?? []))) {
            throw new \InvalidArgumentException('当前规格仅支持无声视频，请选择有声规格');
        }
        $metadata = MarketGenerationRequest::metadata($snapshot);
        $mode = MarketGenerationMaterials::select($metadata, $request)['native_mode'] ?? '';
        $schema = MarketGenerationNativeContract::schema($metadata, $mode) ?: (array)($metadata['params_schema'] ?? []);
        $field = self::field($schema, $mode);
        if ($field !== null) {
            $request[$field] = true;
        }
        $request['generate_audio'] = true;
        return $request;
    }

    public static function assertEnabled(array $nativeInput): void
    {
        foreach ([$nativeInput, (array)($nativeInput['parameters'] ?? [])] as $params) {
            foreach (self::SWITCHES as $field) {
                if (in_array($params[$field] ?? null, [true, 1, '1', 'true'], true)) {
                    return;
                }
            }
        }
        throw new \InvalidArgumentException('当前生成方式无法开启视频声音，请选择其他有声规格');
    }
}
