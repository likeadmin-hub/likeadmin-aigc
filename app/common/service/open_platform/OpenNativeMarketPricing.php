<?php
namespace app\common\service\open_platform;

use app\common\service\power\{MarketImageModelRuntimeService as Images, MarketVideoRuntimeService as Videos, MarketUsageSettlementService as Usage};

/** Pricing-only projection. Never returns a replacement transport payload. */
final class OpenNativeMarketPricing
{
    public const ADAPTER = 'native_media_v1';

    public static function supports(array $product): bool
    {
        return ($product['resource_type'] ?? '') === 'model'
            && in_array($product['model_type'] ?? '', ['image', 'video'], true)
            || ($product['resource_type'] ?? '') === 'app_api' && Videos::isSupportedAppProduct($product);
    }

    /** Identity is supplied by the bound contract, never by a pricing override in the body. */
    public static function bind(array $product, array $raw, int $skuId, array $sku = []): array
    {
        if (!self::supports($product) || $skuId <= 0) throw new OpenException('pricing_unavailable', '该商品尚未接入原生价格映射', 503);
        $isImage = ($product['model_type'] ?? '') === 'image' && $product['resource_type'] === 'model';
        $selection = ['market_product_id' => (int)$product['id'], 'market_sku_id' => $skuId, 'resource_type' => $product['resource_type']];
        $locked = self::object($sku['locked_params'] ?? []);
        foreach (['model' => 'upstream_model_code', 'channel' => 'upstream_channel_code'] as $name => $column) {
            $value = self::alias($raw, [$name]);
            $expected = $name === 'model' && $product['resource_type'] === 'app_api' ? ($locked['model'] ?? $product[$column] ?? '') : ($product[$column] ?? '');
            if ($value !== null && (string)$value !== (string)$expected) throw new OpenException('invalid_sku', '模型或渠道与所选商品不一致');
        }
        // These fields can be independent wire dimensions (quality=high, resolution=2k).
        // Project only the dimension which the native SKU uses for its price selector.
        $dimensions = [];
        foreach (['quality', 'resolution', 'image_size'] as $name) {
            $value = self::alias($raw, [$name]);
            if ($value !== null) $dimensions[$name] = $value;
            if ($value !== null && isset($locked[$name]) && strcasecmp($value, (string)$locked[$name]) !== 0) throw new OpenException('invalid_sku', '参数与所选 SKU 的锁定规格不一致');
        }
        $priority = $isImage ? ['quality','resolution','image_size'] : ['resolution','quality','image_size'];
        foreach ($priority as $name) if (isset($locked[$name]) && (string)$locked[$name] !== '') { $selection['quality'] = (string)$locked[$name]; break; }
        if (!isset($selection['quality'])) foreach ($priority as $name) if (isset($dimensions[$name])) { $selection['quality'] = $dimensions[$name]; break; }
        foreach (['ratio' => ['ratio', 'aspect_ratio'], 'duration' => ['duration', 'seconds', 'video_duration']] as $name => $aliases) {
            $value = self::alias($raw, $aliases, $name === 'duration');
            if ($value !== null) $selection[$name] = $value;
        }
        $size = self::alias($raw, ['size']);
        if ($size !== null) {
            if (preg_match('/^\d+\s*:\s*\d+$/D', (string)$size)) {
                if (isset($selection['ratio']) && str_replace(' ', '', (string)$selection['ratio']) !== str_replace(' ', '', (string)$size)) throw new OpenException('invalid_request', '图片尺寸与比例参数冲突');
                $selection['ratio'] = $size;
            } else $selection['size'] = $size;
        }
        $quantity = self::alias($raw, ['n', 'quantity'], true) ?? 1;
        if ($quantity < 1) throw new OpenException('invalid_request', '图片数量须为正整数');
        $selection['quantity'] = $quantity;
        $references=self::alias($raw,['reference_images','image_urls','images','urls'],false,true);
        if($references!==null)$selection['reference_images']=$references;
        foreach (['video_urls', 'audio_urls', 'reference_assets', 'first_frame_image', 'last_frame_image', 'generation_method', 'generationMethod'] as $name) {
            $value = self::alias($raw, [$name], false, true);
            if ($value !== null) $selection[$name] = $value;
        }
        // Structured task inputs carry typed references inside messages.
        $input = self::object($raw['input'] ?? []);
        $images = [];
        $structuredMedia = ['video_urls' => [], 'audio_urls' => []];
        foreach (is_array($input['messages'] ?? null) ? $input['messages'] : [] as $message) foreach (is_array(self::object($message)['content'] ?? null) ? self::object($message)['content'] : [] as $part) {
            $part = self::object($part);
            if (isset($part['image'])) $images[] = $part['image'];
            foreach (['video' => 'video_urls', 'audio' => 'audio_urls'] as $field => $target) if (isset($part[$field])) $structuredMedia[$target][] = $part[$field];
        }
        if ($images) {
            if (isset($selection['reference_images']) && OpenPolicy::fingerprint(['v' => $selection['reference_images']]) !== OpenPolicy::fingerprint(['v' => $images])) throw new OpenException('invalid_request', '参考图参数冲突');
            $selection['reference_images'] = $images;
        }
        foreach ($structuredMedia as $target => $values) if ($values) {
            if (isset($selection[$target]) && OpenPolicy::fingerprint(['v' => $selection[$target]]) !== OpenPolicy::fingerprint(['v' => $values])) throw new OpenException('invalid_request', '结构化素材参数冲突');
            $selection[$target] = $values;
        }
        return ['kind' => $isImage ? 'image' : 'video', 'selection' => $selection];
    }

