<?php

namespace app\common\service\decorate\agent;

use app\common\enum\FileEnum;
use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\file\TenantFile;
use app\common\service\ai\AiTaskResultAssetService;
use app\common\service\decorate\DecorateDataSourceService;
use app\common\service\FileService;
use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\storage\StorageSignedUrlService;
use Exception;

/** Tenant-admin authoring adapter. No request field can opt ordinary users into this payer. */
final class DecorationAgentMediaService
{
    public const BUSINESS_TABLE = 'decorate_agent_image_slot';
    private const MAX_BYTES = 10485760;
    private const MAX_ATTACHMENTS = 5;

    /** URLs and image metadata are resolved from an owned file, never copied from client input. */
    public static function validateAttachments(int $tenantId, array $attachments): array
    {
        self::assertTenant($tenantId);
        if (count($attachments) > self::MAX_ATTACHMENTS) throw new Exception('最多上传5张参考图片');
        $result = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || (int)($attachment['file_id'] ?? 0) <= 0) throw new Exception('附件必须提供文件ID');
            $id = (int)$attachment['file_id'];
            if (isset($result[$id])) throw new Exception('附件不能重复');
            $file = TenantFile::where(['tenant_id' => $tenantId, 'id' => $id, 'source' => FileEnum::SOURCE_ADMIN, 'type' => FileEnum::IMAGE_TYPE])->findOrEmpty();
            $row = $file->isEmpty() ? [] : $file->toArray();
            self::assertAttachmentRow($tenantId, $row);
            $url = self::fileUrl($row);
            [$width, $height] = self::inspectImage(self::readImage($row, $url));
            $uses = $attachment['uses'] ?? ['asset'];
            if (is_string($uses)) $uses = [$uses];
            if (!is_array($uses) || count(array_filter($uses, 'is_string')) !== count($uses) || array_diff($uses, ['asset', 'layout', 'style', 'generation']) !== []) throw new Exception('附件用途无效');
            $result[$id] = ['file_id' => $id, 'url' => $url, 'uses' => array_values(array_unique($uses)), 'width' => $width, 'height' => $height];
        }
        return array_values($result);
    }

    /** Ephemeral transport only: never persist these data URIs in agent context or events. */
    public static function visionReferences(int $tenantId, array $attachments): array
    {
        $validated = self::validateAttachments($tenantId, $attachments);
        $references = [];
        foreach ($validated as $attachment) {
            if (array_intersect($attachment['uses'], ['layout', 'style']) === []) continue;
            // Reload ownership before reading bytes; a removed file must not become a vision input.
            $file = TenantFile::where(['tenant_id' => $tenantId, 'id' => $attachment['file_id'], 'source' => FileEnum::SOURCE_ADMIN, 'type' => FileEnum::IMAGE_TYPE])->findOrEmpty();
            $row = $file->isEmpty() ? [] : $file->toArray();
            self::assertAttachmentRow($tenantId, $row);
            $bytes = self::readImage($row, self::fileUrl($row));
            self::inspectImage($bytes);
            $info = getimagesizefromstring($bytes);
            $references[] = 'data:' . $info['mime'] . ';base64,' . base64_encode($bytes);
        }
        return $references;
    }

    public static function options(int $tenantId): array
    {
        self::assertTenant($tenantId);
        return MarketImageModelRuntimeService::options($tenantId);
    }

    /** Search tenant media plus existing published decoration sources, respecting enabled source flags. */
    public static function search(int $tenantId, string $query, array $allowedSources, array $attachments, int $limit = 12): array
    {
        self::assertTenant($tenantId);
        $limit = max(1, min(50, $limit));
        $needle = mb_strtolower(trim($query));
        $candidates = [];
        if (in_array('upload', $allowedSources, true)) {
            foreach (self::validateAttachments($tenantId, $attachments) as $attachment) {
                if (!in_array('asset', $attachment['uses'], true)) continue;
                $candidates[] = $attachment + ['id' => 'file:' . $attachment['file_id'], 'source' => 'upload', 'title' => '上传参考图'];
            }
        }
        if (in_array('tenant', $allowedSources, true)) {
            $files = TenantFile::where(['tenant_id' => $tenantId, 'source' => FileEnum::SOURCE_ADMIN, 'type' => FileEnum::IMAGE_TYPE]);
            if ($needle !== '') $files->whereLike('name', '%' . $query . '%');
            foreach ($files->order('id', 'desc')->limit($limit)->select()->toArray() as $file) {
                if (!self::imageExtension((string)$file['uri'])) continue;
                $candidates[] = ['id' => 'file:' . $file['id'], 'file_id' => (int)$file['id'], 'url' => self::fileUrl($file), 'source' => 'tenant', 'title' => (string)$file['name'], 'width' => 0, 'height' => 0];
            }
        }
        $refs = [];
        foreach ($allowedSources as $source) {
            if ($source === 'content') foreach (['article', 'image_cases', 'ai_tools', 'app_entries'] as $key) $refs[$key] = ['key' => $key];
            if (is_array($source)) {
                $key = (string)($source['key'] ?? '');
                if (in_array($key, ['article', 'image_cases', 'ai_tools', 'app_entries'], true)) $refs[$key] = $source;
            }
        }
        foreach ($refs as $key => $ref) {
            $params = (array)($ref['params'] ?? []);
            $params['limit'] = 50;
            $rows = DecorateDataSourceService::resolve($key, $params, $tenantId, ['tenant_id' => $tenantId, 'terminal' => 'mobile']);
            $rows = isset($rows['lists']) ? $rows['lists'] : $rows;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $title = (string)($row['title'] ?? $row['name'] ?? '');
                if ($needle !== '' && !str_contains(mb_strtolower($title . ' ' . (string)($row['description'] ?? $row['desc'] ?? '')), $needle)) continue;
                foreach (['image', 'cover_url', 'cover', 'image_url', 'icon'] as $field) {
                    $uri = trim((string)($row[$field] ?? ''));
                    if ($uri === '' || !self::imageExtension($uri)) continue;
                    $candidates[] = ['id' => $key . ':' . (string)($row['id'] ?? $row['app_code'] ?? '') . ':' . $field, 'file_id' => 0, 'url' => FileService::getFileUrl($uri), 'source' => 'content', 'source_key' => $key, 'source_id' => $row['id'] ?? $row['app_code'] ?? '', 'title' => $title, 'width' => 0, 'height' => 0];
                    break;
                }
            }
        }
        $seen = []; $result = [];
        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate['url']])) continue;
            $seen[$candidate['url']] = true; $result[] = $candidate;
            if (count($result) >= $limit) break;
        }
        return $result;
    }

    public static function quote(int $tenantId, array $selection, int $quantity = 1, array $request = []): array
    {
        self::assertTenant($tenantId);
        self::referenceRequest($tenantId, $request);
        $quote = MarketImageModelRuntimeService::quote($tenantId, $selection, $quantity, [], true);
        $quote['actual_tenant_cost_points'] = (float)$quote['tenant_cost_points'];
        $quote['cost_known'] = (string)$quote['settlement_mode'] !== 'actual_usage';
        return $quote;
    }

    /** Persist this receipt in the slot before calling submit; never hide a second submission. */
    public static function reserve(int $tenantId, int $adminId, int $slotId, array $selection, array $request, int $quantity = 1): array
    {
        self::assertTenant($tenantId);
        if ($adminId <= 0 || $slotId <= 0) throw new Exception('装修图片任务身份无效');
        $request = self::referenceRequest($tenantId, $request);
        $receipt = MarketImageModelRuntimeService::reserve($tenantId, 0, 'decoration_agent_image', (string)$slotId, $selection, $request, $quantity, 'system_default', self::BUSINESS_TABLE, [], true);
        $receipt['spent_points'] = (float)self::consumption($tenantId, (int)$receipt['consumption_id'])['reserved_tenant_cost'];
        return $receipt;
    }

    public static function submit(int $consumptionId, array $request): array
    {
        $consumption = self::consumption(0, $consumptionId);
        $request = self::referenceRequest((int)$consumption['tenant_id'], $request);
        $snapshot = (array)$consumption['price_snapshot'];
        $request['quantity'] = max(1, (int)($snapshot['_tenant_admin_image_quantity'] ?? 1));
        MarketImageModelRuntimeService::submit($consumptionId, $request, true);
        return self::poll((int)$consumption['tenant_id'], $consumptionId, false);
    }

    public static function poll(int $tenantId, int $consumptionId, bool $refresh = true): array
    {
        self::assertTenant($tenantId);
        $consumption = self::consumption($tenantId, $consumptionId);
        if ($refresh && in_array((string)$consumption['billing_status'], ['reserved', 'pending_usage'], true)) {
            MarketImageModelRuntimeService::refresh($consumptionId);
            $consumption = self::consumption($tenantId, $consumptionId);
        }
        $summary = (array)$consumption['response_summary'];
        $images = [];
        if ((string)$consumption['run_status'] === 'success' && (string)$consumption['billing_status'] === 'settled') {
            AiTaskResultAssetService::recordConsumptionAssets($consumptionId, true);
            foreach ((array)($summary['images'] ?? []) as $image) {
                if (!is_array($image) || empty($image['image_uri']) || empty($image['storage_scope']) || empty($image['storage_engine'])) continue;
                $images[] = $image + ['url' => FileService::getFileUrlByStorage((string)$image['image_uri'], (string)$image['storage_scope'], (string)$image['storage_engine'], (string)($image['storage_domain'] ?? ''))];
            }
        }
        $status = (string)$consumption['run_status'];
        if ($status === 'success' && (string)$consumption['billing_status'] !== 'settled') $status = 'running';
        if ($status === 'success' && $images === []) $status = 'needs_review';
        if (in_array($status, ['submitting', 'pending_confirmation'], true) || ($status === 'running' && (string)$consumption['upstream_task_id'] === '')) $status = 'needs_review';
        $spent = (string)$consumption['billing_status'] === 'settled' ? (float)$consumption['actual_tenant_cost'] : ((string)$consumption['billing_status'] === 'refunded' ? 0.0 : (float)$consumption['reserved_tenant_cost']);
        return ['consumption_id' => $consumptionId, 'app_task_id' => (int)$consumption['app_task_id'], 'status' => $status, 'images' => $images, 'error' => (string)$consumption['error_message'], 'billing_status' => (string)$consumption['billing_status'], 'spent_points' => $spent, 'actual_spent_points' => (float)$consumption['actual_tenant_cost']];
    }

    private static function consumption(int $tenantId, int $id): AiConsumptionLog
    {
        $consumption = AiConsumptionLog::findOrEmpty($id);
        if ($consumption->isEmpty() || ($tenantId > 0 && (int)$consumption['tenant_id'] !== $tenantId) || (int)$consumption['user_id'] !== 0 || (string)$consumption['app_code'] !== 'system_default' || (string)$consumption['protocol'] !== 'image_generate') throw new Exception('装修图片消耗记录不存在');
        $task = AiAppTask::findOrEmpty((int)$consumption['app_task_id']);
        if ($task->isEmpty() || (string)$task['business_table'] !== self::BUSINESS_TABLE || (int)$task['tenant_id'] !== (int)$consumption['tenant_id']) throw new Exception('装修图片任务不存在');
        return $consumption;
    }

    private static function referenceRequest(int $tenantId, array $request): array
    {
        $request = array_intersect_key($request, array_flip(['prompt', 'negative_prompt', 'ratio', 'aspect_ratio', 'quality', 'size', 'image_size', 'resolution', 'quantity', 'attachments', 'reference_images', 'reference_file_ids']));
        $attachments = self::validateAttachments($tenantId, (array)($request['attachments'] ?? []));
        $trusted = array_column($attachments, 'url');
        if (array_key_exists('reference_file_ids', $request)) {
            if (!is_array($request['reference_file_ids'])) throw new Exception('参考图文件ID无效');
            $byId = array_column($attachments, 'url', 'file_id');
            $references = [];
            foreach ($request['reference_file_ids'] as $id) {
                if (!is_scalar($id) || !preg_match('/^[1-9][0-9]*$/', (string)$id) || !isset($byId[(int)$id])) throw new Exception('参考图必须来自当前租户上传附件');
                $references[] = $byId[(int)$id];
            }
        } elseif (array_key_exists('reference_images', $request)) {
            $references = (array)$request['reference_images'];
            foreach ($references as $reference) if (!is_string($reference) || !in_array($reference, $trusted, true)) throw new Exception('参考图必须来自当前租户上传附件');
        } else {
            $references = array_column(array_filter($attachments, static fn(array $attachment): bool => in_array('generation', $attachment['uses'], true)), 'url');
        }
        $request['reference_images'] = array_values(array_unique($references));
        unset($request['reference_file_ids']);
        unset($request['callback_url']);
        return $request;
    }

    private static function assertAttachmentRow(int $tenantId, array $row): void
    {
        if ((int)($row['id'] ?? 0) <= 0 || (int)($row['tenant_id'] ?? 0) !== $tenantId || (int)($row['source'] ?? -1) !== FileEnum::SOURCE_ADMIN || (int)($row['type'] ?? 0) !== FileEnum::IMAGE_TYPE || !empty($row['delete_time'])) throw new Exception('附件不存在或不属于当前租户后台');
        if (!self::imageExtension((string)($row['uri'] ?? ''))) throw new Exception('附件仅支持JPG、PNG、WEBP图片');
    }

    private static function readImage(array $file, string $url): string
    {
        if ((string)($file['storage_engine'] ?? '') === 'local') {
            $uri = (string)$file['uri'];
            if (preg_match('#^https?://#i', $uri)) $uri = (string)parse_url($uri, PHP_URL_PATH);
            $root = realpath(public_path());
            $path = realpath(public_path() . ltrim($uri, '/'));
            if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) throw new Exception('附件文件不存在');
            if (filesize($path) > self::MAX_BYTES) throw new Exception('附件不能超过10MB');
            $data = file_get_contents($path);
            if ($data === false) throw new Exception('附件读取失败');
            return $data;
        }
        return OpenSafeHttp::get($url, self::MAX_BYTES, 15)['body'];
    }

    private static function inspectImage(string $data): array
    {
        if (strlen($data) > self::MAX_BYTES) throw new Exception('附件不能超过10MB');
        $info = @getimagesizefromstring($data);
        if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] < 1 || $info[1] < 1) throw new Exception('附件仅支持有效的JPG、PNG、WEBP图片');
        return [(int)$info[0], (int)$info[1]];
    }

    private static function imageExtension(string $uri): bool
    { return preg_match('/\.(?:jpe?g|png|webp)(?:[?#]|$)/i', $uri) === 1; }

    private static function fileUrl(array $file): string
    {
        $signed = StorageSignedUrlService::resolve((string)$file['uri'], (string)($file['storage_scope'] ?? ''), (string)($file['storage_engine'] ?? ''), (string)($file['storage_domain'] ?? ''), (int)($file['tenant_id'] ?? 0));
        return $signed ?? FileService::getFileUrlByStorage((string)$file['uri'], (string)($file['storage_scope'] ?? ''), (string)($file['storage_engine'] ?? ''), (string)($file['storage_domain'] ?? ''));
    }

    private static function assertTenant(int $tenantId): void
    { if ($tenantId <= 0) throw new Exception('租户身份无效'); }
}
