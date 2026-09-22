<?php
declare(strict_types=1);

namespace app\common\service\app\aigc_short_drama\canvas_agent;

use app\common\service\FileService;
use app\common\service\power\MarketFileQaAppRuntimeService;
use RuntimeException;
use think\facade\Db;

/**
 * Owns the durable PDF/Office attachment boundary for canvas Agent messages.
 *
 * A browser can only reference a registered canvas_document asset.  Its raw
 * URL never enters conversation history or model context: after a user has
 * confirmed the actual-usage parser quote, this class freezes bounded parser
 * output as untrusted text material for the conversation store.
 */
final class ConversationDocuments
{
    private const ASSET_TYPE = 'canvas_document';
    private const MAX_MATERIAL_BYTES = 102400;

    public static function quote(int $tenant, int $user, int $canvas, int $assetId): array
    {
        self::asset($tenant, $user, $canvas, $assetId);
        $quote = MarketFileQaAppRuntimeService::quote($tenant);
        $payload = self::quotePayload($assetId, $quote);
        return self::view(self::asset($tenant, $user, $canvas, $assetId), [
            'quote' => $quote,
            'quote_hash' => self::hash($payload),
            'requires_confirmation' => true,
        ]);
    }

    /** Submit only after the exact quote shown to the user is confirmed. */
    public static function confirm(int $tenant, int $user, int $canvas, int $assetId, string $quoteHash): array
    {
        $asset = self::asset($tenant, $user, $canvas, $assetId);
        $meta = self::meta($asset);
        $state = (string)($meta['document_parse_status'] ?? 'awaiting_confirmation');
        if ($state === 'ready') return self::view($asset);
        if ($state === 'parsing') return self::detail($tenant, $user, $canvas, $assetId);
        if (!in_array($state, ['awaiting_confirmation', 'failed', 'canceled'], true)) {
            throw new RuntimeException('DOCUMENT_INVALID_STATE');
        }
        $quote = MarketFileQaAppRuntimeService::quote($tenant);
        if (!hash_equals(self::hash(self::quotePayload($assetId, $quote)), $quoteHash)) {
            throw new RuntimeException('DOCUMENT_QUOTE_EXPIRED');
        }
        $url = FileService::getFileUrlByStorage(
            (string)$asset['uri'],
            (string)($asset['storage_scope'] ?? ''),
            (string)($asset['storage_engine'] ?? ''),
            (string)($asset['storage_domain'] ?? '')
        );
        if (!is_string($url) || trim($url) === '') throw new RuntimeException('DOCUMENT_URL_UNAVAILABLE');
        $request = [
            'idempotency_key' => 'canvas-agent-document-' . $assetId,
            'file_urls' => [$url],
            'parse_mode' => 'auto',
            'preserve_original' => true,
            'metadata' => ['canvas_id' => $canvas, 'asset_id' => $assetId, 'source' => 'canvas_agent_document'],
        ];
        try {
            $reserved = MarketFileQaAppRuntimeService::reserve(
                $tenant,
                $user,
                'canvas_agent_document_parse',
                (string)$assetId,
                [],
                $request,
                1,
                'aigc_short_drama',
                'aigc_short_drama_asset'
            );
            MarketFileQaAppRuntimeService::linkBusinessTask((int)$reserved['app_task_id'], $assetId);
            $submitted = MarketFileQaAppRuntimeService::submit((int)$reserved['consumption_id'], $request);
            $meta = array_merge($meta, [
                'document_parse_status' => 'parsing',
                'document_error' => '',
                'document_consumption_id' => (int)$reserved['consumption_id'],
                'document_provider_task_id' => (string)($submitted['provider_task_id'] ?? ''),
                'document_quote' => $quote,
                'document_material' => '',
            ]);
            self::save($assetId, 'processing', $meta);
        } catch (\Throwable $error) {
            $meta = array_merge($meta, [
                'document_parse_status' => 'failed',
                'document_error' => '文档解析提交失败，请稍后重试',
            ]);
            self::save($assetId, 'failed', $meta);
            throw $error instanceof RuntimeException ? $error : new RuntimeException('DOCUMENT_PARSE_SUBMIT_FAILED', 0, $error);
        }
        return self::detail($tenant, $user, $canvas, $assetId);
    }

