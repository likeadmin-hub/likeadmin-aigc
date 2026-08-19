<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\agent\billing\CanvasAgentEntitlementService;
use app\common\service\app\aigc_canvas\agent\enrichment\VisualUnderstandingService;
use app\common\service\app\aigc_canvas\agent\generation\CanvasGenerationTaskCenterService;
use app\common\service\app\aigc_canvas\agent\memory\ProjectMemoryService;
use app\common\service\app\aigc_canvas\agent\model\CanvasGenerationPricingService;
use Exception;

final class CanvasAgentToolExecutor
{
    public static function assertExecutable(int $tenantId, string $toolCode, array $input): void
    {
        CanvasAgentToolRegistryService::validateInput($tenantId, $toolCode, $input);
    }

    public static function execute(int $tenantId, int $userId, string $toolCode, array $input, ?callable $onTextEvent = null): array
    {
        self::assertExecutable($tenantId, $toolCode, $input);

        $streamed = false;
        $output = match ($toolCode) {
            'generate_text' => self::executeText($tenantId, $userId, $input, $onTextEvent, $streamed),
            'generate_image' => self::executeGenerationTool($tenantId, $userId, $toolCode, $input),
            'generate_video' => self::executeGenerationTool($tenantId, $userId, $toolCode, $input),
            'generate_music' => self::executeGenerationTool($tenantId, $userId, $toolCode, $input),
            'web_fetch' => self::executeWebFetch($input),
            'url_to_design_brief' => self::executeUrlToDesignBrief($input),
            'brand_research' => self::executeBrandResearch($input),
            'search_web_info' => self::executeWebSearch($input),
            'search_image' => self::executeImageSearch($input),
            'asset_analyze' => self::executeAssetAnalyze($tenantId, $userId, $input),
            'canvas_select' => self::executeCanvasSelect($input),
            'canvas_read' => self::executeCanvasRead($input),
            'canvas_patch' => self::executeCanvasPatch($input),
            default => throw new Exception('Agent 工具暂不支持直接执行：' . $toolCode),
        };

        return [
            'output' => $output,
            'streamed' => $streamed,
        ];
    }

    private static function executeText(int $tenantId, int $userId, array $input, ?callable $onTextEvent, bool &$streamed): array
    {
        if (is_callable($onTextEvent)) {
            $streamed = true;
            return AigcCanvasService::streamText($tenantId, $userId, $input, $onTextEvent);
        }
        return AigcCanvasService::generateText($tenantId, $userId, $input);
    }

    private static function executeGenerationTool(int $tenantId, int $userId, string $toolCode, array $input): array
    {
        return CanvasGenerationTaskCenterService::create($tenantId, $userId, $input + [
            'type' => str_replace('generate_', '', $toolCode),
        ]);

        $estimate = CanvasGenerationPricingService::estimate($tenantId, $toolCode, $input);
        $entitlement = CanvasAgentEntitlementService::precheck($tenantId, $userId, $estimate);
        if (!empty($estimate['available']) && empty($entitlement['can_submit'])) {
            $message = trim((string)($entitlement['message'] ?? $estimate['message'] ?? ''));
            throw new Exception($message !== '' ? $message : '积分不足，无法提交生成任务');
        }

        return match ($toolCode) {
            'generate_image' => AigcCanvasService::generateImage($tenantId, $userId, $input),
            'generate_video' => AigcCanvasService::generateVideo($tenantId, $userId, $input),
            'generate_music' => AigcCanvasService::generateMusic($tenantId, $userId, $input),
            default => throw new Exception('Unsupported generation tool: ' . $toolCode),
        };
    }

    private static function executeWebFetch(array $input): array
    {
        $url = trim((string)($input['url'] ?? ''));
        if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
            throw new Exception('web_fetch 只支持 http/https URL');
        }
        $maxLength = max(500, min(8000, (int)($input['max_length'] ?? 4000)));
        $context = stream_context_create([
            'http' => [
                'timeout' => 8,
                'user_agent' => 'LikeAdmin-AIGC-Canvas-Agent/1.0',
                'header' => "Accept: text/html,text/plain;q=0.9,*/*;q=0.5\r\n",
            ],
        ]);
        $content = @file_get_contents($url, false, $context);
        if ($content === false || $content === '') {
            throw new Exception('URL 内容读取失败');
        }
        $title = self::extractTitle($content);
        $text = self::htmlToText($content);
        $excerpt = mb_substr($text, 0, $maxLength, 'UTF-8');

