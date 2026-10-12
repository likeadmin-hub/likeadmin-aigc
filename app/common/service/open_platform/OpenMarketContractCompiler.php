<?php
namespace app\common\service\open_platform;

use app\common\service\power\{PowerMarketService, TenantPowerMarketService};
use think\facade\Db;

/** Readable full catalogue plan; imports reviewed metadata as drafts, never executes or publishes. */
final class OpenMarketContractCompiler
{
    private const VERSION = 1;
    private const PROTOCOL_PATHS = ['openai_chat' => '/api/v1/chat/completions', 'openai_completions' => '/api/v1/completions', 'openai_responses' => '/api/v1/responses', 'anthropic_messages' => '/api/v1/messages'];
    // Exact audited operations. New catalogue paths require an explicit code review.
    private const APPS = [
        'person_replacement' => ['submit', 'query'], 'action_transfer' => ['submit', 'query'], 'happy_horse' => ['submit', 'create', 'query'],
        'music_generation' => ['mashup_lyrics', 'vox', 'style', 'midi', 'timing', 'mp4', 'wav', 'voice_clone', 'persona', 'upload_audio', 'lyrics', 'query', 'create'],
        'smart_clip' => ['template', 'template_detail', 'realman_broadcast', 'broadcast_mixcut', 'news_mixcut'], 'image_human' => ['submit', 'query'], 'wan' => ['create', 'query'],
        'seedance' => ['createGroup', 'createAsset', 'getAsset', 'updateAsset', 'deleteAsset', 'query', 'create'], 'lipsync' => ['submit', 'query'],
        'dressing_diffusion' => ['submit', 'query'], 'mmaudio' => ['submit', 'query'], 'flashvsr' => ['submit', 'query'], 'seedsvc' => ['submit', 'query'],
        'watermark_removal' => ['remove', 'xhs_note_detail', 'xhs_user_note_list', 'douyin_user_video_list', 'xhs_user_detail', 'douyin_video_detail', 'douyin_search_video', 'xhs_search_note'],
        'voice_tts' => ['tts_live', 'clone_voice', 'tts', 'tts_async', 'stt', 'list_voices'], 'grok_video' => ['submit', 'query'], 'nano_banana' => ['submit', 'query'],
        'full_video' => ['submit', 'query'], 'file_qa' => ['chat', 'parse'], 'pic_lipsync' => ['submit', 'query'],
    ];
    private const RESOURCE_APIS = ['query', 'cancel', 'template', 'template_detail', 'createGroup', 'createAsset', 'getAsset', 'updateAsset', 'deleteAsset', 'list_voices', 'clone_voice', 'voice_clone', 'persona', 'upload_audio'];

    public static function plan(int $tenantId, array $reviews = [], array $productIds = []): array
    {
        self::tenant($tenantId); $products = TenantPowerMarketService::catalogueProducts($tenantId);
        if ($productIds) $products = array_values(array_filter($products, static fn($p) => in_array((int)$p['id'], array_map('intval', $productIds), true)));
        $auxiliary = [];
        foreach (Db::name('power_market_product')->where(['resource_type' => 'app_api', 'status' => 1])->field('id,upstream_app_code,upstream_api_code,source_payload')->select()->toArray() as $row) {
            $meta = OpenStore::json($row['source_payload'])['market_metadata'] ?? [];
            if (in_array($row['upstream_api_code'], ['query', 'cancel'], true)) $auxiliary[$row['upstream_app_code']][] = ['product_id' => (int)$row['id'], 'api_code' => $row['upstream_api_code'], 'method' => $meta['method'] ?? '', 'schema_present' => !empty($meta['params_schema']), 'doc_present' => !empty($meta['developer_doc_content'])];
        }
        $rows = []; $counts = ['products' => count($products), 'skus' => 0, 'candidates' => 0, 'drafts' => 0, 'blocked_candidates' => 0, 'products_with_drafts' => 0, 'blocked_products' => 0]; $groups = []; $blockers = [];
        foreach ($products as $product) {
            $row = self::compileProduct($product, $auxiliary[$product['upstream_app_code'] ?? ''] ?? [], $reviews[(string)$product['id']] ?? []); $rows[] = $row;
            $counts['skus'] += count($row['skus']); $counts['candidates'] += count($row['candidates']); $counts['drafts'] += count($row['drafts']);
            $counts[$row['drafts'] ? 'products_with_drafts' : 'blocked_products']++;
            $group = $row['resource_type'] . ':' . ($row['model_type'] ?: $row['app_code']); $groups[$group] = ($groups[$group] ?? 0) + 1;
            foreach ($row['candidates'] as $candidate) if (!$candidate['draftable']) { $counts['blocked_candidates']++; foreach ($candidate['blockers'] as $b) $blockers[$b['code']] = ($blockers[$b['code']] ?? 0) + 1; }
        }
        return ['compiler_version' => self::VERSION, 'tenant_id' => $tenantId, 'generated_at' => date(DATE_ATOM), 'counts' => $counts, 'groups' => $groups, 'blocker_counts' => $blockers, 'products' => $rows, 'publication' => 'drafts_only', 'execution_performed' => false];
    }

