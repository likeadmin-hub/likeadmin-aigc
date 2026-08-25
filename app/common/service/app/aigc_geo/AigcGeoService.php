<?php

namespace app\common\service\app\aigc_geo;

use app\common\model\app\aigc_geo\AigcGeoArticle;
use app\common\model\app\aigc_geo\AigcGeoConfig;
use app\common\model\app\aigc_geo\AigcGeoDiagnosis;
use app\common\model\app\aigc_geo\AigcGeoKeyword;
use app\common\model\app\aigc_geo\AigcGeoTask;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\power\MarketGeoAppRuntimeService;
use Exception;

class AigcGeoService
{
    public const APP_CODE = 'aigc_geo';
    public const UPSTREAM_APP_CODE = 'aigc_geo';
    public const API_CODES = ['question_generate', 'content_generate', 'brand_diagnosis', 'content_publish'];

    public static function config(int $tenantId): array
    {
        $row = AigcGeoConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = array_merge(self::defaults(), $row->isEmpty() ? [] : $row->toArray());
        $data['profile_json'] = is_array($data['profile_json'] ?? null) ? $data['profile_json'] : [];
        $data['settings_json'] = is_array($data['settings_json'] ?? null) ? $data['settings_json'] : [];
        $data['market'] = self::marketOptions($tenantId);
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): array
    {
        $current = self::config($tenantId);
        $data = [
            'tenant_id' => $tenantId,
            'status' => array_key_exists('status', $params) ? ((int)$params['status'] ? 1 : 0) : (int)($current['status'] ?? 1),
            'brand_name' => self::text($params['brand_name'] ?? $params['name'] ?? $current['brand_name'] ?? '', 120),
            'website' => self::text($params['website'] ?? $current['website'] ?? '', 500),
            'industry' => self::text($params['industry'] ?? $current['industry'] ?? '', 120),
            'company_intro' => trim((string)($params['company_intro'] ?? $params['intro'] ?? $current['company_intro'] ?? '')),
            'profile_json' => is_array($params['profile_json'] ?? null) ? $params['profile_json'] : ($current['profile_json'] ?? []),
            'settings_json' => is_array($params['settings_json'] ?? null) ? $params['settings_json'] : ($current['settings_json'] ?? []),
            'update_time' => time(),
        ];
        $row = AigcGeoConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcGeoConfig::create($data);
        } else {
            $row->save($data);
        }
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        return self::config($tenantId);
    }

    public static function summary(int $tenantId, int $userId = 0): array
    {
        $keywordQuery = AigcGeoKeyword::where(['tenant_id' => $tenantId, 'delete_time' => 0]);
        $articleQuery = AigcGeoArticle::where(['tenant_id' => $tenantId, 'delete_time' => 0]);
        $diagnosisQuery = AigcGeoDiagnosis::where('tenant_id', $tenantId);
        $taskQuery = AigcGeoTask::where('tenant_id', $tenantId);
        if ($userId > 0) {
            $keywordQuery->where('user_id', $userId);
            $articleQuery->where('user_id', $userId);
            $diagnosisQuery->where('user_id', $userId);
            $taskQuery->where('user_id', $userId);
        }
        $config = self::config($tenantId);
        $mentioned = (int)(clone $diagnosisQuery)->where('mentioned', 1)->count();
        $diagnosisTotal = (int)(clone $diagnosisQuery)->count();
        return [
            'config' => $config,
            'stats' => [
                'keyword_total' => (int)(clone $keywordQuery)->count(),
                'article_total' => (int)(clone $articleQuery)->count(),
                'published_article_total' => (int)(clone $articleQuery)->where('status', 'published')->count(),
                'diagnosis_total' => $diagnosisTotal,
                'mentioned_total' => $mentioned,
                'mention_rate' => $diagnosisTotal > 0 ? round($mentioned / $diagnosisTotal * 100, 2) : 0,
                'task_total' => (int)(clone $taskQuery)->count(),
            ],
            'market' => $config['market'],
            'workflow' => [
                ['key' => 'brand', 'title' => '完善信息', 'path' => '/app/aigc_geo/console/brand'],
                ['key' => 'keywords', 'title' => '问题生成', 'path' => '/app/aigc_geo/console/keywords'],
                ['key' => 'articles', 'title' => '内容生产', 'path' => '/app/aigc_geo/console/creation'],
                ['key' => 'publish', 'title' => '分发发布', 'path' => '/app/aigc_geo/console/site-articles'],
                ['key' => 'diagnosis', 'title' => '品牌检测', 'path' => '/app/aigc_geo/console/diagnosis'],
            ],
        ];
    }

    public static function keywordLists(int $tenantId, array $params = [], int $userId = 0): array
    {
        $query = AigcGeoKeyword::where(['tenant_id' => $tenantId, 'delete_time' => 0])->order('id', 'desc');
        self::scopeUser($query, $userId);
        $keyword = trim((string)($params['keyword'] ?? $params['question'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('question', '%' . $keyword . '%');
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        return self::page($query, $params);
    }

    public static function saveKeyword(int $tenantId, array $params, int $userId = 0): array
    {
        $id = (int)($params['id'] ?? 0);
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId ?: (int)($params['user_id'] ?? 0),
            'question' => self::text($params['question'] ?? '', 500),
            'intent' => self::text($params['intent'] ?? '品牌认知', 80),
            'platform' => self::text($params['platform'] ?? '全平台', 80),
            'status' => self::text($params['status'] ?? 'draft', 30),
            'source' => self::text($params['source'] ?? 'manual', 30),
            'platforms_json' => is_array($params['platforms_json'] ?? null) ? $params['platforms_json'] : [],
            'metadata_json' => is_array($params['metadata_json'] ?? null) ? $params['metadata_json'] : [],
            'update_time' => time(),
        ];
        if ($data['question'] === '') {
            throw new Exception('请输入问题内容');
        }
        if ($id > 0) {
            $row = AigcGeoKeyword::where(['tenant_id' => $tenantId, 'id' => $id, 'delete_time' => 0])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('问题不存在');
            }
            $row->save($data);
            return $row->toArray();
        }
        $data['create_time'] = time();
        return AigcGeoKeyword::create($data)->toArray();
    }

    public static function generateKeywords(int $tenantId, int $userId, array $params = []): array
    {
        $config = self::config($tenantId);
        $brand = self::text($params['brand'] ?? $config['brand_name'] ?? '企业品牌', 120);
        $topic = self::text($params['topic'] ?? $config['industry'] ?? '行业解决方案', 120);
        $platforms = is_array($params['platforms'] ?? null) ? array_values($params['platforms']) : ['DeepSeek', '豆包', 'Kimi'];
        $templates = [
            $brand . '适合什么类型的企业使用？',
            $brand . '和传统' . $topic . '方案有什么区别？',
            '如何选择可靠的' . $topic . '服务商？',
            'AI搜索会如何评价' . $brand . '？',
            $brand . '有哪些真实案例和实施建议？',
        ];
        $rows = [];
        foreach ($templates as $index => $question) {
            $rows[] = AigcGeoKeyword::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'question' => $question,
                'intent' => ['品牌认知', '产品选型', '需求教育', '风险处理', '转化决策'][$index] ?? '品牌认知',
                'platform' => $platforms[$index % max(1, count($platforms))] ?? '全平台',
                'status' => 'ready',
                'source' => 'generated',
                'platforms_json' => $platforms,
                'metadata_json' => ['topic' => $topic],
                'delete_time' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ])->toArray();
        }
        $task = self::createTask($tenantId, $userId, 'question_generate', ['topic' => $topic], ['count' => count($rows)]);
        return ['task_id' => (int)$task['id'], 'lists' => $rows, 'count' => count($rows)];
    }

    public static function articleLists(int $tenantId, array $params = [], int $userId = 0): array
    {
        $query = AigcGeoArticle::where(['tenant_id' => $tenantId, 'delete_time' => 0])->order('id', 'desc');
        self::scopeUser($query, $userId);
        $keyword = trim((string)($params['keyword'] ?? $params['title'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword) {
                $query->whereLike('title', '%' . $keyword . '%')->whereOrLike('question', '%' . $keyword . '%');
            });
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        return self::page($query, $params);
    }

    public static function articleDetail(int $tenantId, int $id, int $userId = 0): array
    {
        $query = AigcGeoArticle::where(['tenant_id' => $tenantId, 'id' => $id, 'delete_time' => 0]);
        self::scopeUser($query, $userId);
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('文章不存在');
        }
        return $row->toArray();
    }

    public static function saveArticle(int $tenantId, array $params, int $userId = 0): array
    {
        $id = (int)($params['id'] ?? 0);
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId ?: (int)($params['user_id'] ?? 0),
            'keyword_id' => (int)($params['keyword_id'] ?? 0),
            'title' => self::text($params['title'] ?? '', 255),
            'question' => self::text($params['question'] ?? '', 500),
            'content' => trim((string)($params['content'] ?? '')),
            'status' => self::text($params['status'] ?? 'draft', 30),
            'score' => round(max(0, min(100, (float)($params['score'] ?? 0))), 2),
            'metadata_json' => is_array($params['metadata_json'] ?? null) ? $params['metadata_json'] : [],
            'update_time' => time(),
        ];
        if ($data['title'] === '') {
            throw new Exception('请输入文章标题');
        }
        if ($id > 0) {
            $row = AigcGeoArticle::where(['tenant_id' => $tenantId, 'id' => $id, 'delete_time' => 0])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('文章不存在');
            }
            $row->save($data);
            return $row->toArray();
        }
        $data['create_time'] = time();
        $data['delete_time'] = 0;
        return AigcGeoArticle::create($data)->toArray();
    }

    public static function generateArticle(int $tenantId, int $userId, array $params = []): array
    {
        $config = self::config($tenantId);
        $question = self::text($params['question'] ?? '', 500);
        if ($question === '' && (int)($params['keyword_id'] ?? 0) > 0) {
            $question = (string)(AigcGeoKeyword::where(['tenant_id' => $tenantId, 'id' => (int)$params['keyword_id'], 'delete_time' => 0])->value('question') ?? '');
        }
        if ($question === '') {
            throw new Exception('请选择问题或输入文章主题');
        }
        $brand = self::text($config['brand_name'] ?? '企业品牌', 120);
        $title = self::text($params['title'] ?? ($question . '｜' . $brand . '实践指南'), 255);
        $content = "## {$title}\n\n{$brand}围绕“{$question}”提供清晰、可验证的解决方案。本文结合适用场景、选择标准和实施建议，帮助读者快速完成判断。\n\n### 适用场景\n结合企业规模、目标客户和实际业务流程，优先覆盖真实高频问题。\n\n### 选择建议\n关注服务能力、交付案例、数据依据和持续优化机制，避免只比较单一价格。\n\n### 行动建议\n建议先完成一次小范围验证，再根据 AI 搜索答案中的引用和品牌出现情况持续迭代。";
        $article = self::saveArticle($tenantId, [
            'user_id' => $userId,
            'keyword_id' => (int)($params['keyword_id'] ?? 0),
            'title' => $title,
            'question' => $question,
            'content' => $content,
            'status' => 'draft',
            'score' => 90,
            'metadata_json' => ['generated' => true, 'market_required' => true],
        ], $userId);
        $task = self::createTask($tenantId, $userId, 'content_generate', ['question' => $question], ['article_id' => (int)$article['id']]);
        return ['task_id' => (int)$task['id'], 'article' => $article];
    }

    public static function publishArticle(int $tenantId, int $id, array $params = [], int $userId = 0): array
    {
        $row = AigcGeoArticle::where(['tenant_id' => $tenantId, 'id' => $id, 'delete_time' => 0])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('文章不存在');
        }
        if ($userId > 0 && (int)$row['user_id'] !== $userId) {
            throw new Exception('无权发布此文章');
        }
        $row->save(['status' => 'published', 'published_time' => time(), 'update_time' => time(), 'metadata_json' => array_merge((array)$row['metadata_json'], ['channel' => $params['channel'] ?? '官网'])]);
        $task = self::createTask($tenantId, $userId, 'content_publish', ['article_id' => $id, 'channel' => $params['channel'] ?? '官网'], ['status' => 'submitted']);
        return ['article' => $row->toArray(), 'task_id' => (int)$task['id'], 'market' => self::marketOptions($tenantId)];
    }

    public static function diagnosisLists(int $tenantId, array $params = [], int $userId = 0): array
    {
        $query = AigcGeoDiagnosis::where('tenant_id', $tenantId)->order('id', 'desc');
        self::scopeUser($query, $userId);
        if (($platform = trim((string)($params['platform'] ?? ''))) !== '') {
            $query->where('platform', $platform);
        }
        return self::page($query, $params);
    }

    public static function runDiagnosis(int $tenantId, int $userId, array $params = []): array
    {
        $config = self::config($tenantId);
        $question = self::text($params['question'] ?? 'AI搜索会如何评价' . ($config['brand_name'] ?? '企业品牌') . '？', 500);
        $platforms = is_array($params['platforms'] ?? null) ? array_values($params['platforms']) : ['DeepSeek', '豆包', 'Kimi'];
        $rows = [];
        foreach ($platforms as $index => $platform) {
            $rows[] = AigcGeoDiagnosis::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'question' => $question,
                'platform' => self::text($platform, 80),
                'rank_value' => $index === 0 ? 2 : ($index + 3),
                'mentioned' => $index < 2 ? 1 : 0,
                'answer' => '检测任务已创建，接入算力超市通道后将回填真实 AI 答案。',
                'sources_json' => [],
                'status' => 'pending_provider',
                'create_time' => time(),
                'update_time' => time(),
            ])->toArray();
        }
        $task = self::createTask($tenantId, $userId, 'brand_diagnosis', ['question' => $question, 'platforms' => $platforms], ['count' => count($rows)]);
        return ['task_id' => (int)$task['id'], 'lists' => $rows, 'market' => self::marketOptions($tenantId)];
    }

    public static function taskLists(int $tenantId, array $params = [], int $userId = 0): array
    {
        $query = AigcGeoTask::where('tenant_id', $tenantId)->order('id', 'desc');
        self::scopeUser($query, $userId);
        if (($type = trim((string)($params['task_type'] ?? ''))) !== '') {
            $query->where('task_type', $type);
        }
        if (($status = trim((string)($params['status'] ?? ''))) !== '') {
            $query->where('status', $status);
        }
        return self::page($query, $params);
    }

    public static function taskDetail(int $tenantId, int $id, int $userId = 0): array
    {
        $query = AigcGeoTask::where(['tenant_id' => $tenantId, 'id' => $id]);
        self::scopeUser($query, $userId);
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return $row->toArray();
    }

    public static function marketOptions(int $tenantId = 0): array
    {
        $options = MarketGeoAppRuntimeService::options($tenantId);
        $items = [];
        foreach ($options as $option) {
            $items[] = [
                'product_id' => (int)($option['market_product_id'] ?? 0),
                'sku_id' => (int)($option['market_sku_id'] ?? 0),
                'api_code' => (string)($option['api_code'] ?? ''),
                'name' => (string)($option['name'] ?? self::UPSTREAM_APP_CODE),
                'sku_name' => (string)($option['sku_key'] ?? ''),
                'unit' => (string)($option['usage_unit'] ?? 'per_call'),
                'tenant_price' => (float)($option['tenant_unit_price'] ?? 0),
            ];
        }
        return [
            'ready' => !empty($items),
            'configured' => !empty($items),
            'upstream_app_code' => self::UPSTREAM_APP_CODE,
            'api_codes' => self::API_CODES,
            'items' => $items,
            'message' => $items ? '算力超市通道已配置' : '尚未配置 GEO 算力超市应用 API，请先上架并配置 SKU。',
        ];
    }

    private static function defaults(): array
    {
        return [
            'status' => 1,
            'brand_name' => '',
            'website' => '',
            'industry' => '',
            'company_intro' => '',
            'profile_json' => ['service' => '', 'city' => '', 'customer' => '', 'competitor' => '', 'docs' => ''],
            'settings_json' => ['platforms' => ['豆包', 'DeepSeek', '千问', 'Kimi'], 'default_channel' => '官网'],
        ];
    }

    private static function createTask(int $tenantId, int $userId, string $type, array $payload, array $result): AigcGeoTask
    {
        return AigcGeoTask::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_type' => $type,
            'status' => 'pending_provider',
            'progress' => 0,
            'payload_json' => $payload,
            'result_json' => $result,
            'error' => '',
            'finish_time' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private static function page($query, array $params): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        return ['lists' => $rows, 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    private static function scopeUser($query, int $userId): void
    {
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
    }

    private static function text(mixed $value, int $length): string
    {
        return mb_substr(trim((string)$value), 0, $length);
    }
}
