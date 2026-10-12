<?php
namespace app\common\service\open_platform;

use app\common\service\power\MarketUsageSettlementService;

/** Quote-only preparation. No transport, asset mapping, wallet or reservation writes. */
final class OpenCharacterInputPricing
{
    public const ADAPTER = 'character_input_v1';
    private const TEXT_KEYS = ['text','prompt','content','input'];
    private const IGNORED_KEYS = ['app_code','api_code','callback_url','model','format','language','audio_url','video_url','file_url','media_url','url','reference_id','sample_rate','chunk_length','mp3_bitrate','opus_bitrate','latency'];

    public static function supports(array $product, array $sku): bool
    {
        return self::productSupported($product) && ($sku['usage_unit'] ?? '') === 'character';
    }

    public static function productSupported(array $product): bool
    {
        return ($product['resource_type'] ?? '') === 'app_api'
            && ($product['upstream_app_code'] ?? '') === 'voice_tts'
            && in_array($product['upstream_api_code'] ?? '', ['tts','tts_async','tts_live'], true);
    }

    /** Mirrors source PointsService::countBillableCharacters, including its exact fallback list. */
    public static function count(array $effective): int
    {
        $total = 0;
        foreach (self::TEXT_KEYS as $key) if (array_key_exists($key, $effective)) $total += self::length($effective[$key]);
        if ($total > 0) return $total;
        foreach ($effective as $key => $value) if (!in_array((string)$key, self::IGNORED_KEYS, true)) $total += self::length($value);
        return $total;
    }

    public static function configuration(array $product): array
    {
        $source = self::object($product['source_payload'] ?? []);
        $metadata = self::object($source['market_metadata'] ?? []);
        $defaults = self::object($metadata['default_params'] ?? []);
        OpenPolicy::validateInput($defaults); self::assertDefaultVoice($defaults);
        foreach (['callback_url','webhook_url','callbackUrl','storage','mode'] as $name) if (array_key_exists($name,$defaults)) throw new OpenException('pricing_unavailable','默认回调或执行控制参数尚未接入公开协议',503);
        foreach (self::TEXT_KEYS as $name) if (isset($defaults[$name]) && self::length($defaults[$name]) > 0) throw new OpenException('pricing_unavailable','字符接口存在尚未适配的默认计费文本',503);
        $schema = self::object($metadata['params_schema'] ?? []);$properties=self::object($schema['properties']??$schema);
        foreach ($defaults as $name=>$value) if (!isset($properties[$name])) throw new OpenException('pricing_unavailable','默认参数未声明在原协议中',503);
        return ['character_defaults'=>$defaults,'character_defaults_digest'=>OpenPolicy::fingerprint($defaults)];
    }

    /** The gateway uses this same projection immediately before sending the accepted JSON request. */
    public static function transportParameters(array $operation, array $raw): array
    {
        if (!is_string($raw['text'] ?? null) || $raw['text'] === '' || !mb_check_encoding($raw['text'],'UTF-8')) throw new OpenException('invalid_request','该 TTS 原协议需要请求中显式提供有效 UTF-8 text');
        $defaults = self::object($operation['character_defaults'] ?? []);
        if (OpenPolicy::fingerprint($defaults) !== ($operation['character_defaults_digest'] ?? '')) throw new OpenException('invalid_contract','已冻结字符默认参数摘要无效',503);
        $effective = array_merge($defaults,$raw);
        self::assertDefaultVoice($effective);
        return $effective;
    }