    public static function compileProduct(array $product, array $auxiliary = [], array $review = []): array
    {
        self::review($review);
        $snapshot = OpenStore::json($product['source_payload'] ?? []); $meta = (array)($snapshot['market_metadata'] ?? []);
        $raw = (array)($meta['upstream_metadata'] ?? $meta['upstream_api_metadata'] ?? []);
        $normal = OpenSchemaNormalizer::normalize((array)($review['input'] ?? $meta['params_schema'] ?? []));
        $input = $normal['schema']; $base = $normal['blockers'];
        $doc = (string)($meta['developer_doc_content'] ?? ''); $jsonExamples = self::documentExamples($doc);
        $model = $product['resource_type'] === 'model'; $app = (string)($product['upstream_app_code'] ?? ''); $api = (string)($product['upstream_api_code'] ?? '');
        $callType = (int)($meta['call_type'] ?? $raw['call_type'] ?? 0);
        $method = $model ? 'POST' : strtoupper((string)($meta['method'] ?? ''));
        $protocols = array_values(array_filter((array)($meta['protocols'] ?? []), 'is_string'));
        if (!empty($meta['protocol']) && !in_array($meta['protocol'], $protocols, true)) $protocols[] = $meta['protocol'];
        $paths = [];
        if ($model && ($product['model_type'] ?? '') === 'text') {
            foreach ($protocols as $protocol) if (isset(self::PROTOCOL_PATHS[$protocol])) $paths[] = ['protocol' => $protocol, 'path' => self::PROTOCOL_PATHS[$protocol], 'source' => 'market_metadata.protocols'];
            if (!$paths && str_contains($doc, '/api/v1/chat/completions')) $paths[] = ['protocol' => 'openai_chat', 'path' => '/api/v1/chat/completions', 'source' => 'reviewed_protocol_document'];
        } elseif ($model && in_array($product['model_type'] ?? '', ['image', 'video'], true)) $paths[] = ['protocol' => 'model_task', 'path' => '/api/v1/tasks', 'source' => 'MarketImageModelRuntimeService/MarketVideoRuntimeService'];
        elseif (!$model && isset(self::APPS[$app]) && in_array($api, self::APPS[$app], true)) $paths[] = ['protocol' => 'application_api', 'path' => '/api/v1/apps/' . $app . '/' . $api, 'source' => 'reviewed_app_catalogue'];
        if (!$paths) { $base[] = self::block('protocol_route_missing', '没有已审核的协议路由'); $paths[] = ['protocol' => '', 'path' => '', 'source' => 'missing']; }
        if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) $base[] = self::block('resource_adapter_required', '读取/目录操作需独立资源权限适配，不按免费接口导入');
        if (!$model && ($app === 'seedance' && $api !== 'create' || in_array($api, self::RESOURCE_APIS, true))) $base[] = self::block('ownership_adapter_required', '生命周期或共享资源操作需本地归属适配');
        if (!in_array($callType, [1, 2], true)) $base[] = self::block('lifecycle_mode_review', '同步/异步混合或未知执行模式需审核');
        foreach (array_keys($input['properties'] ?? []) as $field) if (preg_match('/(?:^|_)(?:audio_id|asset_id|file_id|group_id|persona_id|reference_id|task_id)(?:$|_)/i', $field)) $base[] = self::block('ownership_adapter_required', '输入资源 ID 需本地归属适配', $field);
        if ($model) {
            $input['properties']['model'] = ['type' => 'string', 'const' => (string)$product['upstream_model_code']];
            $input['required'] = array_values(array_unique(array_merge($input['required'] ?? [], ['model'])));
            if (!empty($product['upstream_channel_code'])) { $input['properties']['channel'] = ['type' => 'string', 'const' => (string)$product['upstream_channel_code']]; $input['required'][] = 'channel'; }
        }
        $example = $review['example'] ?? self::requestExample($input, (array)($meta['default_params'] ?? []), $jsonExamples);
        $responseSamples = array_values(array_filter($jsonExamples, static fn($sample) => isset($sample['code']) && $sample['code'] === 1 || isset($sample['choices']) || isset($sample['task_id'])));
        $responses = $review['responses'] ?? self::responses($responseSamples);
        if (!$responses) $base[] = self::block('response_schema_missing', '缺少明确成功响应或已审核响应结构');
        if ((self::economicFields($responses) || self::economicFields($responseSamples)) && !OpenMarketResponse::schemaSupported($responses)) $base[] = self::block('response_projection_required', '上游响应含尚未适配的成本/共享账户商业字段');
        $hasTask = false; foreach ($responseSamples as $sample) if (isset($sample['task_id']) || isset($sample['data']['task_id'])) $hasTask = true;
        if ($callType === 2 && !$hasTask && !isset($responses['200']['content']['application/json']['schema']['properties']['data']['properties']['task_id']) && !isset($responses['200']['content']['application/json']['schema']['properties']['task_id'])) $base[] = self::block('task_response_missing', '异步操作缺少任务 ID 响应声明');
        $taskRoot = isset($responses['200']['content']['application/json']['schema']['properties']['task_id']);
        $skus = []; $candidates = []; $drafts = []; $signatures = [];
        foreach ($product['skus'] ?? [] as $sku) { $key = OpenPolicy::fingerprint(OpenStore::json($sku['locked_params'] ?? [])); $signatures[$key] = ($signatures[$key] ?? 0) + 1; }
        foreach ($product['skus'] ?? [] as $sku) {
            $locked = OpenStore::json($sku['locked_params'] ?? []);
            $skus[] = ['sku_id' => (int)$sku['id'], 'sku_key' => (string)$sku['sku_key'], 'usage_unit' => (string)$sku['usage_unit'], 'usage_unit_size' => (float)$sku['usage_unit_size'], 'sale_points' => (float)$sku['sale_points'], 'sale_status' => (bool)$sku['sale_status'], 'locked_params' => OpenPolicy::publicData($locked)];
            foreach ($paths as $route) {
                $issues = $base; $candidateInput = $input; $candidateExample = $example; $when = [];
                if (($sku['usage_unit'] ?? '') !== 'per_call' || (float)($sku['usage_unit_size'] ?? 0) !== 1.) $issues[] = self::block('billing_adapter_required', '仅固定单调用计费可自动导入；实际用量需可信数量与上限');
                if (empty($sku['sale_status'])) $issues[] = self::block('sku_not_sellable', 'SKU 已下架');
                if (($signatures[OpenPolicy::fingerprint($locked)] ?? 0) > 1) $issues[] = self::block('ambiguous_sku', '多个 SKU 锁定相同维度，需可靠选择器');
                foreach ($locked as $field => $value) {
                    if (str_starts_with($field, '_')) { $issues[] = self::block('derived_sku_parameter', '内部计费维度需服务器派生', $field); continue; }
                    if (!isset($candidateInput['properties'][$field])) { $issues[] = self::block('sku_parameter_missing', 'SKU 锁定参数未声明在请求结构中', $field); continue; }
                    $candidateInput['properties'][$field]['const'] = $value; $candidateInput['required'] = array_values(array_unique(array_merge($candidateInput['required'] ?? [], [$field]))); $candidateExample[$field] = $value; $when[$field] = $value;
                }
                if ($model && !empty($product['upstream_channel_code'])) $when['channel'] = (string)$product['upstream_channel_code'];
                $code = 'market.p' . (int)$product['id'] . '.' . $route['protocol'] . '.s' . (int)$sku['id'];
                $config = ['response_adapter' => OpenMarketResponse::ADAPTER, 'executor' => 'json', 'role' => 'submit', 'title' => (string)$product['name'], 'billable' => true, 'input' => $candidateInput, 'example' => $candidateExample, 'responses' => $responses, 'when' => $when, 'billing' => [['sku_id' => (int)$sku['id'], 'basis' => 'input', 'max_units' => 1]], 'billing_description' => '按当前租户有效商城 SKU 售价，单调用计费；任务查询不重复收费', 'query_path' => '/api/v1/tasks/{task_id}', 'task_id_path' => $taskRoot ? 'task_id' : 'data.task_id'];
                if ($model) $config['model'] = (string)$product['upstream_model_code'];
                $draft = ['code' => $code, 'kind' => 'market', 'product_id' => (int)$product['id'], 'method' => $method, 'path' => $route['path'], 'config' => $config];
                try { OpenContracts::validate($draft); } catch (\Throwable $error) { $issues[] = self::block('contract_validation_failed', $error instanceof OpenException ? $error->getMessage() : '契约结构无法验证'); }
                $issues = self::unique($issues);
                $candidates[] = ['code' => $code, 'sku_id' => (int)$sku['id'], 'method' => $method, 'path' => $route['path'], 'protocol' => $route['protocol'], 'route_source' => $route['source'], 'draftable' => !$issues, 'blockers' => $issues];
                if (!$issues) $drafts[] = $draft;
            }
        }
        if (!$skus) $base[] = self::block('sku_missing', '缺少有效用户售价 SKU');
        $sourceDigest = OpenPolicy::fingerprint(['product_id' => (int)$product['id'], 'metadata' => $meta, 'skus' => $skus, 'review' => $review]);
        foreach ($drafts as &$draft) $draft['config']['compiler'] = ['version' => self::VERSION, 'source_digest' => $sourceDigest, 'review_note' => $review['review_note'] ?? 'catalogue metadata only']; unset($draft);
        $allIssues = $base; foreach ($candidates as $candidate) $allIssues = array_merge($allIssues, $candidate['blockers']);
        $category = $model ? ['category_code' => $product['model_type'] ?? '', 'category_name' => $product['model_type'] ?? ''] : PowerMarketService::appCategory($product);
        return ['product_id' => (int)$product['id'], 'name' => (string)$product['name'], 'resource_type' => $product['resource_type'], 'model_type' => $product['model_type'] ?? '', 'model_code' => $product['upstream_model_code'] ?? '', 'channel_code' => $product['upstream_channel_code'] ?? '', 'app_code' => $app, 'api_code' => $api, 'category_code' => $category['category_code'], 'category_name' => $category['category_name'], 'source_digest' => $sourceDigest, 'schema_shape' => $normal['shape'], 'input' => OpenPolicy::publicData($input), 'declared_protocols' => $protocols, 'call_type' => $callType, 'doc_present' => $doc !== '', 'source_limits' => ['max_tokens' => $meta['max_tokens'] ?? null, 'context_window' => $meta['context_window'] ?? null], 'auxiliary_apis' => $auxiliary, 'skus' => $skus, 'candidates' => $candidates, 'drafts' => $drafts, 'blockers' => self::unique($allIssues)];
    }

    public static function import(int $tenantId, array $reviews = [], array $productIds = []): array
    {
        // Catalogue pricing/availability are re-resolved; callers cannot supply an executable plan.
        $plan = self::plan($tenantId, $reviews, $productIds); $created = []; $skipped = [];
        Db::transaction(function() use ($plan, &$created, &$skipped) {
            // Serialize imports without introducing settings or changing tenant enablement.
            if ($plan['products']) Db::name('power_market_product')->whereIn('id', array_column($plan['products'], 'product_id'))->order('id')->lock(true)->column('id');
            foreach ($plan['products'] as $product) foreach ($product['drafts'] as $draft) {
                $slug = str_replace('.', '-', $draft['code']);
                $existing = OpenStore::table('contract')->where('code', $draft['code'])->order('version', 'desc')->find();
                $document = OpenStore::table('document')->where(['tenant_id' => 0, 'slug' => $slug])->find();
                if ($existing || $document) { $skipped[] = ['code' => $draft['code'], 'reason' => $existing ? 'existing_contract_preserved' : 'existing_document_preserved', 'contract_id' => (int)($existing['id'] ?? 0), 'status' => $existing['status'] ?? 'document_exists']; continue; }
                $contract = OpenContracts::save($draft);
                $doc = OpenDocuments::save(0, ['slug' => $slug, 'title' => $product['name'] . ' API', 'category' => $product['category_name'] ?: '商城 API', 'contract_code' => $draft['code'], 'content' => self::document($product, $draft)]);
                $created[] = ['code' => $draft['code'], 'contract_id' => (int)$contract['id'], 'document_id' => (int)$doc['id'], 'status' => 'draft'];
            }
        });
        return ['tenant_id' => $tenantId, 'counts' => $plan['counts'], 'created' => $created, 'skipped' => $skipped, 'published' => 0, 'execution_performed' => false, 'blocker_counts' => $plan['blocker_counts']];
    }

    private static function tenant(int $tenantId): void { if ($tenantId <= 0) throw new OpenException('invalid_tenant', '请明确目标租户'); }
    private static function review(array $review): void
    {
        if (!$review) return;
        if (array_diff(array_keys($review), ['review_note', 'input', 'example', 'responses']) || !is_string($review['review_note'] ?? null) || trim($review['review_note']) === '' || strlen($review['review_note']) > 2000) throw new OpenException('invalid_review', '仅接受明确审核的参数、示例及响应结构');
        foreach (['input', 'example', 'responses'] as $field) if (isset($review[$field]) && !is_array($review[$field])) throw new OpenException('invalid_review', '审核结构必须为对象');
        self::noSecrets($review);
    }
    private static function noSecrets(array $value, int $depth = 0): void
    {
        if ($depth > 32) throw new OpenException('invalid_review', '审核结构嵌套超限');
        foreach ($value as $name => $item) {
            if (is_string($name) && OpenSchemaNormalizer::sensitiveName($name)) throw new OpenException('invalid_review', '审核结构不能包含凭据或内部字段');
            if (is_array($item)) self::noSecrets($item, $depth + 1);
        }
    }
    private static function documentExamples(string $doc): array
    {
        preg_match_all('/```json\s*([\s\S]*?)```/i', $doc, $matches); $examples = [];
        foreach ($matches[1] ?? [] as $text) { $object = json_decode(trim($text)); if ($object instanceof \stdClass) $examples[] = (array)$object; }
        // Decode nested JSON objects as arrays for paths while preserving empty object/list distinctions in examples.
        return array_map(static fn($example) => json_decode(OpenStore::encode($example), true), $examples);
    }
    private static function requestExample(array $input, array $defaults, array $examples): array
    {
        foreach ($examples as $example) {
            if (isset($example['code']) || isset($example['error']) || isset($example['choices'])) continue;
            try { OpenPolicy::validateInput($example); OpenSchema::validate($example, $input); if ($example || empty($input['required'])) return $example; } catch (\Throwable) {}
        }
        $example = [];
        foreach ($input['properties'] ?? [] as $name => $field) {
            if (in_array($name, ['callback_url', 'webhook_url', 'callbackUrl'], true)) continue;
            foreach (['const', 'example', 'default'] as $key) if (array_key_exists($key, $field)) { $example[$name] = $field[$key]; continue 2; }
            if (array_key_exists($name, $defaults)) $example[$name] = $defaults[$name];
        }
        return $example;
    }
    private static function responses(array $samples): array
    {
        if (!$samples) return [];
        return ['200' => ['description' => '上游文档声明的成功响应，须由真实网关回执验收', 'content' => ['application/json' => ['schema' => self::responseShape($samples[0])]]]];
    }
    private static function responseShape(mixed $value, int $depth = 0): array
    {
        if ($depth > 32) return [];
        if (is_array($value)) {
            if ($value && array_is_list($value)) return ['type' => 'array', 'items' => self::responseShape($value[0], $depth + 1)];
            $properties = []; foreach ($value as $key => $item) $properties[$key] = self::responseShape($item, $depth + 1);
            return ['type' => 'object', 'properties' => $properties];
        }
        return ['type' => match (true) {is_int($value) => 'integer', is_float($value) => 'number', is_bool($value) => 'boolean', $value === null => 'null', default => 'string'}];
    }
    private static function economicFields(array $value, int $depth = 0): bool
    {
        if ($depth > 32) return true;
        foreach ($value as $key => $item) {
            if (is_string($key) && (OpenSchemaNormalizer::sensitiveName($key) || in_array(strtolower($key), ['points_cost', 'actual_points', 'frozen_points', 'points', 'upstream_price', 'tenant_cost'], true))) return true;
            if (is_array($item) && self::economicFields($item, $depth + 1)) return true;
        }
        return false;
    }
    private static function document(array $product, array $draft): string
    {
        $c = $draft['config']; $encode = static fn($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return '# ' . $product['name'] . "\n\n此接口为待验收草稿；文档草稿不代表可调用或已发布。\n\n" . $draft['method'] . ' `' . $draft['path'] . "`\n\n按当前租户有效商城 SKU 售价结算，不使用上游账户价格。查询原任务不重复收费。\n\n参数结构：\n\n```json\n" . $encode($c['input']) . "\n```\n\n请求示例：\n\n```json\n" . $encode((object)$c['example']) . "\n```\n\n响应声明：\n\n```json\n" . $encode($c['responses']) . "\n```\n\n来源摘要：`" . $product['source_digest'] . "`。必须关联与本契约及示例一致、成功完成结算的真实网关回执后方可发布。\n";
    }
    private static function block(string $code, string $message, string $field = ''): array { return compact('code', 'field', 'message'); }
    private static function unique(array $issues): array { $out = []; foreach ($issues as $issue) $out[OpenStore::encode($issue)] = $issue; return array_values($out); }
}