    public static function capture(int $tenantId, array $product, array $raw, int $skuId): array
    {
        $user=(int)(request()->userId??0);if($user<=0)$user=(int)(request()->userInfo['user_id']??0);
        if($user<=0)throw new OpenException('permission_denied','原生价格映射需要已认证的素材归属',403);
        $materials=OpenMaterials::resolve($tenantId,$user,$raw);
        if(OpenPolicy::fingerprint($materials)!==OpenPolicy::fingerprint($raw))throw new OpenException('local_material_mapping_required','原协议透传请使用素材 URL；本地 file_id 尚未接入资源映射');
        $sku = \app\common\model\power\PowerMarketSku::where(['id'=>$skuId,'product_id'=>(int)$product['id'],'status'=>1,'sale_status'=>1])->find();
        if (!$sku) throw new OpenException('invalid_sku', '所选商品规格不可用');
        OpenSkuConsistency::assert($sku->toArray());
        $bound = self::bind($product, $raw, $skuId, $sku->toArray());
        try {
            $captured = $bound['kind'] === 'image'
                ? Images::openPlatformAdmissionQuote($tenantId, $bound['selection'], $bound['selection']['quantity'])
                : Videos::openPlatformAdmissionQuote($tenantId, $bound['selection']);
        } catch (OpenException $e) { throw $e; }
        catch (\Exception $e) { throw new OpenException('invalid_sku', $e->getMessage()); }
        if ((int)$captured['market']['product']['id'] !== (int)$product['id'] || (int)$captured['market']['sku']['id'] !== $skuId) throw new OpenException('invalid_sku', '参数不能改选契约绑定的 SKU');
        if (($captured['quote']['settlement_mode'] ?? '') !== 'reserved' || Usage::isActualUsageSku($captured['market']['sku'])) throw new OpenException('native_usage_boundary_required', '已有 Token SKU 按真实用量计费，当前透传执行尚需可信额度边界', 503);
        if ($bound['kind'] === 'image') {
            try { Images::qwenDimensionSummary($captured['quote']['market_snapshot'], $bound['selection']); }
            catch (\Exception $e) { throw new OpenException('invalid_request',$e->getMessage()); }
        }
        $quote = $captured['quote'];
        return ['user' => round((float)$quote['user_charge_points'], 2), 'tenant' => round((float)$quote['tenant_cost_points'], 2), 'snapshot' => [
            'native_media' => ['kind' => $bound['kind'], 'market_snapshot' => $quote['market_snapshot'], 'submitted_units' => (float)$quote['quantity']],
            'rules' => [['sku_id' => $skuId, 'sku_key' => $captured['market']['sku']['sku_key'], 'basis' => 'native', 'units' => (float)$quote['quantity'], 'max_units' => (float)$quote['quantity'], 'unit_size' => $bound['kind'] === 'image' ? 1. : (float)$quote['billing_unit_size'], 'usage_unit' => $quote['billing_unit'], 'prices' => ['user' => (float)$quote['user_unit_points'], 'tenant' => (float)$quote['tenant_unit_points']]]],
        ]];
    }