        return [
            'status' => 'success',
            'url' => $url,
            'title' => $title,
            'content' => $excerpt,
            'summary' => mb_substr($text, 0, min(800, $maxLength), 'UTF-8'),
            'sources' => [[
                'url' => $url,
                'title' => $title,
            ]],
        ];
    }

    private static function executeUrlToDesignBrief(array $input): array
    {
        $fetched = self::executeWebFetch($input);
        $title = (string)($fetched['title'] ?? '');
        $summary = trim((string)($fetched['summary'] ?? ''));
        return [
            'status' => 'success',
            'url' => (string)($fetched['url'] ?? ''),
            'title' => $title,
            'brief' => [
                'brand_name' => $title,
                'visual_keywords' => self::inferVisualKeywords($summary),
                'selling_points' => self::sentenceList($summary, 5),
                'design_direction' => '根据页面标题、正文卖点和现有品牌信息生成贴合业务语境的视觉方案。',
            ],
            'sources' => (array)($fetched['sources'] ?? []),
        ];
    }

    private static function executeBrandResearch(array $input): array
    {
        $url = trim((string)($input['url'] ?? ''));
        if ($url !== '') {
            $brief = self::executeUrlToDesignBrief($input);
            return array_merge($brief, [
                'brand_query' => (string)($input['query'] ?? $brief['title'] ?? ''),
            ]);
        }
        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            throw new Exception('brand_research 需要品牌名称或官网 URL');
        }
        $search = self::executeWebSearch(['query' => $query, 'limit' => 5]);
        return [
            'status' => (string)($search['status'] ?? 'success'),
            'brand_query' => $query,
            'summary' => self::brandSummaryFromSearch($query, (array)($search['items'] ?? [])),
            'sources' => (array)($search['sources'] ?? []),
            'items' => (array)($search['items'] ?? []),
            'message' => (string)($search['message'] ?? ''),
        ];
    }

    private static function executeWebSearch(array $input): array
    {
        $query = trim((string)($input['query'] ?? $input['keyword'] ?? ''));
        $url = trim((string)($input['url'] ?? ''));
        if ($url !== '') {
            return self::executeWebFetch(['url' => $url] + $input);
        }
        if ($query === '') {
            throw new Exception('search_web_info 需要搜索关键词');
        }
        $limit = max(1, min(10, (int)($input['limit'] ?? 5)));
        $searchUrl = 'https://www.bing.com/search?q=' . rawurlencode($query);
        try {
            $html = self::httpGet($searchUrl, 12);
            $items = self::parseWebSearchResults($html, $limit);
            return [
                'status' => $items === [] ? 'empty' : 'success',
                'query' => $query,
                'items' => $items,
                'sources' => array_map(static fn(array $item): array => [
                    'url' => (string)($item['url'] ?? ''),
                    'title' => (string)($item['title'] ?? ''),
                ], $items),
                'message' => $items === [] ? '未搜索到可用结果' : '',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unavailable',
                'query' => $query,
                'items' => [],
                'sources' => [],
                'message' => '网页搜索暂不可用：' . mb_substr($e->getMessage(), 0, 200, 'UTF-8'),
            ];
        }
    }

    private static function executeImageSearch(array $input): array
    {
        $query = trim((string)($input['query'] ?? $input['keyword'] ?? ''));
        if ($query === '') {
            throw new Exception('search_image 需要搜索关键词');
        }
        $limit = max(1, min(12, (int)($input['limit'] ?? 6)));
        $searchUrl = 'https://www.bing.com/images/search?q=' . rawurlencode($query);
        try {
            $html = self::httpGet($searchUrl, 12);
            $items = self::parseImageSearchResults($html, $limit);
            return [
                'status' => $items === [] ? 'empty' : 'success',
                'query' => $query,
                'items' => $items,
                'sources' => array_map(static fn(array $item): array => [
                    'url' => (string)($item['source_url'] ?? $item['url'] ?? ''),
                    'title' => (string)($item['title'] ?? ''),
                ], $items),
                'message' => $items === [] ? '未搜索到可用图片结果' : '',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unavailable',
                'query' => $query,
                'items' => [],
                'sources' => [],
                'message' => '图片搜索暂不可用：' . mb_substr($e->getMessage(), 0, 200, 'UTF-8'),
            ];
        }
    }

    private static function httpGet(string $url, int $timeout = 10): string
    {
        if (function_exists('curl_init')) {
            try {
                return self::curlGet($url, $timeout, true);
            } catch (Exception $e) {
                if (!str_contains(strtolower($e->getMessage()), 'ssl certificate')) {
                    throw $e;
                }
                return self::curlGet($url, $timeout, false);
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'follow_location' => 1,
                'user_agent' => 'LikeAdmin-AIGC-Canvas-Agent/1.0',
                'header' => "Accept: text/html,text/plain;q=0.9,*/*;q=0.5\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            throw new Exception('网络请求失败');
        }
        return $body;
    }

    private static function curlGet(string $url, int $timeout, bool $sslVerify): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'LikeAdmin-AIGC-Canvas-Agent/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html,text/plain;q=0.9,*/*;q=0.5'],
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) {
            throw new Exception($error ?: '网络请求失败');
        }
        if ($http >= 400) {
            throw new Exception('HTTP ' . $http);
        }
        if (!is_string($body) || $body === '') {
            throw new Exception('响应为空');
        }
        return $body;
    }

    private static function parseWebSearchResults(string $html, int $limit): array
    {
        $items = [];
        if (preg_match_all('/<li[^>]+class="[^"]*b_algo[^"]*"[^>]*>.*?<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>.*?(?:<p[^>]*>(.*?)<\/p>)?/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = self::decodeSearchUrl((string)$match[1]);
                if (!self::validSourceUrl($url)) {
                    continue;
                }
                $items[] = [
                    'title' => self::cleanText((string)$match[2]),
                    'url' => $url,
                    'snippet' => self::cleanText((string)($match[3] ?? '')),
                ];
                if (count($items) >= $limit) {
                    break;
                }
            }
        }
        if ($items === [] && preg_match_all('/<a[^>]+href="(https?:\/\/[^"]+)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = self::decodeSearchUrl((string)$match[1]);
                if (!self::validSourceUrl($url)) {
                    continue;
                }
                $items[] = ['title' => self::cleanText((string)$match[2]), 'url' => $url, 'snippet' => ''];
                if (count($items) >= $limit) {
                    break;
                }
            }
        }
        return $items;
    }

    private static function parseImageSearchResults(string $html, int $limit): array
    {
        $items = [];
        if (preg_match_all('/murl&quot;:&quot;(.*?)&quot;.*?purl&quot;:&quot;(.*?)&quot;.*?t&quot;:&quot;(.*?)&quot;/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $imageUrl = html_entity_decode(stripslashes((string)$match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!self::validSourceUrl($imageUrl)) {
                    continue;
                }
                $items[] = [
                    'title' => self::cleanText((string)$match[3]),
                    'url' => $imageUrl,
                    'image_url' => $imageUrl,
                    'source_url' => self::decodeSearchUrl(html_entity_decode(stripslashes((string)$match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                ];
                if (count($items) >= $limit) {
                    break;
                }
            }
        }
        if ($items === [] && preg_match_all('/"murl":"(.*?)"/is', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $imageUrl = stripcslashes((string)$url);
                if (!self::validSourceUrl($imageUrl)) {
                    continue;
                }
                $items[] = ['title' => '', 'url' => $imageUrl, 'image_url' => $imageUrl, 'source_url' => ''];
                if (count($items) >= $limit) {
                    break;
                }
            }
        }
        return $items;
    }

    private static function decodeSearchUrl(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);
        if (isset($parts['host']) && str_contains((string)$parts['host'], 'bing.com') && !empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
            if (!empty($query['u']) && is_string($query['u'])) {
                $decoded = base64_decode(str_starts_with($query['u'], 'a1') ? substr($query['u'], 2) : $query['u'], true);
                if (is_string($decoded) && preg_match('/^https?:\/\//i', $decoded)) {
                    return $decoded;
                }
            }
        }
        return $url;
    }

    private static function validSourceUrl(string $url): bool
    {
        if (!preg_match('/^https?:\/\//i', $url)) {
            return false;
        }
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        return $host !== '' && !str_contains($host, 'bing.com') && !str_contains($host, 'microsoft.com');
    }

    private static function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private static function brandSummaryFromSearch(string $query, array $items): string
    {
        if ($items === []) {
            return '未搜索到可用资料，建议提供品牌官网 URL 以提取更准确的品牌信息。';
        }
        $snippets = array_values(array_filter(array_map(static fn(array $item): string => trim((string)($item['snippet'] ?? '')), $items)));
        $summary = implode(' ', array_slice($snippets, 0, 3));
        return $summary !== '' ? mb_substr($summary, 0, 800, 'UTF-8') : '已找到 ' . count($items) . ' 条与“' . $query . '”相关的公开资料。';
    }

    private static function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    private static function htmlToText(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private static function inferVisualKeywords(string $text): array
    {
        $keywords = [];
        foreach (['科技', '高端', '简约', '年轻', '自然', '健康', '专业', '国风', '潮流', '温暖'] as $keyword) {
            if (str_contains($text, $keyword)) {
                $keywords[] = $keyword;
            }
        }
        return $keywords ?: ['品牌识别', '产品卖点', '清晰层级'];
    }

    private static function sentenceList(string $text, int $limit): array
    {
        $parts = preg_split('/[。！？!?；;\n]+/u', $text) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts)));
        return array_slice($parts, 0, $limit);
    }

    private static function executeAssetAnalyze(int $tenantId, int $userId, array $input): array
    {
        $assets = array_values(array_filter(array_merge(
            (array)($input['assets'] ?? []),
            (array)($input['references'] ?? []),
            (array)($input['uploaded_references'] ?? [])
        ), static fn($item): bool => is_array($item) || is_string($item)));
        $items = [];
        foreach ($assets as $index => $asset) {
            $row = is_array($asset) ? $asset : ['url' => (string)$asset];
            $url = trim((string)($row['url'] ?? $row['image_url'] ?? $row['file_url'] ?? ''));
            $mime = trim((string)($row['mime'] ?? $row['mime_type'] ?? ''));
            $type = self::assetType($url, $mime);
            $items[] = [
                'index' => $index,
                'type' => $type,
                'url' => $url,
                'width' => (int)($row['width'] ?? 0),
                'height' => (int)($row['height'] ?? 0),
                'summary' => self::assetSummary($type, $row),
            ];
        }
        $result = ['status' => 'success', 'asset_count' => count($items), 'items' => $items];
        if (empty($input['requires_visual_understanding'])) {
            return $result;
        }
        if (!empty($input['precomputed_asset_insights']) && is_array($input['precomputed_asset_insights'])) {
            return $result + [
                'visual_understanding' => ['status' => 'success', 'reused' => true, 'insights' => array_values($input['precomputed_asset_insights'])],
                'asset_insights' => array_values($input['precomputed_asset_insights']),
            ];
        }
        $understanding = VisualUnderstandingService::analyze($tenantId, $userId, $assets, (string)($input['request'] ?? ''), [
            'model_selection' => $input['vision_model_selection'] ?? '',
            'request_timeout_seconds' => (int)($input['request_timeout_seconds'] ?? 60),
        ]);
        self::rememberAssetInsights($tenantId, $userId, (int)($input['project_id'] ?? 0), (array)($understanding['insights'] ?? []), $input);
        return $result + [
            'visual_understanding' => $understanding,
            'asset_insights' => (array)($understanding['insights'] ?? []),
        ];
    }

    private static function rememberAssetInsights(int $tenantId, int $userId, int $projectId, array $insights, array $input): void
    {
        if ($projectId <= 0 || (string)($input['reference_scope'] ?? '') !== 'canvas') {
            return;
        }
        foreach ($insights as $insight) {
            if (!is_array($insight) || (float)($insight['confidence'] ?? 0) <= 0) {
                continue;
            }
            $assetKey = trim((string)($insight['asset_key'] ?? ''));
            if ($assetKey === '') {
                continue;
            }
            ProjectMemoryService::remember($tenantId, $userId, $projectId, 'reference_asset', 'reference_asset:' . $assetKey, [
                'summary' => (string)($insight['subject_type'] ?? 'reference image'),
                'subject_type' => (string)($insight['subject_type'] ?? ''),
                'visible_facts' => array_values((array)($insight['visible_facts'] ?? [])),
                'visual_style' => array_values((array)($insight['visual_style'] ?? [])),
                'composition' => (array)($insight['composition'] ?? []),
                'ocr_text' => array_values((array)($insight['ocr_text'] ?? [])),
                'confidence' => (float)($insight['confidence'] ?? 0),
            ], [
                'source_type' => 'visual_understanding',
                'scope' => 'canvas',
                'request_id' => (string)($input['request_id'] ?? ''),
            ]);
        }
    }

    private static function executeCanvasSelect(array $input): array
    {
        $selected = (array)($input['selected_elements'] ?? $input['selection'] ?? []);
        return [
            'status' => 'success',
            'selected_count' => count($selected),
            'selected_elements' => array_values(array_filter($selected, static fn($item): bool => is_array($item))),
        ];
    }

    private static function executeCanvasRead(array $input): array
    {
        $snapshot = is_array($input['canvas_snapshot'] ?? null) ? $input['canvas_snapshot'] : [];
        $nodes = is_array($snapshot['nodes'] ?? null) ? $snapshot['nodes'] : (array)($input['nodes'] ?? []);
        return [
            'status' => 'success',
            'node_count' => count($nodes),
            'canvas_snapshot' => $snapshot !== [] ? $snapshot : [
                'nodes' => array_values(array_filter($nodes, static fn($item): bool => is_array($item))),
                'viewport' => is_array($input['viewport'] ?? null) ? $input['viewport'] : [],
            ],
        ];
    }

    private static function executeCanvasPatch(array $input): array
    {
        $patch = is_array($input['patch_json'] ?? null) ? $input['patch_json'] : [];
        $actions = is_array($input['actions'] ?? null) ? $input['actions'] : (array)($patch['actions'] ?? []);
        return [
            'status' => 'success',
            'patch_json' => $patch !== [] ? $patch : ['actions' => $actions],
            'actions' => array_values(array_filter($actions, static fn($item): bool => is_array($item))),
            'requires_confirmation' => !empty($input['requires_confirmation']),
        ];
    }

    private static function assetType(string $url, string $mime): string
    {
        $value = strtolower($mime . ' ' . (string)parse_url($url, PHP_URL_PATH));
        if (str_contains($value, 'video') || preg_match('/\.(mp4|mov|webm|avi)$/', $value)) {
            return 'video';
        }
        if (str_contains($value, 'audio') || preg_match('/\.(mp3|wav|m4a|aac)$/', $value)) {
            return 'audio';
        }
        if (str_contains($value, 'image') || preg_match('/\.(jpg|jpeg|png|webp|gif)$/', $value)) {
            return 'image';
        }
        return 'file';
    }

    private static function assetSummary(string $type, array $asset): string
    {
        $name = trim((string)($asset['name'] ?? $asset['filename'] ?? ''));
        $size = trim(implode('x', array_filter([
            (string)($asset['width'] ?? ''),
            (string)($asset['height'] ?? ''),
        ], static fn(string $value): bool => $value !== '' && $value !== '0')));
        return implode(' / ', array_values(array_filter([$name, $type, $size])));
    }
}
