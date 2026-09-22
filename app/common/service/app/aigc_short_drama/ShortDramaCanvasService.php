<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_local_redraw\AigcLocalRedrawService;
use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\app\aigc_music\AigcMusicService;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;
use app\common\service\app\aigc_short_drama\canvas_agent\GenerationIntentService;
use app\common\service\power\MarketTextModelRuntimeService;
use app\common\service\FileService;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;

/**
 * Short-drama-owned free canvas persistence and execution facade.
 *
 * This class delegates only to platform generation runtimes, while retaining document ownership,
 * run history and result projection inside the aigc_short_drama namespace.
 */
class ShortDramaCanvasService
{
    private const DOCUMENT_TABLE = 'aigc_short_drama_canvas';
    private const RUN_TABLE = 'aigc_short_drama_canvas_run';
    private const QUOTE_TABLE = 'aigc_short_drama_canvas_quote';
    private const QUOTE_TTL_SECONDS = 600;

    public static function current(int $tenantId, int $userId, int $id = 0): array
    {
        $query = Db::name(self::DOCUMENT_TABLE)->where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ]);
        if ($id > 0) {
            $row = $query->where('id', $id)->find();
            if (!$row) throw new Exception('画布项目不存在或无权访问');
            return self::formatDocument($row, true);
        }
        $row = $query->order('id', 'desc')->find();
        if (!$row) {
            return self::create($tenantId, $userId, []);
        }
        return self::formatDocument($row, true);
    }

    public static function create(int $tenantId, int $userId, array $params): array
    {
        $title = mb_substr(trim((string)($params['title'] ?? '')) ?: '无标题空间', 0, 40);
        $now = time();
        $id = Db::name(self::DOCUMENT_TABLE)->insertGetId([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'title' => $title,
            'nodes_json' => '[]', 'edges_json' => '[]', 'viewport_json' => '{}',
            'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
        ]);
        return self::formatDocument(Db::name(self::DOCUMENT_TABLE)->where('id', $id)->find(), true);
    }

    public static function lists(int $tenantId, int $userId, array $params = []): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(50, max(1, (int)($params['page_size'] ?? 20)));
        $query = Db::name(self::DOCUMENT_TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0]);
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') $query->whereLike('title', '%' . $keyword . '%');
        $count = (int)(clone $query)->count();
        $rows = $query->order(['update_time' => 'desc', 'id' => 'desc'])->page($pageNo, $pageSize)->select()->toArray();
        return ['lists' => array_map(static fn(array $row): array => self::formatDocument($row), $rows), 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    /** A formal target is opt-in; free canvas reads never require this table. */
    public static function binding(int $tenantId, int $userId, int $canvasId): array
    {
        return ShortDramaCanvasBindingService::current($tenantId, $userId, $canvasId);
    }

    public static function bindProject(int $tenantId, int $userId, array $params): array
    {
        return ShortDramaCanvasBindingService::bind($tenantId, $userId, $params);
    }

    public static function save(int $tenantId, int $userId, array $params): array
    {
        return Db::transaction(function () use ($tenantId, $userId, $params): array {
        // Poster/video projectors already lock this row. Acquire the same lock
        // before reading, merging and saving so a completed poster cannot be
        // overwritten between the read and the document update.
        $document = self::ownedDocument($tenantId, $userId, (int)($params['id'] ?? 0), true);
        GraphService::validateSaveRevision($document, $params);
        // Transitional CAS for the deployed schema. This detects every JSON
        // writer without requiring a live migration. Legacy callers remain
        // compatible until the versioned graph contract is fully rolled out.
        if (array_key_exists('expected_document_token', $params)) {
            $expected = (string)$params['expected_document_token'];
            if (!preg_match('/^[a-f0-9]{64}$/D', $expected) || !hash_equals(self::documentToken($document), $expected)) {
                throw new Exception('VERSION_CONFLICT: 云端画布已变化，本地修改已保留，请重新读取后再编辑');
            }
        }
        $rawNodes=(array)($params['nodes']??[]);
        // Validate before legacy normalization can discard malformed entries.
        $nodes=((int)($document['schema_version']??1)>=2 || array_key_exists('expected_revision',$params))
            ? GraphService::sanitizeManualNodes($document,$rawNodes) : $rawNodes;
        $nodes = self::normalizeNodes($nodes);
        $removed = array_unique(array_merge(
            self::decode((string)($document['removed_node_ids_json'] ?? '[]')),
            array_map('strval', (array)($params['removed_node_ids'] ?? []))
        ));
        // Explicit undo/recreation with the same ID restores a node; missing
        // snapshots alone are not deletion evidence and must remain recoverable.
        $present = array_map(static fn(array $node): string => (string)$node['id'], $nodes);
        $removed = array_values(array_diff($removed, $present));
        $nodes = self::mergePersistedVideoPosters($nodes, self::decode((string)($document['nodes_json'] ?? '')));
        $edges = self::normalizeEdges((array)($params['edges'] ?? []), $nodes);
        self::queueVideoPosters($tenantId, $userId, (int)$document['id'], $nodes, false);
        $title = trim((string)($params['title'] ?? $document['title']));
        $title = mb_substr($title ?: '无标题空间', 0, 40);
        GraphService::persistLockedDocument($document, [
            'title' => $title, 'nodes_json' => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'edges_json' => json_encode($edges, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'removed_node_ids_json' => json_encode($removed),
            'viewport_json' => json_encode((array)($params['viewport'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'update_time' => time(),
        ] + (array_key_exists('expected_revision', $params) ? ['schema_version'=>2] : []));
        self::queueVideoPosters($tenantId, $userId, (int)$document['id'], $nodes);
        return self::currentById($tenantId, $userId, (int)$document['id']);
        });
    }

    /**
     * Persist a still image for one owned video node.
     *
     * This deliberately accepts a canvas/node pair instead of a URL. It keeps
     * the request tenant-scoped and lets the server read object storage without
     * relying on the browser's CORS and Content-Disposition behaviour.
     */
    public static function captureVideoFrame(int $tenantId, int $userId, array $params): array
    {
        $canvasId = (int)($params['canvas_id'] ?? 0);
        if ($canvasId <= 0) throw new Exception('缺少画布项目');
        $document = self::ownedDocument($tenantId, $userId, $canvasId);
        $nodeId = trim((string)($params['node_id'] ?? ''));
        if ($nodeId === '') throw new Exception('缺少视频节点');
        if (!empty($params['job_id'])) {
            return ShortDramaCanvasPosterJobService::frameStatus($tenantId, $userId, $canvasId, $nodeId, (int)$params['job_id']);
        }
        $node = null;
        foreach (self::decode((string)($document['nodes_json'] ?? '[]')) as $item) {
            if ((string)($item['id'] ?? '') === $nodeId) {
                $node = $item;
                break;
            }
        }
        if (!is_array($node)) throw new Exception('视频节点不存在或已被删除');
        $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
        $isVideo = (string)($node['type'] ?? '') === 'video'
            || str_starts_with(strtolower((string)($metadata['mimeType'] ?? $metadata['mime_type'] ?? '')), 'video/');
        if (!$isVideo) throw new Exception('只能对视频节点截帧');

        $uri = self::canvasStoredUri((string)($metadata['video_url'] ?? $metadata['url'] ?? ''));
        if ($uri === '') throw new Exception('该视频尚未保存到可截帧的存储');
        $mode = strtolower(trim((string)($params['mode'] ?? 'current')));
        if (!in_array($mode, ['first', 'current', 'last'], true)) $mode = 'current';
        $duration = max(0, min(28800, (float)($params['duration'] ?? 0)));
        $requestedTime = max(0, min(28800, (float)($params['time'] ?? 0)));
        $time = match ($mode) {
            'first' => $requestedTime,
            'last' => $requestedTime > 0 ? $requestedTime : max(0.001, $duration - 0.05),
            default => $requestedTime,
        };
        if ($time <= 0) $time = 0.001;
        // A playhead at duration is beyond the final decodable frame.
        if ($duration > 0) $time = min($time, max(0.001, $duration - 0.05));

        $jobId = ShortDramaCanvasPosterJobService::enqueue(
            $tenantId,
            $userId,
            $canvasId,
            $nodeId,
            $uri,
            (string)($metadata['storage_scope'] ?? ''),
            (string)($metadata['storage_engine'] ?? ''),
            (string)($metadata['storage_domain'] ?? ''),
            $time
        );
        return ShortDramaCanvasPosterJobService::frameStatus($tenantId, $userId, $canvasId, $nodeId, $jobId);
    }

    /** Reuse native canvas image processing, with short-drama ownership checks. */
    public static function editImage(int $tenantId, int $userId, array $params): array
    {
        $id = (int)($params['canvas_id'] ?? 0);
        if ($id <= 0) throw new Exception('缺少画布项目');
        $document = self::ownedDocument($tenantId, $userId, $id);
        foreach (self::decode((string)$document['nodes_json']) as $node) {
            if ((string)$node['id'] !== (string)($params['node_id'] ?? '')) continue;
            if (($node['type'] ?? '') !== 'image') throw new Exception('只能编辑图片节点');
            $meta = (array)($node['metadata'] ?? []);
            $source = (string)($meta['image'] ?? $meta['url'] ?? '');
            if ($source === '' || str_starts_with($source, 'blob:')) throw new Exception('请等待图片上传完成');
            // Never allow the request to choose a different source file.
            $params['source_url'] = self::imageProcessingSource($tenantId, $source, $meta);
            return match ((string)($params['operation'] ?? '')) {
                'crop' => AigcCanvasService::cropImage($tenantId, $userId, $params),
                'transform' => AigcCanvasService::rotateImage($tenantId, $userId, $params),
                default => throw new Exception('不支持的图片编辑操作'),
            };
        }
        throw new Exception('图片节点不存在或已被删除');
    }

    private static function imageProcessingSource(int $tenantId, string $source, array $meta): string
    {
        $uri = self::canvasStoredUri($source);
        if ($uri === '' || str_contains($uri, '..') || str_contains($uri, "\0")) throw new Exception('图片存储地址无效');
        $config = StorageConfigService::getStoredFileConfig($tenantId, $meta['storage_scope'] ?? null, $meta['storage_engine'] ?? null);
        $engine = (string)($config['default'] ?? 'local');
        if ($engine === 'local') return $uri;
        // Resolve the host from server-managed storage configuration, never a
        // client-supplied URL. cURL handles TLS/proxies consistently with uploads.
        $url = FileService::getFileUrlByStorage($uri, (string)($config['scope'] ?? 'tenant'), $engine, StorageConfigService::getStorageDomain($config));
        $content = '';
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$content): int {
                if (strlen($content) + strlen($chunk) > 20 * 1024 * 1024) return 0;
                $content .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!$ok || $status !== 200 || $content === '') throw new Exception('图片读取失败，请稍后重试');
        $info = @getimagesizefromstring($content);
        if (!$info || (int)$info[0] * (int)$info[1] > 40000000) throw new Exception('图片格式或尺寸不支持编辑');
        return 'data:' . (string)$info['mime'] . ';base64,' . base64_encode($content);
    }

    /**
     * Soft-delete only the canvas workspace and its execution projections.
     * Generated assets remain part of the user's historical asset library.
     */
    public static function delete(int $tenantId, int $userId, int $id): array
    {
        $document = self::ownedDocument($tenantId, $userId, $id);
        Db::transaction(function () use ($tenantId, $userId, $document): void {
            $running = Db::name(self::RUN_TABLE)->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'canvas_id' => (int)$document['id'],
                'delete_time' => 0,
            ])->whereIn('status', ['pending', 'running'])->lock(true)->count();
            if ($running > 0) throw new Exception('请等待画布任务完成后删除项目');

            $time = time();
            Db::name(self::DOCUMENT_TABLE)->where([
                'id' => (int)$document['id'],
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'delete_time' => 0,
            ])->update(['delete_time' => $time, 'update_time' => $time]);
            Db::name(self::RUN_TABLE)->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'canvas_id' => (int)$document['id'],
                'delete_time' => 0,
            ])->update(['delete_time' => $time, 'update_time' => $time]);
        });
        return ['id' => (int)$document['id']];
    }

    public static function submit(int $tenantId, int $userId, array $params): array
    {
        $document = self::ownedDocument($tenantId, $userId, (int)($params['canvas_id'] ?? 0));
        $nodeId = trim((string)($params['node_id'] ?? ''));
        $type = strtolower(trim((string)($params['type'] ?? '')));
        if (!in_array($type, ['text', 'image', 'video', 'audio'], true)) throw new Exception('不支持的短剧画布节点类型');
        if ($nodeId === '') throw new Exception('缺少画布节点');
        $savedNode = null;
        foreach (self::decode((string)($document['nodes_json'] ?? '[]')) as $candidate) {
            if ((string)($candidate['id'] ?? '') === $nodeId) { $savedNode = $candidate; break; }
        }
        if (!$savedNode) throw new Exception('NODE_NOT_FOUND: 请先保存节点，已删除的节点不能生成');
        if ((string)($savedNode['type'] ?? '') !== $type) throw new Exception('NODE_TYPE_MISMATCH: 节点类型已变化，请重新读取画布');
        $params=self::withGraphReferenceInputs($document,$nodeId,$params);
        $payload = self::generationPayload($type, $params, $tenantId, $userId, (int)$document['id']);
        // Resolve on the server so disabled, cross-tenant and stale Skills
        // cannot be submitted by replaying a saved composer selection.
        if ((int)($params['skill_id'] ?? 0) > 0) {
            $skill = ShortDramaSkillService::resolveForTask($tenantId, $params);
            $payload = self::applyComposerSkill($type, $payload, $params, $skill);
        }
        $now = time();
        $runId = Db::name(self::RUN_TABLE)->insertGetId([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'canvas_id' => (int)$document['id'], 'node_id' => $nodeId,
            'node_type' => $type, 'status' => 'running', 'progress' => 5,
            'request_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_json' => '{}', 'error' => '', 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
        ]);
        try {
            $result = self::executeGenerationPayload($type, $tenantId, $userId, $payload);
            // Text generation is a synchronous market request. Its successful
            // response carries content rather than an asynchronous task status,
            // so treating an omitted status as "running" leaves the canvas node
            // polling forever even though the provider has already finished.
            $status = $type === 'text'
                ? 'success'
                : self::normalizeStatus((string)($result['status'] ?? 'running'));
            Db::name(self::RUN_TABLE)->where('id', $runId)->update([
                'provider_task_id' => (string)($result['image_task_id'] ?? $result['task_id'] ?? $result['id'] ?? ''),
                'status' => $status, 'progress' => $status === 'success' ? 100 : 25,
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => time(),
            ]);
            if ($type === 'text') {
                MarketTextModelRuntimeService::bindBusinessTask(
                    (int)($result['app_task_id'] ?? 0),
                    self::RUN_TABLE,
                    $runId
                );
            }
            self::syncShortDramaTask($runId);
        } catch (\Throwable $e) {
            Db::name(self::RUN_TABLE)->where('id', $runId)->update([
                'status' => 'failed', 'progress' => 0, 'error' => mb_substr($e->getMessage(), 0, 500), 'update_time' => time(),
            ]);
            self::syncShortDramaTask($runId);
            throw $e instanceof Exception ? $e : new Exception('短剧画布任务提交失败，请稍后重试');
        }
        return self::runDetail($tenantId, $userId, $runId);
    }

    /**
     * Price a video request without creating a task or reserving points.
     * The durable quote binds the exact request key and server-normalized
     * selection, so the browser cannot reuse a confirmation after changing a
     * model, duration, resolution or an owned reference-asset version.
     */
    public static function quote(int $tenantId, int $userId, array $params): array
    {
        $document = self::ownedDocument($tenantId, $userId, (int)($params['canvas_id'] ?? 0));
        $nodeId = trim((string)($params['node_id'] ?? ''));
        $type = strtolower(trim((string)($params['type'] ?? '')));
        if ($type !== 'video') throw new Exception('QUOTE_UNSUPPORTED_NODE_TYPE');
        self::assertGenerationNode($document, $nodeId, $type);
        $key = trim((string)($params['request_key'] ?? ''));
        self::assertRequestKey($key);
        $params=self::withGraphReferenceInputs($document,$nodeId,$params);
        $payload = self::generationPayload($type, $params, $tenantId, $userId, (int)$document['id']);
        $quote = AigcVideoService::estimate($tenantId, $payload);
        $quoteInput = self::quoteInputForDocument($document, $nodeId, $payload);
        $inputHash = hash('sha256', self::json($quoteInput));
        $now = time();
        $token = bin2hex(random_bytes(24));
        $expiresAt = $now + self::QUOTE_TTL_SECONDS;
        Db::name(self::QUOTE_TABLE)->insert([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'canvas_id' => (int)$document['id'], 'node_id' => $nodeId,
            'request_key' => $key, 'quote_token' => $token, 'input_hash' => $inputHash,
            'request_json' => self::json($quoteInput), 'quote_json' => self::json(self::publicQuote($quote)),
            'status' => 'quoted', 'expires_at' => $expiresAt, 'confirmed_at' => 0, 'create_time' => $now, 'update_time' => $now,
        ]);
        return ['quote_token' => $token, 'status' => 'quoted', 'expires_at' => $expiresAt, 'quote' => self::publicQuote($quote)];
    }

    /** Explicit user acknowledgement only; it never submits a Provider task. */
    public static function confirmQuote(int $tenantId, int $userId, array $params): array
    {
        $canvasId = (int)($params['canvas_id'] ?? 0);
        $nodeId = trim((string)($params['node_id'] ?? ''));
        $token = trim((string)($params['quote_token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{48}$/D', $token)) throw new Exception('INVALID_QUOTE_TOKEN');
        self::ownedDocument($tenantId, $userId, $canvasId);
        return Db::transaction(function () use ($tenantId, $userId, $canvasId, $nodeId, $token): array {
            $row = Db::name(self::QUOTE_TABLE)->where([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'canvas_id' => $canvasId, 'node_id' => $nodeId, 'quote_token' => $token,
            ])->lock(true)->find();
            if (!$row) throw new Exception('QUOTE_NOT_FOUND');
            if ((int)$row['expires_at'] < time()) throw new Exception('QUOTE_EXPIRED');
            if (!in_array((string)$row['status'], ['quoted', 'confirmed'], true)) throw new Exception('QUOTE_CONFIRMATION_REQUIRED');
            if ((string)$row['status'] === 'quoted') {
                Db::name(self::QUOTE_TABLE)->where('id', (int)$row['id'])->update(['status' => 'confirmed', 'confirmed_at' => time(), 'update_time' => time()]);
                $row['status'] = 'confirmed';
            }
            return ['quote_token' => (string)$row['quote_token'], 'status' => 'confirmed', 'expires_at' => (int)$row['expires_at'], 'quote' => self::decode((string)$row['quote_json'])];
        });
    }

    /** P1 integration boundary, deliberately not routed until recovery/UI gates pass. */
    public static function submitIdempotent(int $tenantId, int $userId, array $params): array
    {
        $document=self::ownedDocument($tenantId,$userId,(int)($params['canvas_id']??0));
        $nodeId=trim((string)($params['node_id']??''));
        $type=strtolower(trim((string)($params['type']??'')));
        if (!in_array($type,['text','image','video','audio'],true)) throw new Exception('不支持的短剧画布节点类型');
        // Validate the saved target before any video-specific confirmation
        // policy. A stale/deleted node must never be reported as a pricing
        // issue, nor cause a quote lookup.
        self::assertGenerationNode($document, $nodeId, $type);
        $nodes=self::decode((string)($document['nodes_json']??'[]'));
        foreach ($nodes as $node) {
            $metadata=is_array($node['metadata']??null)?$node['metadata']:[];
            if ((string)($node['id']??'')===$nodeId && !empty($metadata['workflow_audio_disabled'])) {
                throw new Exception('WORKFLOW_AUDIO_GENERATION_UNAVAILABLE');
            }
        }
        $edges=self::decode((string)($document['edges_json']??'[]'));
        $params=self::withGraphReferenceInputs($document,$nodeId,$params);
        $key=(string)($params['request_key']??'');
        $payload=self::generationPayload($type,$params,$tenantId,$userId,(int)$document['id']);
        $requestInput=$payload+['skill_id'=>(int)($params['skill_id']??0),'skill_version'=>(int)($params['skill_version']??0),'skill_inputs'=>(array)($params['skill_inputs']??[])];
        $intent=GenerationIntentService::lookup($tenantId,$userId,(int)$document['id'],$key,$nodeId,$type,$requestInput);
        if (!$intent) {
            if ($type === 'video') self::assertConfirmedQuote($tenantId, $userId, $document, $nodeId, $key, $payload, (string)($params['quote_token'] ?? ''));
            if ((int)($params['skill_id']??0)>0) $payload=self::applyComposerSkill($type,$payload,$params,ShortDramaSkillService::resolveForTask($tenantId,$params));
            $intent=GenerationIntentService::reserve($tenantId,$userId,(int)$document['id'],$key,$nodeId,$type,$payload,$requestInput);
        }
        $runId=(int)$intent['canvas_run_id'];
        $claim=GenerationIntentService::claim($tenantId,$userId,(int)$intent['id']);
        if (!$claim) return self::runDetail($tenantId,$userId,$runId);
        // Use the winner's persisted snapshot, never the losing request's newly
        // resolved Skill/model input. Provider I/O is outside intent transactions.
        $snapshot=json_decode($claim['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        $executionInput=$snapshot['input'];
        if ($type==='text') {
            // Persist the canvas-run ownership before a synchronous text call.
            // This makes a failed initial model and any server-routed fallback
            // auditable without relying on browser state.
            $executionInput['business_table']=self::RUN_TABLE;
            $executionInput['business_id']=$runId;
        }
        try {
            $result=self::executeGenerationPayload($type,$tenantId,$userId,$executionInput);
        } catch (\app\common\service\ai\PreSubmissionRejected $error) {
            GenerationIntentService::rejected($tenantId,$userId,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],$error);
            self::syncShortDramaTask($runId);
            return self::runDetail($tenantId,$userId,$runId);
        } catch (\Throwable $error) {
            if (self::isConfirmedModelUnavailable($error)) {
                // The text market has already recorded/refunded this specific
                // request without an upstream receipt. It is safe to expose a
                // terminal failure and allow a new request key after switching
                // models; it must not be presented as an ambiguous paid submit.
                GenerationIntentService::failed($tenantId,$userId,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],
                    'UPSTREAM_MODEL_UNAVAILABLE','当前选择的模型暂不可用，请切换模型后重新提交');
            } else {
                // Lower services remain billing authorities. An unclassified
                // error cannot prove that no external task was accepted or that
                // a refund ran, so it is never automatically resubmitted.
                GenerationIntentService::unknown($tenantId,$userId,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version']);
            }
            self::syncShortDramaTask($runId);
            return self::runDetail($tenantId,$userId,$runId);
        }
        GenerationIntentService::accepted($tenantId,$userId,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],
            (string)($result['image_task_id']??$result['task_id']??$result['id']??''),$result,$type==='text');
        if ($type==='text') MarketTextModelRuntimeService::bindBusinessTask((int)($result['app_task_id']??0),self::RUN_TABLE,$runId);
        self::syncShortDramaTask($runId);
        return self::runDetail($tenantId,$userId,$runId);
    }

    /**
     * Server-only continuation for an Agent node that was created in auto
     * mode.  It intentionally does not accept a browser payload: model,
     * prompt, source assets and the idempotency key all come from the owned
     * graph.  Video never enters here because it requires a fresh explicit
     * quote confirmation.
     *
     * @return 'submitted'|'waiting'|'blocked'
     */
    public static function submitAgentAutoNode(int $tenantId, int $userId, int $canvasId, string $nodeId): string
    {
        $document=self::ownedDocument($tenantId,$userId,$canvasId);
        $nodes=self::decode((string)($document['nodes_json']??'[]'));
        $edges=self::decode((string)($document['edges_json']??'[]'));
        $target=null;
        foreach ($nodes as $node) if ((string)($node['id']??'')===$nodeId) {$target=$node;break;}
        if (!$target) throw new Exception('NODE_NOT_FOUND');
        $metadata=(array)($target['metadata']??[]);
        $type=(string)($target['type']??'');
        if (empty($metadata['agent_auto_submit']) || !in_array($type,['text','image'],true)) throw new Exception('AGENT_AUTO_SUBMIT_FORBIDDEN');
        if ((string)($metadata['status']??'idle')!=='idle') return 'waiting';
        $key=(string)($metadata['agent_auto_request_key']??'');
        self::assertRequestKey($key);
        $dependency=self::agentAutoDependencyState($nodes,$edges,$nodeId);
        if ($dependency['state']==='blocked') {
            GraphService::blockAgentDependentNode($tenantId,$userId,$canvasId,$nodeId,'前序节点生成失败，未提交此依赖节点');
            return 'blocked';
        }
        if ($dependency['state']!=='ready') return 'waiting';
        $params=[
            'canvas_id'=>$canvasId,'node_id'=>$nodeId,'type'=>$type,
            'prompt'=>(string)($metadata['prompt']??$metadata['content']??''),
            'content'=>(string)($metadata['prompt']??$metadata['content']??''),
            'model_code'=>(string)($metadata['model_code']??''),
            'channel'=>(string)($metadata['channel']??''),
            'model_id'=>(string)($metadata['model_id']??''),
            'ratio'=>(string)($metadata['ratio']??''),'resolution'=>(string)($metadata['resolution']??''),
            'quality'=>(string)($metadata['quality']??''),'count'=>(int)($metadata['count']??1),
            'request_key'=>$key,'reference_assets'=>$dependency['references'],
        ];
        self::submitIdempotent($tenantId,$userId,$params);
        return 'submitted';
    }

    private static function executeGenerationPayload(string $type,int $tenantId,int $userId,array $payload): array
    {
        return match ($type) {
            'text'=>AigcLlmService::generateText($tenantId,$userId,$payload),
            'image'=>($payload['operation']??'')==='local_redraw'
                ? AigcLocalRedrawService::generate($tenantId,$userId,$payload)
                : AigcImageService::generate($tenantId,$userId,$payload),
            'video'=>AigcVideoService::generate($tenantId,$userId,$payload),
            'audio'=>AigcMusicService::generate($tenantId,$userId,$payload),
        };
    }

    /**
     * Only classify a response as terminal when the Provider explicitly says
     * the requested model does not exist or is not sellable. Timeouts, 5xx and
     * connection failures remain ambiguous because they may follow acceptance.
     */
    private static function isConfirmedModelUnavailable(\Throwable $error): bool
    {
        $message=mb_strtolower(trim($error->getMessage()),'UTF-8');
        foreach ([
            'model_not_found','model not found','invalid_model','invalid model',
            'unsupported_model','unsupported model','所选文本模型未上架',
            '所选文本模型已不可用','当前模型已下架','当前模型不可用',
        ] as $needle) {
            if (str_contains($message,$needle)) return true;
        }
        return false;
    }

    public static function runDetail(int $tenantId, int $userId, int $runId): array
    {
        $run = Db::name(self::RUN_TABLE)->where(['id' => $runId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])->find();
        if (!$run) throw new Exception('画布任务不存在或无权访问');
        self::refreshRun($run);
        $run = Db::name(self::RUN_TABLE)->where('id', $runId)->find();
        return self::formatRun($run);
    }

    private static function refreshRun(array $run): void
    {
        // A retained late receipt is evidence, not permission for ordinary
        // polling to settle an unresolved submission or invoke provider sync.
        if ((string)$run['status']==='needs_reconciliation') return;
        // Successful runs are reconciled again on detail reads. This repairs an
        // expired/raw provider URI into a storage-authorized delivery URL and
        // backfills short-drama history if a browser closed before polling.
        if (in_array((string)$run['status'], ['failed', 'canceled'], true)) {
            self::syncShortDramaTask((int)$run['id']);
            return;
        }
        $externalId = (int)($run['provider_task_id'] ?? 0);
        $intentRun=!empty(self::decode((string)$run['request_json'])['__canvas_intent_version']);
        if ((string)$run['node_type'] === 'text') {
            if ($intentRun) GenerationIntentService::projectResult((int)$run['tenant_id'],(int)$run['user_id'],(int)$run['id']);
            return;
        }
        if ($externalId <= 0) return;
        $type = (string)$run['node_type'];
        // Image-market tasks expose an explicit reconciliation hook. Video and
        // music runtimes publish their status through their own callback flows,
        // so reading their task rows is the non-invasive reconciliation path.
        if ($type === 'image') {
            try { AigcImageService::syncMarketTaskResult((int)$run['tenant_id'], $externalId, (int)$run['user_id']); } catch (\Throwable) { }
        }
        $table = $type === 'image' ? 'aigc_image_task' : ($type === 'video' ? 'aigc_video_task' : 'aigc_music_task');
        $task = Db::name($table)->where(['id' => $externalId, 'tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'delete_time' => 0])->find();
        if (!$task) return;
        $status = self::normalizeStatus((string)($task['status'] ?? 'running'));
        $resultTable = $type === 'image' ? 'aigc_image_result' : ($type === 'video' ? 'aigc_video_result' : 'aigc_music_result');
        $column = $type === 'image' ? 'image_uri' : ($type === 'video' ? 'video_uri' : 'audio_uri');
        $results = Db::name($resultTable)->where(['tenant_id' => (int)$run['tenant_id'], 'task_id' => $externalId, 'delete_time' => 0])->order('id', 'asc')->select()->toArray();
        $urls = array_values(array_filter(array_map(static function (array $item) use ($column, $type): array {
            $uri = (string)($item[$column] ?? '');
            $scope = (string)($item['storage_scope'] ?? 'tenant');
            $engine = (string)($item['storage_engine'] ?? 'local');
            $domain = (string)($item['storage_domain'] ?? '');
            $coverUri = $type === 'video' ? (string)($item['cover_uri'] ?? '') : '';
            return [
                'url' => $uri === '' ? '' : FileService::getFileUrlByStorage($uri, $scope, $engine, $domain),
                'uri' => $uri, 'storage_scope' => $scope, 'storage_engine' => $engine, 'storage_domain' => $domain,
                'poster_uri' => $coverUri,
                'poster_url' => $coverUri === '' ? '' : FileService::getFileUrlByStorage($coverUri, $scope, $engine, $domain),
            ];
        }, $results), static fn(array $item): bool => $item['url'] !== ''));
        $payload = json_decode((string)$run['result_json'], true) ?: [];
        if ($urls) $payload['results'] = $urls;
        Db::name(self::RUN_TABLE)->where('id', $run['id'])->update([
            'status' => $status, 'progress' => $status === 'success' ? 100 : max(25, (int)($task['progress'] ?? 25)),
            'error' => (string)($task['error'] ?? $task['error_msg'] ?? ''),
            'result_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => time(),
        ]);
        // Asset registration enriches the immutable run result with the
        // canvas-owned asset id. Do it before intent projection: downstream
        // nodes must receive an identity that survives a signed URL refresh.
        self::syncShortDramaTask((int)$run['id']);
        $run = Db::name(self::RUN_TABLE)->where('id', $run['id'])->find() ?: $run;
        if (!$intentRun && $type === 'video' && $status === 'success' && $urls) {
            self::projectVideoRunToCanvas($run, $urls[0]);
        }
        if ($intentRun) GenerationIntentService::projectResult((int)$run['tenant_id'],(int)$run['user_id'],(int)$run['id']);
    }

    /** Persist completed video metadata even when the browser closes before its next poll. */
    private static function projectVideoRunToCanvas(array $run, array $result): void
    {
        $canvasId = (int)($run['canvas_id'] ?? 0);
        $nodeId = (string)($run['node_id'] ?? '');
        $uri = self::canvasStoredUri((string)($result['uri'] ?? $result['url'] ?? ''));
        if ($canvasId <= 0 || $nodeId === '' || $uri === '') return;
        Db::transaction(function () use ($run, $result, $canvasId, $nodeId, $uri): void {
            $document = Db::name(self::DOCUMENT_TABLE)->where([
                'id' => $canvasId, 'tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'delete_time' => 0,
            ])->lock(true)->find();
            if (!$document) return;
            $nodes = self::decode((string)($document['nodes_json'] ?? ''));
            $changed = false;
            foreach ($nodes as &$node) {
                if ((string)($node['id'] ?? '') !== $nodeId) continue;
                $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
                if ((int)($metadata['canvasRunId'] ?? 0) !== (int)$run['id']) continue;
                $previousMetadata = $metadata;
                $posterUrl = (string)($result['poster_url'] ?? '');
                $posterUri = (string)($result['poster_uri'] ?? '');
                if ($posterUrl === '' && $posterUri === '' && self::canvasStoredUri((string)($metadata['video_url'] ?? $metadata['url'] ?? '')) === $uri) {
                    $posterUrl = (string)($metadata['poster_url'] ?? '');
                    $posterUri = (string)($metadata['poster_uri'] ?? '');
                }
                $metadata = array_merge($metadata, [
                    'url' => (string)$result['url'], 'video_url' => (string)$result['url'],
                    'storage_scope' => (string)($result['storage_scope'] ?? ''),
                    'storage_engine' => (string)($result['storage_engine'] ?? ''),
                    'storage_domain' => (string)($result['storage_domain'] ?? ''),
                    'poster_url' => $posterUrl,
                    'poster_uri' => $posterUri,
                    'poster_status' => $posterUrl !== '' || $posterUri !== '' ? 'ready' : 'pending',
                    'status' => 'success', 'progress' => 100, 'error' => '',
                ]);
                if ($metadata === $previousMetadata) break;
                $node['metadata'] = $metadata;
                $changed = true;
                break;
            }
            unset($node);
            if (!$changed) return;
            self::queueVideoPosters((int)$run['tenant_id'], (int)$run['user_id'], $canvasId, $nodes);
            GraphService::persistLockedDocument($document, [
                'nodes_json' => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => time(),
            ]);
        });
    }

    /** Mirror canvas-owned work into the short-drama task/asset history without sharing another canvas app. */
    private static function syncShortDramaTask(int $runId, bool $locked=false): void
    {
        if (!$locked) {
            Db::transaction(static function () use ($runId): void { self::syncShortDramaTask($runId,true); });
            return;
        }
        // Serialize history/asset upserts for concurrent retries and polling.
        $run = Db::name(self::RUN_TABLE)->where('id', $runId)->lock(true)->find();
        if (!$run) return;
        $canvas = Db::name(self::DOCUMENT_TABLE)->where([
            'id' => (int)$run['canvas_id'], 'tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'delete_time' => 0,
        ])->find();
        $taskId = 'canvas_run_' . (int)$run['id'];
        $status = (string)$run['status'];
        $result = self::decode((string)$run['result_json']);
        $source = self::sourceTaskProjection($run);
        if ((string)$run['node_type'] === 'text') {
            $source = array_merge($source, self::textResultProjection($result));
        }
        $now = time();
        $request = self::decode((string)$run['request_json']);
        $inputAssetIds = array_values(array_unique(array_filter(array_map(
            static fn($reference): int => is_array($reference) ? (int)($reference['asset_id'] ?? 0) : 0,
            (array)($request['reference_assets'] ?? [])
        ))));
        $skill = (array)($request['skill_snapshot'] ?? []);
        $data = [
            'tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'project_id' => 0, 'canvas_id' => (int)$run['canvas_id'], 'shot_id' => '',
            'task_id' => $taskId, 'parent_task_id' => '', 'source_task_id' => (string)$run['provider_task_id'],
            'source_app_code' => AigcShortDramaService::APP_CODE, 'task_type' => 'canvas_' . (string)$run['node_type'],
            'skill_id' => (int)($skill['id'] ?? 0), 'skill_version' => (int)($skill['version'] ?? 0),
            'skill_source' => $skill ? 'manual' : 'none', 'skill_snapshot_json' => $skill ? json_encode($skill, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}',
            'app_task_id' => (int)($source['app_task_id'] ?? 0), 'consumption_id' => (int)($source['consumption_id'] ?? 0),
            'market_product_id' => (int)($source['market_product_id'] ?? 0), 'market_sku_id' => (int)($source['market_sku_id'] ?? 0),
            'status' => $status, 'progress' => (int)$run['progress'], 'provider' => (string)($source['provider'] ?? 'canvas'), 'provider_task_id' => (string)($source['provider_task_id'] ?? ''),
            'provider_request_id' => (string)($source['provider_request_id'] ?? ''), 'model_json' => json_encode((array)($source['model'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'request_json' => (string)$run['request_json'],
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'input_asset_ids' => json_encode($inputAssetIds),
            'pricing_snapshot' => json_encode((array)($source['pricing'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'billing_status' => (string)($source['billing_status'] ?? 'delegated'), 'tenant_cost_points' => (float)($source['tenant_cost_points'] ?? 0), 'user_charge_points' => (float)($source['user_charge_points'] ?? 0),
            'idempotency_key' => sha1((int)$run['tenant_id'] . '|' . (int)$run['user_id'] . '|' . $taskId), 'retry_count' => 0,
            'error_code' => $status === 'failed' ? 'canvas_generation_failed' : '', 'error_msg' => (string)$run['error'],
            'operator_error' => '', 'safety_status' => $status === 'success' ? 'passed' : 'pending', 'started_at' => (int)$run['create_time'],
            'finished_at' => in_array($status, ['success', 'failed', 'canceled'], true) ? $now : 0, 'update_time' => $now, 'delete_time' => 0,
        ];
        $existing = Db::name('aigc_short_drama_generation_task')->where(['tenant_id' => (int)$run['tenant_id'], 'task_id' => $taskId, 'delete_time' => 0])->find();
        if ($existing) Db::name('aigc_short_drama_generation_task')->where('id', $existing['id'])->update($data);
        else Db::name('aigc_short_drama_generation_task')->insert($data + ['output_asset_ids' => '[]', 'create_time' => $now]);
        if ($status !== 'success' || !in_array((string)$run['node_type'], ['image', 'video', 'audio'], true)) return;
        $type = (string)$run['node_type'];
        $resultTable = $type === 'image' ? 'aigc_image_result' : ($type === 'video' ? 'aigc_video_result' : 'aigc_music_result');
        $column = $type === 'image' ? 'image_uri' : ($type === 'video' ? 'video_uri' : 'audio_uri');
        $providerTaskId = (int)$run['provider_task_id'];
        if ($providerTaskId <= 0) return;
        $assetIds = [];
        $assetIdsByUri = [];
        foreach (Db::name($resultTable)->where(['tenant_id' => (int)$run['tenant_id'], 'task_id' => $providerTaskId, 'delete_time' => 0])->select()->toArray() as $index => $item) {
            $uri = (string)($item[$column] ?? '');
            if ($uri === '') continue;
            $asset = Db::name('aigc_short_drama_asset')->where(['tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'project_id' => 0, 'canvas_id' => (int)$run['canvas_id'], 'task_id' => $taskId, 'uri' => $uri, 'delete_time' => 0])->find();
            if (!$asset) {
                $assetId = Db::name('aigc_short_drama_asset')->insertGetId([
                    'tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'project_id' => 0, 'canvas_id' => (int)$run['canvas_id'], 'task_id' => $taskId, 'shot_id' => '',
                    'asset_type' => 'canvas_' . $type, 'title' => (string)($canvas['title'] ?? '画布') . ' · ' . ($type === 'image' ? '图片' : ($type === 'video' ? '视频' : '音频')) . ((int)$index + 1),
                    'uri' => $uri, 'cover_uri' => '', 'storage_scope' => (string)($item['storage_scope'] ?? 'tenant'), 'storage_engine' => (string)($item['storage_engine'] ?? 'local'), 'storage_domain' => (string)($item['storage_domain'] ?? ''),
                    'mime_type' => $type === 'image' ? 'image/png' : ($type === 'video' ? 'video/mp4' : 'audio/mpeg'), 'file_size' => 0, 'width' => (int)($item['width'] ?? 0), 'height' => (int)($item['height'] ?? 0), 'duration' => (float)($item['duration'] ?? 0), 'checksum' => '',
                    'meta_json' => json_encode(['source' => 'short_drama_canvas', 'canvas_id' => (int)$run['canvas_id'], 'canvas_run_id' => (int)$run['id'], 'provider_task_id' => $providerTaskId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => 'ready', 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
                ]);
            } else $assetId = (int)$asset['id'];
            $assetIds[] = $assetId;
            $assetIdsByUri[$uri] = $assetId;
        }
        if (!$assetIds) return;
        // Keep the task history and the canvas run in sync. The run is what
        // Graph projection and browser polling consume, so it must expose the
        // same durable output identity as the asset library.
        $resultItems = (array)($result['results'] ?? $result['images'] ?? $result['videos'] ?? []);
        foreach ($resultItems as &$item) {
            if (!is_array($item)) continue;
            $uri = self::canvasStoredUri((string)($item['uri'] ?? $item['url'] ?? ''));
            if ($uri !== '' && isset($assetIdsByUri[$uri])) $item['asset_id'] = $assetIdsByUri[$uri];
        }
        unset($item);
        $result['results'] = $resultItems;
        $encodedResult = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Db::name(self::RUN_TABLE)->where('id', (int)$run['id'])->update(['result_json' => $encodedResult, 'update_time' => time()]);
        Db::name('aigc_short_drama_generation_task')->where(['tenant_id' => (int)$run['tenant_id'], 'task_id' => $taskId])->update([
            'output_asset_ids' => json_encode($assetIds), 'result_json' => $encodedResult, 'update_time' => time(),
        ]);
    }

    /** Read only the auditable fields from the task created by the delegated runtime. */
    private static function sourceTaskProjection(array $run): array
    {
        $type = (string)($run['node_type'] ?? '');
        $table = $type === 'image' ? 'aigc_image_task' : ($type === 'video' ? 'aigc_video_task' : ($type === 'audio' ? 'aigc_music_task' : ''));
        $sourceId = (int)($run['provider_task_id'] ?? 0);
        if ($table === '' || $sourceId <= 0) return [];
        $row = Db::name($table)->where(['id' => $sourceId, 'tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'delete_time' => 0])->find();
        if (!$row) return [];
        $model = self::decode((string)($row['model_json'] ?? ''));
        if ($model === []) $model = array_filter(['model' => (string)($row['model'] ?? ''), 'channel' => (string)($row['channel'] ?? '')]);
        return [
            'app_task_id' => (int)($row['app_task_id'] ?? 0), 'consumption_id' => (int)($row['consumption_id'] ?? 0),
            'market_product_id' => (int)($row['market_product_id'] ?? 0), 'market_sku_id' => (int)($row['market_sku_id'] ?? 0),
            'provider' => (string)($row['provider'] ?? 'canvas'),
            'provider_task_id' => (string)($row['provider_task_id'] ?? ''),
            'provider_request_id' => (string)($row['provider_request_id'] ?? $row['market_request_id'] ?? ''),
            'model' => $model, 'pricing' => self::decode((string)($row['pricing_snapshot'] ?? '')),
            'billing_status' => (string)($row['billing_status'] ?? 'delegated'),
            'tenant_cost_points' => (float)($row['tenant_cost_points'] ?? 0), 'user_charge_points' => (float)($row['user_charge_points'] ?? 0),
        ];
    }

    /** Map the synchronous text runtime result into short-drama task history. */
    private static function textResultProjection(array $result): array
    {
        $billing = (array)($result['billing'] ?? []);
        return [
            'app_task_id' => (int)($result['app_task_id'] ?? 0),
            'consumption_id' => (int)($result['consumption_id'] ?? 0),
            'market_product_id' => (int)($result['market_product_id'] ?? 0),
            'market_sku_id' => (int)($result['market_sku_id'] ?? 0),
            'provider' => (string)($result['provider'] ?? 'power_market'),
            'provider_task_id' => (string)($result['provider_task_id'] ?? ''),
            'provider_request_id' => (string)($result['provider_request_id'] ?? ''),
            'model' => array_filter([
                'model_code' => (string)($result['model_code'] ?? ''),
                'channel_code' => (string)($result['channel_code'] ?? ''),
            ]),
            'pricing' => $billing,
            'billing_status' => (string)($billing['billing_status'] ?? 'settled'),
            'tenant_cost_points' => (float)($billing['tenant_cost_points'] ?? 0),
            'user_charge_points' => (float)($billing['user_charge_points'] ?? 0),
        ];
    }

    private static function applyComposerSkill(string $type, array $payload, array $params, array $skill): array
    {
        $missing = ShortDramaSkillRuntime::missingSlots($skill, $params);
        if ($missing) throw new Exception('请填写技能所需信息：' . implode('、', array_map(static fn(array $slot): string => (string)($slot['label'] ?? $slot['key']), $missing)));
        $stage = ['text' => 'script_plan', 'image' => 'shot_image', 'video' => 'shot_video', 'audio' => 'bgm_audio'][$type];
        ShortDramaSkillRuntime::validateMedia($skill, $stage, array_replace($payload, ['model_code' => $payload['model_code'] ?? $payload['channel'] ?? '']));
        $inputs = [];
        foreach ((array)($params['skill_inputs'] ?? []) as $key => $value) {
            if (is_scalar($value)) $inputs[] = mb_substr((string)$key, 0, 100) . '：' . mb_substr((string)$value, 0, 2000);
        }
        $payload['prompt'] .= ($inputs ? "\n\n【技能输入】\n" . implode("\n", array_slice($inputs, 0, 30)) : '') . ShortDramaSkillRuntime::instruction($skill, $stage);
        $payload['content'] = $payload['prompt'];
        $payload['skill_snapshot'] = $skill;
        return $payload;
    }

    /**
     * Resolve Agent-created dependency edges from the authoritative graph.
     * This is deliberately a pure evaluator so both the existing Agent worker
     * and the submit endpoint take the same readiness decision after a race or
     * worker restart. Only an explicit same-plan dependency can become
     * terminally blocked; ordinary selected context remains compatible with
     * its previous wait-until-media-ready behavior.
     *
     * @return array{state:'ready'|'waiting'|'blocked',references:list<array<string,mixed>>}
     */
    public static function agentAutoDependencyState(array $nodes, array $edges, string $targetId): array
    {
        $plan=self::agentPlanDependencyState($nodes,$edges,$targetId);
        if ($plan['state']!=='ready') return $plan+['text_context'=>[]];
        $byId=[];
        foreach ($nodes as $node) if (is_array($node) && isset($node['id'])) $byId[(string)$node['id']]=$node;
        $references=[];
        $textContext=[];
        $seenText=[];
        foreach ($edges as $edge) {
            if (!is_array($edge) || (string)($edge['to']??'')!==$targetId || (string)($edge['kind']??'reference')!=='reference') continue;
            if ((string)($edge['role']??'')==='agent_dependency') continue;
            $source=$byId[(string)($edge['from']??'')]??null;
            if (!$source) continue;
            $type=(string)($source['type']??'');
            $metadata=(array)($source['metadata']??[]);
            if ($type==='text') {
                $content=trim((string)($metadata['content']??$metadata['prompt']??''));
                $identity=(string)($source['id']??'').'|'.$content;
                if ($content!=='' && !isset($seenText[$identity])) {
                    $seenText[$identity]=true;
                    $textContext[]=['title'=>mb_substr(trim((string)($source['title']??'')),0,80),'content'=>mb_substr($content,0,6000)];
                }
                continue;
            }
            $status=(string)($metadata['status']??'');
            if (!in_array($type,['image','video','audio'],true)) {
                continue;
            }
            if (in_array($status,['failed','canceled'],true)) return ['state'=>'blocked','references'=>[],'text_context'=>[]];
            if ($status!=='success') return ['state'=>'waiting','references'=>[],'text_context'=>[]];
            $assetId=(int)($metadata['asset_id']??0);
            if ($assetId<=0) return ['state'=>'waiting','references'=>[],'text_context'=>[]];
            $references[]=[
                'type'=>$type,'asset_id'=>$assetId,
                'role'=>in_array((string)($edge['role']??''),['first_frame','last_frame','reference'],true)
                    ? (string)$edge['role'] : 'reference',
            ];
        }
        return ['state'=>'ready','references'=>self::mergeReferenceAssets($plan['references'],$references),'text_context'=>$textContext];
    }

    /** Resolve graph inputs once for quoting and every submit path. Linked
     * text is passed as bounded prompt context; linked completed media is
     * resolved as an owned asset. This makes a visible reference edge a real
     * request input without allowing browser-supplied IDs or URLs. */
    private static function withGraphReferenceInputs(array $document,string $nodeId,array $params): array
    {
        $nodes=self::decode((string)($document['nodes_json']??'[]'));
        $edges=self::decode((string)($document['edges_json']??'[]'));
        $context=self::agentAutoDependencyState($nodes,$edges,$nodeId);
        if (($context['state']??'waiting')==='blocked') throw new Exception('前序或引用节点生成失败，请先重试前序节点');
        if (($context['state']??'waiting')!=='ready') throw new Exception('前序或引用节点尚未生成完成，请稍后再试');
        if (!empty($context['references'])) $params['reference_assets']=self::mergeReferenceAssets((array)($params['reference_assets']??[]),(array)$context['references']);
        $parts=[];$remaining=12000;
        foreach ((array)($context['text_context']??[]) as $item) {
            if (!is_array($item) || $remaining<=0) break;
            $content=trim((string)($item['content']??''));
            if ($content==='') continue;
            $title=trim((string)($item['title']??''));
            $part=($title===''?'':"【{$title}】\n").mb_substr($content,0,$remaining);
            $parts[]=$part;$remaining-=mb_strlen($part);
        }
        if ($parts) {
            $prompt=trim((string)($params['prompt']??$params['content']??''));
            $params['prompt']=$prompt."\n\n【画布已连接上下文】\n".implode("\n\n",$parts);
            $params['content']=$params['prompt'];
        }
        return $params;
    }

    /** @return array{state:'ready'|'waiting'|'blocked',references:list<array<string,mixed>>} */
    public static function agentPlanDependencyState(array $nodes, array $edges, string $targetId): array
    {
        $byId=[];
        foreach ($nodes as $node) if (is_array($node) && isset($node['id'])) $byId[(string)$node['id']]=$node;
        $references=[];
        foreach ($edges as $edge) {
            if (!is_array($edge) || (string)($edge['to']??'')!==$targetId || (string)($edge['kind']??'reference')!=='reference' || (string)($edge['role']??'')!=='agent_dependency') continue;
            $source=$byId[(string)($edge['from']??'')]??null;
            if (!$source) return ['state'=>'blocked','references'=>[]];
            $metadata=(array)($source['metadata']??[]);
            $status=(string)($metadata['status']??'');
            if (in_array($status,['failed','canceled'],true)) return ['state'=>'blocked','references'=>[]];
            if ($status!=='success') return ['state'=>'waiting','references'=>[]];
            $type=(string)($source['type']??'');
            if (!in_array($type,['image','video','audio'],true)) continue;
            $assetId=(int)($metadata['asset_id']??0);
            if ($assetId<=0) return ['state'=>'waiting','references'=>[]];
            $references[]=['type'=>$type,'asset_id'=>$assetId,'role'=>'reference'];
        }
        return ['state'=>'ready','references'=>$references];
    }

    /** @return list<array<string,mixed>> */
    private static function mergeReferenceAssets(array $first, array $second): array
    {
        $merged=[]; $seen=[];
        foreach (array_merge($first,$second) as $reference) {
            if (!is_array($reference)) continue;
            $identity=(string)($reference['type']??'').'|'.(string)($reference['asset_id']??'').'|'.(string)($reference['role']??'reference');
            if ($identity==='||reference' || isset($seen[$identity])) continue;
            $seen[$identity]=true;
            $merged[]=$reference;
        }
        return $merged;
    }

    private static function generationPayload(string $type, array $params, int $tenantId, int $userId, int $canvasId): array
    {
        $prompt = trim((string)($params['prompt'] ?? $params['content'] ?? ''));
        if ($prompt === '') throw new Exception('请输入提示内容');
        $referenceAssets=self::resolveOwnedReferenceAssets($tenantId, $userId, $canvasId, (array)($params['reference_assets'] ?? []));
        // Owned user uploads take precedence over transient URLs. Their signed
        // delivery URLs are resolved server-side and image references force the
        // shared text runtime onto a vision-capable tenant model.
        $referenceImages=[];
        foreach ($referenceAssets as $reference) {
            if (is_array($reference) && strtolower((string)($reference['type'] ?? ''))==='image' && trim((string)($reference['url'] ?? ''))!=='') $referenceImages[]=(string)$reference['url'];
        }
        foreach ((array)($params['reference_images'] ?? []) as $image) if (is_string($image) && trim($image)!=='') $referenceImages[]=trim($image);
        $payload = [
            'prompt' => $prompt, 'content' => $prompt, 'channel' => (string)($params['channel'] ?? ''),
            'model_code' => (string)($params['model_code'] ?? ''), 'model_id' => (string)($params['model_id'] ?? ''),
            'ratio' => (string)($params['ratio'] ?? $params['aspect_ratio'] ?? ''), 'duration' => (int)($params['duration'] ?? 0),
            'quantity' => max(1, min(4, (int)($params['count'] ?? $params['quantity'] ?? 1))),
            'generation_method' => (string)($params['generation_method'] ?? $params['generationMethod'] ?? ''),
            'reference_images' => array_values(array_unique($referenceImages)),
            'reference_assets' => $referenceAssets,
            'source_app_code' => AigcShortDramaService::APP_CODE,
        ];
        if ($type === 'audio') $payload['lyrics'] = (string)($params['lyrics'] ?? '');
        if ($type === 'image' && ($params['operation'] ?? '') === 'local_redraw') {
            $payload['operation'] = 'local_redraw';
            $payload['source_image'] = trim((string)($params['source_image'] ?? ''));
            $payload['mask_image'] = trim((string)($params['mask_image'] ?? ''));
            if ($payload['source_image'] === '' || $payload['mask_image'] === '') throw new Exception('请选择原图并绘制蒙版');
        }
        foreach (['quality', 'resolution', 'negative_prompt'] as $key) {
            if (isset($params[$key])) $payload[$key] = (string)$params[$key];
        }
        return array_filter($payload, static fn($value) => $value !== '' && $value !== 0 || is_array($value));
    }

    private static function assertGenerationNode(array $document, string $nodeId, string $type): void
    {
        if ($nodeId === '') throw new Exception('NODE_NOT_FOUND');
        foreach (self::decode((string)($document['nodes_json'] ?? '[]')) as $node) {
            if ((string)($node['id'] ?? '') !== $nodeId) continue;
            if ((string)($node['type'] ?? '') !== $type) throw new Exception('NODE_TYPE_MISMATCH');
            return;
        }
        throw new Exception('NODE_NOT_FOUND');
    }

    private static function assertRequestKey(string $key): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $key)) throw new Exception('INVALID_REQUEST_KEY');
    }

    /** The canonical quote input intentionally uses owned asset identity, not a transient signed URL. */
    private static function quoteInput(string $nodeId, array $payload): array
    {
        $input = $payload;
        $references = [];
        foreach ((array)($payload['reference_assets'] ?? []) as $reference) {
            if (!is_array($reference)) continue;
            if ((int)($reference['asset_id'] ?? 0) > 0) {
                $references[] = array_filter([
                    'type' => (string)($reference['type'] ?? ''), 'role' => (string)($reference['role'] ?? ''),
                    'asset_id' => (int)$reference['asset_id'], 'uri' => (string)($reference['uri'] ?? ''),
                    'storage_scope' => (string)($reference['storage_scope'] ?? ''),
                    'storage_engine' => (string)($reference['storage_engine'] ?? ''), 'storage_domain' => (string)($reference['storage_domain'] ?? ''),
                ], static fn($value): bool => $value !== '');
                continue;
            }
            $references[] = $reference;
        }
        $input['node_id'] = $nodeId;
        $input['reference_assets'] = $references;
        // reference_images is derived from reference_assets by the video
        // adapter. Including it here would make a refreshed signed delivery
        // URL look like a user edit.
        if ($references !== []) unset($input['reference_images']);
        return self::canonical($input);
    }

    private static function quoteInputForDocument(array $document, string $nodeId, array $payload): array
    {
        $input = self::quoteInput($nodeId, $payload);
        foreach (self::decode((string)($document['nodes_json'] ?? '[]')) as $node) {
            if ((string)($node['id'] ?? '') === $nodeId) {
                // A graph revision also advances for server-owned runtime state
                // (queued/running/failed progress) and for unrelated nodes.  It
                // must not invalidate a price confirmation while the browser is
                // waiting on its confirmation dialog.  The canonical request
                // already covers every billable option; retain the target
                // content revision as the document-side stale-edit guard.
                $input['node_content_revision'] = (int)($node['metadata']['content_revision'] ?? 0);
                break;
            }
        }
        return self::canonical($input);
    }

    private static function assertConfirmedQuote(int $tenantId, int $userId, array $document, string $nodeId, string $requestKey, array $payload, string $token): void
    {
        self::assertRequestKey($requestKey);
        if (!preg_match('/^[a-f0-9]{48}$/D', $token)) throw new Exception('QUOTE_CONFIRMATION_REQUIRED');
        $canvasId = (int)$document['id'];
        Db::transaction(function () use ($tenantId, $userId, $canvasId, $document, $nodeId, $requestKey, $payload, $token): void {
            $quote = Db::name(self::QUOTE_TABLE)->where([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'canvas_id' => $canvasId, 'node_id' => $nodeId, 'quote_token' => $token,
            ])->lock(true)->find();
            if (!$quote) throw new Exception('QUOTE_NOT_FOUND');
            if ((int)$quote['expires_at'] < time()) throw new Exception('QUOTE_EXPIRED');
            if ((string)$quote['status'] !== 'confirmed') throw new Exception('QUOTE_CONFIRMATION_REQUIRED');
            if (!hash_equals((string)$quote['request_key'], $requestKey)) throw new Exception('QUOTE_REQUEST_MISMATCH');
            if (!hash_equals((string)$quote['input_hash'], hash('sha256', self::json(self::quoteInputForDocument($document, $nodeId, $payload))))) throw new Exception('QUOTE_INPUT_CHANGED');
        });
    }

    private static function publicQuote(array $quote): array
    {
        return [
            'market_product_id' => (int)($quote['market_product_id'] ?? 0), 'market_sku_id' => (int)($quote['market_sku_id'] ?? 0),
            'tenant_cost_points' => (float)($quote['tenant_cost_points'] ?? 0), 'user_charge_points' => (float)($quote['user_charge_points'] ?? 0),
            'usage_unit' => (string)($quote['usage_unit'] ?? ''), 'settlement_mode' => (string)($quote['settlement_mode'] ?? ''),
        ];
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        $list = array_keys($value) === range(0, count($value) - 1);
        if ($list) return array_map(static fn($item) => self::canonical($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonical($item);
        return $value;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * An asset-library selection is an owned asset identity, not a browser URL.
     * Resolve it at acceptance time so an old selected version remains stable
     * while each new request receives the storage service's current URL.
     */
    private static function resolveOwnedReferenceAssets(int $tenantId, int $userId, int $canvasId, array $references): array
    {
        $resolved=[];
        foreach (array_values($references) as $reference) {
            if (!is_array($reference)) throw new Exception('CANVAS_REFERENCE_UNAVAILABLE');
            $assetId=(int)($reference['asset_id'] ?? $reference['assetId'] ?? 0);
            if ($assetId<=0) { $resolved[]=$reference; continue; }
            $type=strtolower(trim((string)($reference['type'] ?? '')));
            if (!in_array($type,['image','video','audio'],true)) throw new Exception('CANVAS_REFERENCE_UNAVAILABLE');
            $asset=Db::name('aigc_short_drama_asset')->where([
                'id'=>$assetId,'tenant_id'=>$tenantId,'user_id'=>$userId,'delete_time'=>0,'status'=>'ready',
            ])->find();
            $allowedTypes=[
                'image'=>['reference_image','canvas_image','shot_image','character_image','scene_image','subject_image','three_view'],
                'video'=>['canvas_video','shot_video'],
                'audio'=>['canvas_audio','shot_audio','bgm_audio'],
            ];
            if (!$asset || !in_array((int)$asset['canvas_id'],[0,$canvasId],true) || !in_array((string)$asset['asset_type'],$allowedTypes[$type],true) || (string)$asset['uri']==='') throw new Exception('CANVAS_REFERENCE_UNAVAILABLE');
            $url=FileService::getFileUrlByStorage((string)$asset['uri'],(string)$asset['storage_scope'],(string)$asset['storage_engine'],(string)$asset['storage_domain']);
            if (!preg_match('#^https?://#i',$url)) throw new Exception('CANVAS_REFERENCE_UNAVAILABLE');
            $resolved[]=array_replace($reference,[
                'asset_id'=>(int)$asset['id'],'url'=>$url,'uri'=>(string)$asset['uri'],
                'storage_scope'=>(string)$asset['storage_scope'],'storage_engine'=>(string)$asset['storage_engine'],'storage_domain'=>(string)$asset['storage_domain'],
            ]);
            $index=array_key_last($resolved);
            unset($resolved[$index]['assetId']);
        }
        return $resolved;
    }

    private static function ownedDocument(int $tenantId, int $userId, int $id, bool $lock = false): array
    {
        if ($id <= 0) {
            $current = self::current($tenantId, $userId);
            if (!$lock) return $current;
            $id = (int)$current['id'];
        }
        $row = Db::name(self::DOCUMENT_TABLE)->where(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])->lock($lock)->find();
        if (!$row) throw new Exception('画布不存在或无权访问');
        return $row;
    }
    private static function currentById(int $tenantId, int $userId, int $id): array { return self::formatDocument(self::ownedDocument($tenantId, $userId, $id), true); }
    private static function normalizeNodes(array $nodes): array
    {
        // Reject before poster jobs or document writes. Never silently remove
        // existing nodes when an oversized snapshot is submitted.
        if (count($nodes) > 200) {
            throw new Exception('CANVAS_CAPACITY_EXCEEDED: 画布最多支持 200 个节点，请减少节点后重试');
        }
        return array_values(array_filter($nodes, static fn($node) => is_array($node) && isset($node['id']) && isset($node['type'])));
    }
    /** Keep a completed poster when an older browser snapshot saves unrelated canvas changes. */
    private static function mergePersistedVideoPosters(array $nodes, array $persisted): array
    {
        $persistedById = [];
        foreach ($persisted as $node) $persistedById[(string)($node['id'] ?? '')] = $node;
        foreach ($nodes as &$node) {
            $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
            $saved = $persistedById[(string)($node['id'] ?? '')] ?? null;
            $savedMetadata = is_array($saved['metadata'] ?? null) ? $saved['metadata'] : [];
            $source = self::canvasStoredUri((string)($metadata['video_url'] ?? $metadata['url'] ?? ''));
            $sameVideo = $source !== '' && $source === self::canvasStoredUri((string)($savedMetadata['video_url'] ?? $savedMetadata['url'] ?? ''));
            if ($sameVideo && (!empty($savedMetadata['poster_url']) || !empty($savedMetadata['poster_uri']))) {
                foreach (['poster_url', 'poster_uri', 'poster_status'] as $key) if (isset($savedMetadata[$key])) $metadata[$key] = $savedMetadata[$key];
                $node['metadata'] = $metadata;
            }
        }
        unset($node);
        return $nodes;
    }
    /** Queue only persisted video nodes without a durable poster. Saving stays constant-time. */
    private static function queueVideoPosters(int $tenantId, int $userId, int $canvasId, array &$nodes, bool $enqueue = true): void
    {
        foreach ($nodes as &$node) {
            $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
            $isVideo = (string)($node['type'] ?? '') === 'video'
                || str_starts_with(strtolower((string)($metadata['mimeType'] ?? $metadata['mime_type'] ?? '')), 'video/');
            if (!$isVideo || !empty($metadata['poster_url']) || !empty($metadata['poster_uri'])) {
                continue;
            }
            $source = (string)($metadata['video_url'] ?? $metadata['url'] ?? '');
            $uri = self::canvasStoredUri($source);
            if ($uri === '') {
                continue;
            }
            $metadata['poster_status'] = 'pending';
            $node['metadata'] = $metadata;
            if ($enqueue) {
                ShortDramaCanvasPosterJobService::enqueue(
                    $tenantId,
                    $userId,
                    $canvasId,
                    (string)$node['id'],
                    $uri,
                    (string)($metadata['storage_scope'] ?? ''),
                    (string)($metadata['storage_engine'] ?? ''),
                    (string)($metadata['storage_domain'] ?? '')
                );
            }
        }
        unset($node);
    }
    private static function canvasStoredUri(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^https?://#i', $value) === 1) {
            $value = ltrim(rawurldecode((string)(parse_url($value, PHP_URL_PATH) ?: '')), '/');
        }
        $value = ltrim($value, '/');
        return str_starts_with($value, 'uploads/') || str_starts_with($value, 'resource/') ? $value : '';
    }
    private static function normalizeEdges(array $edges, array $nodes): array { $ids = array_flip(array_map(static fn($node) => (string)$node['id'], $nodes)); return array_values(array_filter($edges, static fn($edge) => is_array($edge) && isset($ids[(string)($edge['from'] ?? '')], $ids[(string)($edge['to'] ?? '')]) && (string)$edge['from'] !== (string)$edge['to'])); }
    private static function decode(string $json): array { $decoded = json_decode($json, true); return is_array($decoded) ? $decoded : []; }
    private static function normalizeStatus(string $status): string { return in_array($status, ['success', 'failed', 'canceled'], true) ? $status : 'running'; }
    private static function formatDocument(array $row, bool $includeRuns = false, bool $locked = false): array
    {
        if ($includeRuns && !$locked) {
            // This legacy read path can recover nodes from run history. Re-read
            // under the same document lock as save/projectors before merging;
            // the caller's earlier row may already be stale.
            return Db::transaction(function () use ($row): array {
                $current = self::ownedDocument((int)$row['tenant_id'], (int)$row['user_id'], (int)$row['id'], true);
                return self::formatDocument($current, true, true);
            });
        }
        $nodes = self::decode((string)$row['nodes_json']);
        $createTime = (int)($row['create_time'] ?? 0);
        $data = [
            'id' => (int)$row['id'],
            'agent_enabled' => \app\common\service\app\aigc_short_drama\canvas_agent\FeatureGate::enabled((int)$row['tenant_id']),
            'title' => (string)$row['title'],
            'nodes' => $nodes,
            'removed_node_ids' => array_map('strval', self::decode((string)($row['removed_node_ids_json'] ?? '[]'))),
            'edges' => self::decode((string)$row['edges_json']),
            'viewport' => self::decode((string)$row['viewport_json']),
            'created_at' => $createTime > 0 ? date('Y-m-d H:i:s', $createTime) : '',
            'update_time' => (int)$row['update_time'],
            'document_token' => self::documentToken($row),
        ];
        if (array_key_exists('graph_revision', $row)) {
            $data['graph_revision'] = (int)$row['graph_revision'];
            $data['schema_version'] = (int)($row['schema_version'] ?? 1);
        }
        if (!$includeRuns) return $data;
        // A browser can be refreshed after the backend creates a run but before
        // its debounce save writes canvasRunId into nodes_json. Recreate only the
        // missing visual nodes from owned run history, then return the latest run
        // per node so task results remain durable across browser refreshes.
        $runs = Db::name(self::RUN_TABLE)->where([
            'tenant_id' => (int)$row['tenant_id'], 'user_id' => (int)$row['user_id'], 'canvas_id' => (int)$row['id'], 'delete_time' => 0,
        ])->order('id', 'asc')->select()->toArray();
        $nodeIds = array_fill_keys(array_map(static fn(array $node): string => (string)($node['id'] ?? ''), $nodes), true);
        $recovered = false;
        $pendingRecovery = [];
        foreach ($runs as $index => $run) {
            $nodeId = trim((string)$run['node_id']);
            if ($nodeId === '' || isset($nodeIds[$nodeId]) || in_array($nodeId, $data['removed_node_ids'], true)) continue;
            // Do not turn a valid full canvas into an unsaveable 201+ node
            // document on GET. Keep all run history and report deferred IDs.
            if (count($nodes) >= 200) { $pendingRecovery[$nodeId] = $nodeId; continue; }
            $nodes[] = self::recoveredNode($run, count($nodes));
            $nodeIds[$nodeId] = true;
            $recovered = true;
        }
        if ($recovered) {
            $now = time();
            $row = GraphService::persistLockedDocument($row, [
                'nodes_json' => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'update_time' => $now,
            ]);
            $data['nodes'] = $nodes;
            $data['update_time'] = $now;
            $row['nodes_json'] = json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $data['document_token'] = self::documentToken($row);
            if (array_key_exists('graph_revision', $row)) $data['graph_revision'] = (int)$row['graph_revision'];
        }
        $latest = [];
        foreach (array_reverse($runs) as $run) {
            $nodeId = (string)$run['node_id'];
            if ($nodeId !== '' && !in_array($nodeId, $data['removed_node_ids'], true) && !isset($latest[$nodeId])) $latest[$nodeId] = self::formatRun($run);
        }
        $data['runs'] = array_values($latest);
        $data['recovery_pending_node_ids'] = array_values($pendingRecovery);
        return $data;
    }

    /** Opaque content token, not a graph revision or a mutation receipt. */
    private static function documentToken(array $row): string
    {
        $fields = [];
        foreach (['id', 'tenant_id', 'user_id', 'title', 'nodes_json', 'edges_json', 'viewport_json', 'removed_node_ids_json', 'delete_time'] as $field) {
            $fields[$field] = (string)($row[$field] ?? '');
        }
        return hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Build a durable canvas node for an already-owned generation run. */
    private static function recoveredNode(array $run, int $index): array
    {
        $type = (string)($run['node_type'] ?? 'image');
        $request = self::decode((string)($run['request_json'] ?? ''));
        $labels = ['text' => '文本生成器', 'image' => '图像生成器', 'video' => '视频生成器', 'audio' => '音频生成器'];
        $tones = ['text' => 'orange', 'image' => 'blue', 'video' => 'green', 'audio' => 'purple'];
        return [
            'id' => (string)$run['node_id'],
            'type' => $type,
            'title' => $labels[$type] ?? '生成器',
            'description' => '已恢复的画布任务',
            'tone' => $tones[$type] ?? 'gray',
            'x' => 120 + ($index % 3) * 340,
            'y' => 160 + intdiv($index, 3) * 340,
            'width' => $type === 'audio' ? 444 : 250,
            'height' => 250,
            'metadata' => [
                'canvasRunId' => (int)$run['id'],
                'status' => (string)($run['status'] ?? 'running'),
                'progress' => (int)($run['progress'] ?? 0),
                'prompt' => (string)($request['prompt'] ?? $request['content'] ?? ''),
                'source' => 'recovered_task',
            ],
        ];
    }
    private static function formatRun(array $row): array { $result = self::decode((string)$row['result_json']); return ['id' => (int)$row['id'], 'node_id' => (string)$row['node_id'], 'type' => (string)$row['node_type'], 'status' => (string)$row['status'], 'progress' => (int)$row['progress'], 'error' => (string)$row['error'], 'result' => $result, 'results' => (array)($result['results'] ?? $result['images'] ?? $result['videos'] ?? [])]; }
}