    /** null means missing evidence, never a zero-price success. */
    public static function settledUnits(array $native, array $result, bool $failed): ?float
    {
        if ($failed) return 0.;
        if ($native['kind'] === 'video') return Videos::openPlatformSettlementQuantity($native['market_snapshot'], $result);
        $slots = null; $identity = null;
        foreach (['', 'data.', 'result.', 'output.', 'data.result.', 'data.output.', 'data.result.output.'] as $prefix) foreach (['images', 'image_urls', 'results'] as $field) {
            $value = OpenSchema::get($result, $prefix . $field);
            if ($value === null) continue;
            if (!is_array($value) || !array_is_list($value)) return null;
            $candidate = self::imageSlots($value);
            if ($candidate === null) return null;
            // Compare normalized slots, not wrapper/metadata shapes. Equal
            // aliases are one result list; duplicate URLs remain separate slots.
            $fingerprint = OpenPolicy::fingerprint(['slots' => $candidate]);
            if ($identity !== null && $identity !== $fingerprint) return null;
            $identity = $fingerprint; $slots = $candidate;
        }
        $successful = $slots === null ? 0 : count(array_filter($slots, 'is_string'));
        return $successful > 0 ? (($native['market_snapshot']['billing_meter']['basis'] ?? '') === 'call' ? 1. : (float)$successful) : null;
    }

    /** Use the same immutable meter at admission and settlement, including failed-task refunds. */
    public static function settledPrice(float $unitPrice, float $units, array $native, bool $failed): float
    {
        if ($failed) return 0.;
        return \app\common\service\power\MarketGenerationMeter::price($unitPrice, $units, (array)$native['market_snapshot']);
    }

    /** Successful URL slots plus null markers for confirmed unavailable/non-image slots. */
    private static function imageSlots(array $items): ?array
    {
        $slots = [];
        foreach ($items as $item) {
            if ($item instanceof \stdClass) $item = (array)$item;
            if (is_array($item)) {
                if (array_key_exists('type', $item)) {
                    if (!is_string($item['type'])) return null;
                    $type = strtolower(trim($item['type']));
                    if (in_array($type, ['video', 'audio', 'text'], true)) { $slots[] = null; continue; }
                    if (!in_array($type, ['image', 'image_url'], true)) return null;
                }
                if (array_key_exists('transfer_status', $item)) {
                    if (!is_string($item['transfer_status'])) return null;
                    $transfer = strtolower(trim($item['transfer_status']));
                    if (in_array($transfer, ['failed', 'failure', 'error', 'hidden', 'cancelled', 'canceled'], true)) { $slots[] = null; continue; }
                    // Pending/unknown transfer evidence must remain recoverable,
                    // even if the provider supplied an original URL.
                    if (!in_array($transfer, ['success', 'succeeded', 'completed'], true)) return null;
                }
                $url = null;
                foreach (['url', 'image_url', 'image_uri', 'uri', 'image', 'src', 'origin_url', 'download_url'] as $key) if (isset($item[$key]) && is_string($item[$key]) && trim($item[$key]) !== '') { $url = $item[$key]; break; }
                $item = $url;
            }
            if (!is_string($item) || !preg_match('#^(?:https?://[^\s]+|data:image/[a-z0-9.+-]+;base64,[a-z0-9+/=]+|/?(?:uploads|resource)/[^\s]+)$#iD', trim($item))) return null;
            $slots[] = trim($item);
        }
        return $slots;
    }

    private static function object(mixed $value): array { return $value instanceof \stdClass ? (array)$value : (is_array($value) ? $value : []); }
    private static function alias(array $raw, array $names, bool $integer = false, bool $structured = false): mixed
    {
        $values = [];
        foreach ([$raw, self::object($raw['parameters'] ?? []), self::object($raw['params'] ?? [])] as $source) foreach ($names as $name) if (array_key_exists($name, $source)) {
            $value = $source[$name];
            if ($integer) {
                if (!(is_int($value) || is_string($value) && preg_match('/^[1-9]\d*$/D', $value)) || (int)$value < 1) throw new OpenException('invalid_request', '数量或时长必须为正整数');
                $value = (int)$value;
            } elseif (!$structured) {
                if (!is_string($value) || trim($value) === '') throw new OpenException('invalid_request', '价格维度须为非空字符串');
                $value = trim($value);
            }
            $values[] = $value;
        }
        if (!$values) return null;
        $first = OpenPolicy::fingerprint(['v' => $values[0]]);
        foreach ($values as $value) if (OpenPolicy::fingerprint(['v' => $value]) !== $first) throw new OpenException('invalid_request', '同义参数或嵌套参数冲突');
        return $values[0];
    }
}
