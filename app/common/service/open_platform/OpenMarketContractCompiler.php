<?php
namespace app\common\service\open_platform;

use app\common\service\power\{PowerMarketService, TenantPowerMarketService};
use think\facade\Db;

/** Readable full catalogue plan; imports reviewed metadata as drafts, never executes or publishes. */
final class OpenMarketContractCompiler
{
    private const VERSION = 2;
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
        self::tenant($tenantId); $products = TenantPowerMarketService::catalogueProducts($tenantId);$modelCounts=[];foreach($products as $product)if($product['resource_type']==='model')$modelCounts[$product['upstream_model_code']]=($modelCounts[$product['upstream_model_code']]??0)+1;
        if ($productIds) $products = array_values(array_filter($products, static fn($p) => in_array((int)$p['id'], array_map('intval', $productIds), true)));
        $auxiliary = [];
        foreach (Db::name('power_market_product')->where(['resource_type' => 'app_api', 'status' => 1])->field('id,upstream_app_code,upstream_api_code,source_payload')->select()->toArray() as $row) {
            $meta = OpenStore::json($row['source_payload'])['market_metadata'] ?? [];
            if (in_array($row['upstream_api_code'], ['query', 'cancel'], true)) $auxiliary[$row['upstream_app_code']][] = ['product_id' => (int)$row['id'], 'api_code' => $row['upstream_api_code'], 'method' => $meta['method'] ?? '', 'schema_present' => !empty($meta['params_schema']), 'doc_present' => !empty($meta['developer_doc_content'])];
        }
        $rows = []; $counts = ['products' => count($products), 'skus' => 0, 'candidates' => 0, 'drafts' => 0, 'blocked_candidates' => 0, 'products_with_drafts' => 0, 'blocked_products' => 0]; $groups = []; $blockers = [];
        foreach ($products as $product) {
            $row = self::compileProduct($product, $auxiliary[$product['upstream_app_code'] ?? ''] ?? [], $reviews[(string)$product['id']] ?? [], ($modelCounts[$product['upstream_model_code']??'']??0)>1); $rows[] = $row;
            $counts['skus'] += count($row['skus']); $counts['candidates'] += count($row['candidates']); $counts['drafts'] += count($row['drafts']);
            $counts[$row['drafts'] ? 'products_with_drafts' : 'blocked_products']++;
            $group = $row['resource_type'] . ':' . ($row['model_type'] ?: $row['app_code']); $groups[$group] = ($groups[$group] ?? 0) + 1;
            foreach ($row['candidates'] as $candidate) if (!$candidate['draftable']) { $counts['blocked_candidates']++; foreach ($candidate['blockers'] as $b) $blockers[$b['code']] = ($blockers[$b['code']] ?? 0) + 1; }
        }
        return ['compiler_version' => self::VERSION, 'tenant_id' => $tenantId, 'generated_at' => date(DATE_ATOM), 'counts' => $counts, 'groups' => $groups, 'blocker_counts' => $blockers, 'products' => $rows, 'publication' => 'drafts_only', 'execution_performed' => false];
    }

    public static function compileProduct(array $product, array $auxiliary = [], array $review = [], bool $requireChannel = false): array
    {
        self::review($review);
        $snapshot = OpenStore::json($product['source_payload'] ?? []); $meta = (array)($snapshot['market_metadata'] ?? []);
        $raw = (array)($meta['upstream_metadata'] ?? $meta['upstream_api_metadata'] ?? []);
        $requestSource = (array)($review['input'] ?? $meta['params_schema'] ?? []);
        $requestDefinition = !$requestSource && !array_key_exists('input', $review) && $product['resource_type'] === 'app_api'
            ? OpenMarketProtocolSchemas::applicationInput((string)($product['upstream_app_code'] ?? ''), (string)($product['upstream_api_code'] ?? '')) : null;
        if ($requestDefinition) $requestSource = $requestDefinition['request_schema'];
        $normal = OpenSchemaNormalizer::normalize($requestSource);
        $input = $normal['schema']; $base = $normal['blockers'];
        $defaults = (array)($meta['default_params'] ?? $requestDefinition['default_params'] ?? []);
        $schemaSources = $requestDefinition ? ['input' => $requestDefinition['trace']] : [];
        $doc = (string)($meta['developer_doc_content'] ?? ''); $jsonExamples = self::documentExamples($doc);
        $model = $product['resource_type'] === 'model'; $app = (string)($product['upstream_app_code'] ?? ''); $api = (string)($product['upstream_api_code'] ?? '');
        $characterApi=OpenCharacterInputPricing::productSupported($product);$characterConfiguration=[];
        if($characterApi){try{$characterConfiguration=OpenCharacterInputPricing::configuration($product);}catch(OpenException $e){$base[]=self::block('character_defaults_review',$e->getMessage());}
            if(($input['properties']['text']['type']??'')!=='string')$base[]=self::block('character_input_review','字符接口必须声明原协议 root text');
            $input['required']=array_values(array_unique(array_merge($input['required']??[],['text'])));$input['properties']['text']['minLength']=1;$input['properties']['text']['example']='你好';$input['additionalProperties']=false;
            if(isset($input['properties']['reference_id']))$input['properties']['reference_id']=['type'=>'string','const'=>'','description'=>'当前仅支持供应商默认音色；指定音色需资源归属适配'];
        }
        $callType = (int)($meta['call_type'] ?? $raw['call_type'] ?? $requestDefinition['call_type'] ?? 0);
        $method = $model ? 'POST' : strtoupper((string)($meta['method'] ?? $requestDefinition['method'] ?? ''));
        $protocols = array_values(array_filter((array)($meta['protocols'] ?? []), 'is_string'));
        if (!empty($meta['protocol']) && !in_array($meta['protocol'], $protocols, true)) $protocols[] = $meta['protocol'];
        $paths = [];
        if ($model && ($product['model_type'] ?? '') === 'text') {
            foreach ($protocols as $protocol) if (isset(self::PROTOCOL_PATHS[$protocol])) $paths[] = ['protocol' => $protocol, 'path' => self::PROTOCOL_PATHS[$protocol], 'source' => 'market_metadata.protocols'];
            if (!$paths && str_contains($doc, '/api/v1/chat/completions')) $paths[] = ['protocol' => 'openai_chat', 'path' => '/api/v1/chat/completions', 'source' => 'reviewed_protocol_document'];
        } elseif ($model && in_array($product['model_type'] ?? '', ['image', 'video'], true)) $paths[] = ['protocol' => 'model_task', 'path' => '/api/v1/tasks', 'source' => 'MarketImageModelRuntimeService/MarketVideoRuntimeService'];
        elseif (!$model && isset(self::APPS[$app]) && in_array($api, self::APPS[$app], true)) $paths[] = ['protocol' => 'application_api', 'path' => '/api/v1/apps/' . $app . '/' . $api, 'source' => 'reviewed_app_catalogue'];
        if (!$paths) { $base[] = self::block('protocol_route_missing', '没有已审核的协议路由'); $paths[] = ['protocol' => '', 'path' => '', 'source' => 'missing']; }
        $protocolInputs = [];
        if ($model && ($product['model_type'] ?? '') === 'text' && !$requestSource && !array_key_exists('input', $review)) {
            foreach ($paths as $route) if ($definition = OpenMarketProtocolSchemas::definition($route['protocol'])) {
                $protocolInputs[$route['protocol']] = $definition;
            }
            if ($protocolInputs) $base = array_values(array_filter($base, static fn($issue) => $issue['code'] !== 'schema_missing'));
        }
        if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) $base[] = self::block('resource_adapter_required', '读取/目录操作需独立资源权限适配，不按免费接口导入');
        if (!$model && ($app === 'seedance' && $api !== 'create' || in_array($api, self::RESOURCE_APIS, true))) $base[] = self::block('ownership_adapter_required', '生命周期或共享资源操作需本地归属适配');
        if (!in_array($callType, [1, 2], true)) $base[] = self::block('lifecycle_mode_review', '同步/异步混合或未知执行模式需审核');
        foreach (array_keys($input['properties'] ?? []) as $field) if (preg_match('/(?:^|_)(?:audio_id|asset_id|file_id|group_id|persona_id|reference_id|task_id)(?:$|_)/i', $field)&&!($characterApi&&$field==='reference_id')) $base[] = self::block('ownership_adapter_required', '输入资源 ID 需本地归属适配', $field);
        if ($model) {
            $input['properties']['model'] = ['type' => 'string', 'const' => (string)$product['upstream_model_code']];
            $input['required'] = array_values(array_unique(array_merge($input['required'] ?? [], ['model'])));
            if (!empty($product['upstream_channel_code'])) { $input['properties']['channel'] = ['type' => 'string', 'const' => (string)$product['upstream_channel_code']]; if($requireChannel)$input['required'][] = 'channel'; }
        }
        $example = $review['example'] ?? ($requestDefinition['request_example'] ?? self::requestExample($input, $defaults, $jsonExamples));
        $responseSamples = array_values(array_filter($jsonExamples, static fn($sample) => isset($sample['code']) && $sample['code'] === 1 || isset($sample['choices']) || isset($sample['task_id'])));
        $responses = OpenMarketResponse::retailSchemas($review['responses'] ?? self::responses($responseSamples));
        $responseDefinition = !$responses && !array_key_exists('responses', $review) && $model && in_array($product['model_type'] ?? '', ['image', 'video'], true)
            ? OpenMarketProtocolSchemas::modelTask($product) : null;
        if ($responseDefinition) {
            $responses = OpenMarketResponse::retailSchemas($responseDefinition['submit_responses']);
            $schemaSources['response'] = $responseDefinition['trace'];
        }
        if (!$responses) $base[] = self::block('response_schema_missing', '缺少明确成功响应或已审核响应结构');
        // A reviewed omission cannot erase evidence in the original source envelope.
        $sourceSupported = true;
        foreach ($jsonExamples as $sample) if (isset($sample['code']) || isset($sample['choices']) || isset($sample['task_id']) || isset($sample['error'])) {
            if (!OpenMarketResponse::bodySupported(OpenStore::encode((object)$sample))) $sourceSupported = false;
        }
        if ($responseDefinition) {
            if (!OpenMarketResponse::schemaSupported($responseDefinition['query_responses'])) $sourceSupported = false;
            foreach (['submit_responses', 'query_responses'] as $field) foreach ($responseDefinition[$field] as $response) foreach ($response['content'] as $content) foreach ($content['examples'] ?? [] as $registryExample) {
                if (!OpenMarketResponse::bodySupported(OpenStore::encode($registryExample['value']))) $sourceSupported = false;
            }
        }
        if (!$sourceSupported || !OpenMarketResponse::schemaSupported($responses)) $base[] = self::block('response_projection_required', '上游原始响应或审核结构含尚未适配的协议字段');
        $hasTask = false; foreach ($responseSamples as $sample) if (OpenSchema::get($sample,'task_id')!==null || OpenSchema::get($sample,'data.task_id')!==null) $hasTask = true;
        if ($callType === 2 && !$hasTask && !isset($responses['200']['content']['application/json']['schema']['properties']['data']['properties']['task_id']) && !isset($responses['200']['content']['application/json']['schema']['properties']['task_id'])) $base[] = self::block('task_response_missing', '异步操作缺少任务 ID 响应声明');
        $taskRoot = isset($responses['200']['content']['application/json']['schema']['properties']['task_id']);
        $nativeMedia = OpenNativeMarketPricing::supports($product);
        $skus = []; $candidates = []; $drafts = []; $signatures = [];
        foreach ($product['skus'] ?? [] as $sku) { $key = OpenPolicy::fingerprint(OpenStore::json($sku['locked_params'] ?? [])); $signatures[$key] = ($signatures[$key] ?? 0) + 1; }
        foreach ($product['skus'] ?? [] as $sku) {
            $locked = OpenStore::json($sku['locked_params'] ?? []);
            $character=OpenCharacterInputPricing::supports($product,$sku);
            $skus[] = ['sku_id' => (int)$sku['id'], 'sku_key' => (string)$sku['sku_key'], 'usage_unit' => (string)$sku['usage_unit'], 'usage_unit_size' => (float)$sku['usage_unit_size'], 'sale_points' => (float)$sku['sale_points'], 'sale_status' => (bool)$sku['sale_status'], 'locked_params' => OpenPolicy::publicData($locked)];
            foreach ($paths as $route) {
                $issues = $base; $candidateInput = $input; $candidateExample = $example; $when = []; $whenDefaults=[]; $parameterPaths=[];
                $candidateSources = $schemaSources;
                if (isset($protocolInputs[$route['protocol']])) {
                    $definition = $protocolInputs[$route['protocol']];
                    $candidateInput = $definition['request_schema'];
                    $candidateInput['properties']['model'] = ['type' => 'string', 'const' => (string)$product['upstream_model_code']];
                    if (!empty($product['upstream_channel_code'])) {
                        $candidateInput['properties']['channel'] = $input['properties']['channel'];
                        if ($requireChannel) $candidateInput['required'][] = 'channel';
                    }
                    $candidateExample = $review['example'] ?? $definition['request_example'];
                    $candidateExample['model'] = (string)$product['upstream_model_code'];
                    if ($requireChannel && !empty($product['upstream_channel_code'])) $candidateExample['channel'] = (string)$product['upstream_channel_code'];
                    $candidateSources['input'] = $definition['trace'];
                } elseif ($protocolInputs) $issues[] = self::block('schema_missing', '该协议尚无已审核的请求结构');
                try{OpenSkuConsistency::assert($sku);}catch(OpenException $e){$issues[]=self::block('pricing_spec_mismatch',$e->getMessage());}
                if (\app\common\service\power\MarketUsageSettlementService::isActualUsageSku($sku)) $issues[] = self::block('actual_usage_boundary_required','Token SKU 已存在，透传执行仍需真实用量结算与可信受理额度');
                elseif (!$nativeMedia && !$character && (($sku['usage_unit'] ?? '') !== 'per_call' || (float)($sku['usage_unit_size'] ?? 0) !== 1.)) $issues[] = self::block('billing_adapter_required', '当前业务尚未接入原生数量适配');
                elseif ($nativeMedia && !in_array($sku['usage_unit']??'',['per_call','input_second','output_second','second','seconds','sec'],true)) $issues[] = self::block('billing_adapter_required','原生媒体单位尚未适配');
                if (empty($sku['sale_status'])) $issues[] = self::block('sku_not_sellable', 'SKU 已下架');
                if (($signatures[OpenPolicy::fingerprint($locked)] ?? 0) > 1) $issues[] = self::block('ambiguous_sku', '多个 SKU 锁定相同维度，需可靠选择器');
                foreach ($locked as $field => $value) {
                    if (str_starts_with($field, '_')) { $issues[] = self::block('derived_sku_parameter', '内部计费维度需服务器派生', $field); continue; }
                    $pathsForField=self::fieldPaths($candidateInput,$field);if(count($pathsForField)!==1){$issues[]=self::block('sku_parameter_missing','SKU 锁定参数未唯一声明在请求结构中',$field);continue;}
                    $parameterPath=$pathsForField[0];$parameterPaths[$field]=$parameterPath;self::lockField($candidateInput,$candidateExample,$parameterPath,$value,$whenDefaults);$when[$parameterPath]=$value;
                }
                if ($model && $requireChannel && !empty($product['upstream_channel_code'])) $when['channel'] = (string)$product['upstream_channel_code'];
                $code = 'market.p' . (int)$product['id'] . '.' . $route['protocol'] . '.s' . (int)$sku['id'];
                $config = ['response_adapter' => OpenMarketResponse::ADAPTER, 'executor' => 'json', 'role' => 'submit', 'title' => (string)$product['name'], 'billable' => true, 'input' => $candidateInput, 'example' => $candidateExample, 'responses' => $responses, 'when' => $when, 'when_defaults'=>$whenDefaults, 'billing' => [['sku_id' => (int)$sku['id'], 'basis' => 'input', 'max_units' => 1,'parameter_paths'=>$parameterPaths]], 'billing_description' => '按当前租户有效商城 SKU 售价，单调用计费；任务查询不重复收费', 'query_path' => '/api/v1/tasks/{task_id}', 'task_id_path' => $taskRoot ? 'task_id' : 'data.task_id'];
                if($nativeMedia){$config['native_pricing']=OpenNativeMarketPricing::ADAPTER;$config['success_statuses']=['success','completed','succeeded','partial_success','partial_failed'];$config['billing_description']='按原生媒体 SKU 及实际成功数量/用量结算，受理参数仅用于价格映射，原协议不改写';}
                if($character){$config['native_pricing']=OpenCharacterInputPricing::ADAPTER;$config+=$characterConfiguration;$config['billing_description']='按实际输入 UTF-8 字符数量及已冻结商城 SKU 单位售价计费；当前只支持供应商默认音色，查询不重复扣款';}
                if ($candidateSources) $config['compiler'] = ['schema_sources' => $candidateSources];
                if ($responseDefinition) $config['query_responses'] = OpenMarketResponse::retailSchemas($responseDefinition['query_responses']);
                if ($model){$config['model'] = (string)$product['upstream_model_code'];if(!empty($product['upstream_channel_code']))$config['routing_channel']=(string)$product['upstream_channel_code'];}
                $draft = ['code' => $code, 'kind' => 'market', 'product_id' => (int)$product['id'], 'method' => $method, 'path' => $route['path'], 'config' => $config];
                try { OpenContracts::validate($draft); } catch (\Throwable $error) { $issues[] = self::block('contract_validation_failed', $error instanceof OpenException ? $error->getMessage() : '契约结构无法验证'); }
                $issues = self::unique($issues);
                $candidates[] = ['code' => $code, 'sku_id' => (int)$sku['id'], 'method' => $method, 'path' => $route['path'], 'protocol' => $route['protocol'], 'route_source' => $route['source'], 'input' => OpenPolicy::publicData($candidateInput), 'example' => OpenPolicy::publicData($candidateExample), 'schema_sources' => $candidateSources, 'responses' => OpenPolicy::publicData($responses), 'draftable' => !$issues, 'blockers' => $issues];
                if (!$issues) $drafts[] = $draft;
            }
        }
        if (!$skus) $base[] = self::block('sku_missing', '缺少有效用户售价 SKU');
        $sourceDigest = OpenPolicy::fingerprint(['product_id' => (int)$product['id'], 'metadata' => $meta, 'skus' => $skus, 'review' => $review, 'schema_sources' => $schemaSources, 'protocol_input_sources' => array_map(static fn($definition) => $definition['trace'], $protocolInputs)]);
        foreach ($drafts as &$draft) $draft['config']['compiler'] = ['version' => self::VERSION, 'source_digest' => $sourceDigest, 'review_note' => $review['review_note'] ?? 'catalogue metadata and registered protocol structure only'] + ($draft['config']['compiler'] ?? []); unset($draft);
        $allIssues = $base; foreach ($candidates as $candidate) $allIssues = array_merge($allIssues, $candidate['blockers']);
        $category = $model ? ['category_code' => $product['model_type'] ?? '', 'category_name' => $product['model_type'] ?? ''] : PowerMarketService::appCategory($product);
        return ['product_id' => (int)$product['id'], 'name' => (string)$product['name'], 'resource_type' => $product['resource_type'], 'model_type' => $product['model_type'] ?? '', 'model_code' => $product['upstream_model_code'] ?? '', 'channel_code' => $product['upstream_channel_code'] ?? '', 'app_code' => $app, 'api_code' => $api, 'category_code' => $category['category_code'], 'category_name' => $category['category_name'], 'source_digest' => $sourceDigest, 'schema_shape' => $normal['shape'], 'input' => OpenPolicy::publicData($input), 'declared_protocols' => $protocols, 'schema_sources' => $schemaSources, 'protocol_inputs' => array_map(static fn($definition) => ['input' => $definition['request_schema'], 'example' => $definition['request_example'], 'source' => $definition['trace']], $protocolInputs), 'call_type' => $callType, 'doc_present' => $doc !== '', 'source_limits' => ['max_tokens' => $meta['max_tokens'] ?? null, 'context_window' => $meta['context_window'] ?? null], 'auxiliary_apis' => $auxiliary, 'skus' => $skus, 'candidates' => $candidates, 'drafts' => $drafts, 'blockers' => self::unique($allIssues)];
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
    private static function fieldPaths(array $input,string $field): array
    {
        $paths=[];if(isset($input['properties'][$field]))$paths[]=$field;
        foreach(['parameters','params'] as $parent)if(isset($input['properties'][$parent]['properties'][$field]))$paths[]=$parent.'.'.$field;
        return $paths;
    }
    private static function lockField(array &$input,array &$example,string $path,mixed $value,array &$whenDefaults): void
    {
        $parts=explode('.',$path);$lookup=$input;foreach($parts as $part)$lookup=$lookup['properties'][$part];$hasDefault=array_key_exists('default',$lookup)&&$lookup['default']===$value;if($hasDefault)$whenDefaults[$path]=$value;$schema=&$input;$data=&$example;
        foreach($parts as $i=>$part){if(!$hasDefault)$schema['required']=array_values(array_unique(array_merge($schema['required']??[],[$part])));
            if($i===count($parts)-1){$schema['properties'][$part]['const']=$value;$data[$part]=$value;break;}
            $schema=&$schema['properties'][$part];if(($data[$part]??null) instanceof \stdClass)$data[$part]=(array)$data[$part];if(!isset($data[$part]))$data[$part]=[];$data=&$data[$part];
        }
    }
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
        // Only the request root becomes an array. Nested objects stay typed.
        return $examples;
    }
    private static function requestExample(array $input, array $defaults, array $examples): array
    {
        foreach ($examples as $example) {
            if (isset($example['code']) || isset($example['error']) || isset($example['choices'])) continue;
            try { OpenPolicy::validateInput($example); OpenSchema::validate((object)$example, $input,'$',0,null,true); if ($example || empty($input['required'])) return $example; } catch (\Throwable) {}
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
        return ['200' => ['description' => '成功响应', 'content' => ['application/json' => ['schema' => self::responseShape((object)$samples[0])]]]];
    }
    private static function responseShape(mixed $value, int $depth = 0,string $path=''): array
    {
        if ($depth > 32) return [];
        if ($value instanceof \stdClass) {
            $properties = []; foreach (get_object_vars($value) as $key => $item) $properties[$key] = self::responseShape($item, $depth + 1,$path.$key.'.');
            return ['type' => 'object', 'properties' => $properties];
        }
        if (is_array($value)) return ['type' => 'array', 'items' => $value ? self::responseShape(reset($value), $depth + 1,$path.'[].') : []];
        if(OpenMarketResponse::retailPath(rtrim($path,'.'))&&(is_int($value)||is_float($value)))return ['type'=>'number'];
        return ['type' => match (true) {is_int($value) => 'integer', is_float($value) => 'number', is_bool($value) => 'boolean', $value === null => 'null', default => 'string'}];
    }
    private static function document(array $product, array $draft): string
    {
        $c=$draft['config'];$encode=static fn($v)=>json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $responseExamples = '';
        foreach (['responses' => '提交响应结构示例', 'query_responses' => '任务查询结构示例'] as $field => $title) {
            $examples = $c[$field]['200']['content']['application/json']['examples'] ?? [];
            if (!$examples) continue;
            $responseExamples .= "\n## " . $title . "\n\n以下为协议结构示例，积分为中性占位值，不是报价或真实调用回执。\n";
            foreach ($examples as $example) $responseExamples .= "\n```json\n" . $encode($example['value']) . "\n```\n";
        }
        return '# '.$product['name']."\n\n".$draft['method'].' `'.$draft['path']."`\n\n## 接入与鉴权\n\n使用本站 API Key，将上游地址替换为本站地址，保留此接口的参数结构。请求头使用 `Authorization: Bearer <API_KEY>` 和 `Content-Type: application/json`。完整密钥仅在创建时显示，请在服务端安全保管。\n\n## 请求参数\n\n字段类型、必填项、嵌套字段及约束见参数表。\n\n```json\n".$encode((object)$c['example'])."\n```\n\n## 价格与结算\n\n按本站当前有效商城 SKU 售价计费，在线调试提供当前费用预估。受理时保存价格快照，响应中的积分金额为本站预占或实际结算金额。结果尚未确认或缺少可信用量时，费用保留待处理。\n\n".$c['billing_description']."\n\n## 调用示例\n\ncURL、Python 与 JavaScript 示例见右侧代码面板。替换接入地址和自己的 API Key 后即可调用；不要在浏览器公开完整密钥。\n\n## 响应与任务\n\n保留上游业务字段及 HTTP 状态。响应头 `X-Request-Id` 可用于查询调用记录。异步接口返回 `data.task_id` 后，通过 `GET /api/v1/tasks/{task_id}` 查询状态和结果；仅允许任务创建者访问，轮换密钥后仍可查询。\n".$responseExamples."\n## 幂等与错误处理\n\n计费提交支持 `Idempotency-Key`。同一用户、接口和键的相同请求返回原回执，不重复扣款；参数不同则返回 409。提交超时或受理状态未知时，先查看调用记录，保持原幂等键，避免另建操作重复提交。\n\n鉴权失败返回 401，未开通或权限不足返回 403，余额或额度不足返回 402，限流返回 429。遇到 5xx，请保留请求 ID，并确认已有任务状态。\n";
    }
    private static function block(string $code, string $message, string $field = ''): array { return compact('code', 'field', 'message'); }
    private static function unique(array $issues): array { $out = []; foreach ($issues as $issue) $out[OpenStore::encode($issue)] = $issue; return array_values($out); }
}