    /** Polls a known local consumption and stores only bounded parser material. */
    public static function detail(int $tenant, int $user, int $canvas, int $assetId): array
    {
        $asset = self::asset($tenant, $user, $canvas, $assetId);
        $meta = self::meta($asset);
        if (($meta['document_parse_status'] ?? '') !== 'parsing') return self::view($asset);
        $consumptionId = (int)($meta['document_consumption_id'] ?? 0);
        if ($consumptionId <= 0) throw new RuntimeException('DOCUMENT_PARSE_RECORD_MISSING');
        try {
            $result = MarketFileQaAppRuntimeService::refresh($consumptionId);
            $status = (string)($result['status'] ?? 'running');
            if ($status === 'success') {
                $material = self::extractMaterial((array)($result['result'] ?? []));
                if ($material === '') throw new RuntimeException('DOCUMENT_PARSE_EMPTY');
                $meta = array_merge($meta, [
                    'document_parse_status' => 'ready',
                    'document_error' => '',
                    'document_material' => $material,
                ]);
                self::save($assetId, 'ready', $meta);
            } elseif ($status === 'failed') {
                $meta = array_merge($meta, [
                    'document_parse_status' => 'failed',
                    'document_error' => '文档解析失败，请重试或更换文件',
                ]);
                self::save($assetId, 'failed', $meta);
            }
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'DOCUMENT_PARSE_EMPTY') {
                $meta = array_merge($meta, ['document_parse_status' => 'failed', 'document_error' => '文档没有可用的解析内容']);
                self::save($assetId, 'failed', $meta);
            } else {
                throw $error;
            }
        } catch (\Throwable) {
            // A temporary polling failure must not erase a running paid task.
        }
        return self::view(self::asset($tenant, $user, $canvas, $assetId));
    }

    /** Converts an owned, successfully parsed document to inert text context. */
    public static function material(int $tenant, int $user, int $canvas, int $assetId, string $name): array
    {
        $asset = self::asset($tenant, $user, $canvas, $assetId);
        $meta = self::meta($asset);
        if ((string)($meta['document_parse_status'] ?? '') !== 'ready') throw new RuntimeException('DOCUMENT_NOT_READY');
        $material = (string)($meta['document_material'] ?? '');
        if ($material === '' || !mb_check_encoding($material, 'UTF-8')) throw new RuntimeException('DOCUMENT_NOT_READY');
        // ConversationTextContext deliberately accepts only inline .txt/.md
        // material.  Keep the original filename inside the untrusted body,
        // while using a server-owned synthetic .md name at that boundary.
        return ['type' => 'text', 'name' => 'document-' . $assetId . '.md', 'content' => "来源文档：" . $name . "\n" . $material];
    }

    private static function asset(int $tenant, int $user, int $canvas, int $assetId): array
    {
        ConversationStore::assertCanvasAccess($tenant, $user, $canvas);
        if ($assetId <= 0) throw new RuntimeException('DOCUMENT_NOT_FOUND');
        $asset = Db::name('aigc_short_drama_asset')->where([
            'id' => $assetId, 'tenant_id' => $tenant, 'user_id' => $user,
            'canvas_id' => $canvas, 'asset_type' => self::ASSET_TYPE, 'delete_time' => 0,
        ])->find();
        if (!$asset) throw new RuntimeException('DOCUMENT_NOT_FOUND');
        return $asset;
    }

    private static function save(int $assetId, string $status, array $meta): void
    {
        Db::name('aigc_short_drama_asset')->where('id', $assetId)->update([
            'status' => $status,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'update_time' => time(),
        ]);
    }

    private static function view(array $asset, array $extra = []): array
    {
        $meta = self::meta($asset);
        return array_merge([
            'asset_id' => (int)$asset['id'],
            'name' => (string)$asset['title'],
            'status' => (string)($meta['document_parse_status'] ?? 'awaiting_confirmation'),
            'error' => (string)($meta['document_error'] ?? ''),
            'requires_confirmation' => (string)($meta['document_parse_status'] ?? 'awaiting_confirmation') === 'awaiting_confirmation',
        ], $extra);
    }

    private static function meta(array $asset): array
    {
        try {
            $meta = json_decode((string)($asset['meta_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            return is_array($meta) ? $meta : [];
        } catch (\Throwable) { return []; }
    }

    private static function quotePayload(int $assetId, array $quote): array
    {
        return ['asset_id' => $assetId, 'market_product_id' => (int)($quote['market_product_id'] ?? 0), 'market_sku_id' => (int)($quote['market_sku_id'] ?? 0), 'billing_unit' => (string)($quote['billing_unit'] ?? ''), 'user_unit_points' => (float)($quote['user_unit_points'] ?? 0), 'settlement_mode' => (string)($quote['settlement_mode'] ?? '')];
    }

    private static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function extractMaterial(array $result): string
    {
        if ($result === []) return '';
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!is_string($json) || $json === '') return '';
        return mb_strcut($json, 0, self::MAX_MATERIAL_BYTES, 'UTF-8');
    }
}