    /** Snapshot prices supplied by the existing product/SKU/tenant retail resolver. */
    public static function quote(array $product, array $sku, float $retail, array $raw, int $bodyLimit, int $wireBytes, array $operation): array
    {
        if (!self::supports($product, $sku) || (int)($sku['product_id'] ?? 0) !== (int)($product['id'] ?? 0)) throw new OpenException('pricing_unavailable','该字符 SKU 尚未接入确定的输入计量',503);
        if ($bodyLimit <= 0 || $wireBytes < 0 || $wireBytes > $bodyLimit) throw new OpenException('payload_too_large','请求超出实际 JSON 字节限制',413);
        $configuration = self::configuration($product);
        if ($configuration['character_defaults_digest'] !== ($operation['character_defaults_digest'] ?? '')) throw new OpenException('pricing_configuration_changed','字符接口默认参数已变化，请重新审核契约',503);
        $effective = self::transportParameters($operation,$raw);
        $projectedBytes = strlen(OpenStore::encode((object)$effective));
        if ($projectedBytes > $bodyLimit) throw new OpenException('payload_too_large','合并后的实际 JSON 请求超出字节限制',413);
        foreach (self::object($sku['locked_params'] ?? []) as $name => $value) {
            if (str_starts_with((string)$name, '_')) continue;
            if (!array_key_exists($name,$effective) || OpenPolicy::fingerprint(['v'=>$effective[$name]]) !== OpenPolicy::fingerprint(['v'=>$value])) throw new OpenException('invalid_sku','参数与固定字符 SKU 不一致');
        }
        $quantity = self::count($effective);
        // Each raw UTF-8 code point occupies at least one actual wire byte. Defaults are deterministic additions.
        $hardMaximum = $bodyLimit;
        if ($quantity <= 0 || $quantity > $hardMaximum) throw new OpenException('invalid_request','字符数量超出原请求硬边界');
        $unitSize = (float)($sku['usage_unit_size'] ?? 0);
        $cost = (float)($sku['platform_sale_points'] ?? $sku['sale_points'] ?? -1);
        if ($unitSize < 1 || !is_finite($unitSize) || $cost < 0 || !is_finite($cost) || $retail < 0 || !is_finite($retail)) throw new OpenException('pricing_unavailable','字符 SKU 单位或售价无效',503);
        return ['user'=>round(MarketUsageSettlementService::price($retail,$quantity,$sku),2),'tenant'=>round(MarketUsageSettlementService::price($cost,$quantity,$sku),2),'snapshot'=>[
            'character_input'=>['quantity'=>$quantity,'unit_size'=>$unitSize,'hard_maximum'=>$hardMaximum,'voice_policy'=>'supplier_default_only','effective_hash'=>OpenPolicy::fingerprint($effective),'defaults_digest'=>$configuration['character_defaults_digest']],
            'rules'=>[['sku_id'=>(int)$sku['id'],'sku_key'=>(string)$sku['sku_key'],'basis'=>'character_input','units'=>$quantity,'max_units'=>$quantity,'unit_size'=>$unitSize,'usage_unit'=>'character','prices'=>['user'=>$retail,'tenant'=>$cost]]],
        ]];
    }

    public static function settledUnits(array $snapshot, bool $failed): float
    {
        if ($failed) return 0.;
        $quantity = $snapshot['quantity'] ?? null;
        if (!is_int($quantity) || $quantity <= 0) throw new OpenException('pending_usage','已受理字符快照缺失',503);
        return (float)$quantity;
    }

    private static function assertDefaultVoice(array $value): void
    {
        foreach ($value as $name => $item) {
            if (in_array((string)$name,['reference_id','voice_id','voice','voices','speaker_id','speaker','speakers','model_id','audio','references','reference_audio','audio_url'],true) && $item !== null && $item !== '' && $item !== []) throw new OpenException('resource_adapter_required','私有或指定音色尚需当前用户归属映射；请使用供应商默认音色',503);
            if ($item instanceof \stdClass) $item = (array)$item;
            if (is_array($item)) self::assertDefaultVoice($item);
        }
    }

    private static function length(mixed $value): int
    {
        if (is_string($value)) {
            if (!mb_check_encoding($value,'UTF-8')) throw new OpenException('invalid_request','字符输入必须为有效 UTF-8');
            return mb_strlen($value,'UTF-8');
        }
        // Gateway JSON objects must become arrays for identical supplier recursive counting.
        if ($value instanceof \stdClass) $value = (array)$value;
        if (!is_array($value)) return 0;
        $total = 0; foreach ($value as $item) $total += self::length($item); return $total;
    }
    private static function object(mixed $value): array { return $value instanceof \stdClass ? (array)$value : (is_array($value) ? $value : []); }
}
