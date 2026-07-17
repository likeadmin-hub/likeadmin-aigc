<?php

namespace app\common\service\app\aigc_canvas\agent\planning;

use app\common\service\app\aigc_llm\AigcLlmService;
use Exception;

final class EcommerceDetailSectionPlanner
{
    private const FALLBACK_SECTION_COUNT = 5;
    private const AUTO_MIN_SECTIONS = 3;
    private const AUTO_MAX_SECTIONS = 12;
    private const DEFAULT_RATIO = '3:4';
    private const ALLOWED_RATIOS = ['1:1', '3:4', '2:3', '9:16'];

    public static function resolve(int $tenantId, int $userId, string $content, array $slots = [], array $referenceImages = []): array
    {
        $explicitSections = self::extractExplicitSections($content, $slots);
        $requestedCount = !empty($explicitSections) ? count($explicitSections) : self::extractRequestedCount($content);
        $lockedReason = !empty($explicitSections)
            ? 'explicit_numbered_sections'
            : ($requestedCount > 0 ? 'explicit_requested_count' : '');

        $llm = self::resolveWithLlm(
            $tenantId,
            $userId,
            $content,
            $slots,
            $referenceImages,
            $explicitSections,
            $requestedCount
        );

        if (!empty($explicitSections)) {
            $sections = self::applyPlannedRatios(
                $explicitSections,
                (array)($llm['detail_sections'] ?? []),
                (string)($slots['ratio'] ?? '')
            );
            $count = count($sections);
        } elseif ($requestedCount > 0) {
            $llmSections = self::normalizeSections((array)($llm['detail_sections'] ?? []));
            $sections = count($llmSections) === $requestedCount
                ? $llmSections
                : self::fallbackSections($requestedCount, $slots, $content);
            $count = $requestedCount;
        } else {
            $sections = self::normalizeSections((array)($llm['detail_sections'] ?? []));
            $count = (int)($llm['recommended_section_count'] ?? count($sections));
            $count = max(self::AUTO_MIN_SECTIONS, min(self::AUTO_MAX_SECTIONS, $count));
            if (count($sections) !== $count) {
                $count = self::FALLBACK_SECTION_COUNT;
                $sections = self::fallbackSections($count, $slots, $content);
            }
        }

        $analysis = self::normalizeAnalysis((array)($llm['design_analysis'] ?? $llm), $slots, $count, !empty($llm));
        return [
            'section_count' => $count,
            'recommended_section_count' => $count,
            'count_reason' => $lockedReason !== '' ? $lockedReason : (string)($llm['count_reason'] ?? 'llm_recommended'),
            'detail_sections' => $sections,
            'design_analysis' => $analysis,
            'analysis_source' => !empty($llm) ? 'llm' : 'fallback',
            'reference_image_count' => count(self::normalizeReferenceImages($referenceImages)),
        ];
    }

    public static function streamAnalysisReply(
        int $tenantId,
        int $userId,
        array $plan,
        array $referenceImages,
        ?callable $onDelta = null
    ): array {
        $count = (int)($plan['section_count'] ?? 0);
        $firstBatch = array_slice((array)($plan['detail_sections'] ?? []), 0, min(5, $count));
        $payload = [
            'design_analysis' => $plan['design_analysis'] ?? [],
            'total_count' => $count,
            'count_reason' => $plan['count_reason'] ?? '',
            'first_batch' => array_map(static fn(array $section): array => [
                'index' => (int)($section['section_index'] ?? 0),
                'title' => (string)($section['title'] ?? ''),
                'ratio' => (string)($section['ratio'] ?? self::DEFAULT_RATIO),
                'prompt_summary' => mb_substr((string)($section['image_prompt'] ?? ''), 0, 120, 'UTF-8'),
            ], $firstBatch),
        ];
        try {
            $result = AigcLlmService::streamText($tenantId, $userId, [
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'system_prompt' => implode("\n", [
                    '你是资深电商视觉设计总监，负责向用户解释已经完成的设计分析。',
                    '回复必须基于输入 JSON 中的事实，不得编造没有识别到的产品特征。',
                    '先说明对商品和素材的理解，再说明风格、配色、材质、灯光和构图方向。',
                    '说明规划总张数、原因和第一批将生成的页面名称。',
                    '明确提示当前尚未生成图片，需要用户确认后才生成第一批。',
                    '语气自然、专业、简洁，使用 Markdown；不要输出 JSON，不要写内部 Agent 名称。',
                ]),
                'max_tokens' => 1600,
                'source_app_code' => 'aigc_canvas',
                'source_type' => 'ecommerce_design_analysis_reply',
            ], static function (string $event, array $data) use ($onDelta): void {
                if ($event === 'delta' && is_callable($onDelta) && !empty($data['delta'])) {
                    $onDelta((string)$data['delta']);
                }
            });
            $reply = trim((string)($result['content'] ?? ''));
            if ($reply !== '') {
                return ['content' => $reply, 'streamed' => is_callable($onDelta)];
            }
        } catch (Exception) {
            // The validated structured analysis below remains usable when reply generation fails.
        }
        return ['content' => self::fallbackAnalysisReply($plan), 'streamed' => false];
    }

    public static function normalizeSections(array $sections): array
    {
        $result = [];
        $seenKeys = [];
        $seenPrompts = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $position = count($result) + 1;
            $title = trim((string)($section['title'] ?? $section['section_key'] ?? '')) ?: '详情区块' . $position;
            $prompt = self::sanitizeImagePrompt((string)($section['image_prompt'] ?? $section['prompt'] ?? ''));
            $markerCount = preg_match_all('/第\s*\d+\s*(?:张|页)/u', $prompt);
            if ($prompt === '' || strlen($prompt) > 3000 || $markerCount > 1 || isset($seenPrompts[md5($prompt)])) {
                $prompt = self::fallbackPrompt($title, [], '');
            }
            $seenPrompts[md5($prompt)] = true;
            $copy = $section['copy_content'] ?? [];
            if (is_string($copy)) {
                $copy = ['raw' => trim($copy)];
            }
            $sectionKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim((string)($section['section_key'] ?? ''))) ?? '';
            if ($sectionKey === '' || isset($seenKeys[$sectionKey])) {
                $sectionKey = 'section_' . str_pad((string)$position, 2, '0', STR_PAD_LEFT);
            }
            $seenKeys[$sectionKey] = true;
            $result[] = [
                'section_index' => $position,
                'section_key' => $sectionKey,
                'title' => mb_substr($title, 0, 80, 'UTF-8'),
                'copy_content' => is_array($copy) ? $copy : [],
                'image_prompt' => self::withSingleFrameConstraint($prompt),
                'ratio' => self::normalizeRatio((string)($section['ratio'] ?? '')),
            ];
        }
        return $result;
    }

    public static function extractRequestedCount(string $content): int
    {
        if (preg_match('/(?:生成|制作|设计|需要|规划|推荐|共|做)\s*(\d{1,3})\s*(?:张|页|个区块|个版块|幅)(?:详情图|详情页|图片|图)?/u', $content, $match)) {
            return max(1, (int)$match[1]);
        }
        return 0;
    }

    private static function extractExplicitSections(string $content, array $slots): array
    {
        if (!preg_match_all('/第\s*(\d{1,3})\s*(?:张|页)\s*[｜|:：-]?\s*([^\r\n]+)/u', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $sections = [];
        $total = count($matches[0]);
        for ($index = 0; $index < $total; $index++) {
            $start = (int)$matches[0][$index][1];
            $end = $index + 1 < $total ? (int)$matches[0][$index + 1][1] : strlen($content);
            $block = trim(substr($content, $start, $end - $start));
            $title = trim((string)$matches[2][$index][0]);
            $title = trim((string)preg_replace('/[（(].*$/u', '', $title));
            $prompt = self::extractImagePrompt($block);
            if ($prompt === '') {
                $prompt = self::fallbackPrompt($title, $slots, $content);
            }
            $sections[] = [
                'section_index' => $index + 1,
                'section_key' => 'section_' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT),
                'title' => $title !== '' ? $title : '详情区块' . ($index + 1),
                'copy_content' => self::extractCopyContent($block),
                'image_prompt' => $prompt,
                'ratio' => trim((string)($slots['ratio'] ?? '')),
            ];
        }
        return self::normalizeSections($sections);
    }

    private static function extractImagePrompt(string $block): string
    {
        if (!preg_match('/AI\s*生图提示词(?:（[^）]*）|\([^)]*\))?\s*[:：]?\s*(?:中文提示词\s*)?/ui', $block, $match, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $start = (int)$match[0][1] + strlen((string)$match[0][0]);
        $prompt = trim(substr($block, $start));
        if (preg_match('/\n\s*英文\s*Prompt\s*\n/ui', $prompt, $english, PREG_OFFSET_CAPTURE)) {
            $prompt = trim(substr($prompt, 0, (int)$english[0][1]));
        }
        return self::sanitizeImagePrompt($prompt);
    }

    private static function extractCopyContent(string $block): array
    {
        $copy = preg_replace('/^第\s*\d+\s*(?:张|页)\s*[｜|:：-]?\s*[^\r\n]+\R?/u', '', $block) ?? $block;
        $copy = preg_replace('/AI\s*生图提示词[\s\S]*$/ui', '', $copy) ?? $copy;
        $copy = trim($copy);
        $headline = '';
        if (preg_match('/(?:主标题|标题)\s*\R+\s*([^\r\n]+)/u', $copy, $match)) {
            $headline = trim((string)$match[1]);
        }
        $subheadline = '';
        if (preg_match('/副标题\s*\R+\s*([^\r\n]+)/u', $copy, $match)) {
            $subheadline = trim((string)$match[1]);
        }
        return array_filter([
            'headline' => $headline,
            'subheadline' => $subheadline,
            'raw' => mb_substr($copy, 0, 1600, 'UTF-8'),
        ], static fn($value): bool => $value !== '');
    }

    private static function resolveWithLlm(
        int $tenantId,
        int $userId,
        string $content,
        array $slots,
        array $referenceImages,
        array $explicitSections,
        int $requestedCount
    ): array {
        $references = self::normalizeReferenceImages($referenceImages);
        try {
            $response = AigcLlmService::generateText($tenantId, $userId, [
                'content' => json_encode([
                    'task' => 'analyze_and_plan_ecommerce_detail_page',
                    'user_request' => $content,
                    'known_slots' => $slots,
                    'locked_section_count' => !empty($explicitSections) ? count($explicitSections) : ($requestedCount ?: null),
                    'locked_sections' => array_map(static fn(array $section): array => [
                        'section_index' => $section['section_index'],
                        'section_key' => $section['section_key'],
                        'title' => $section['title'],
                        'image_prompt' => $section['image_prompt'],
                    ], $explicitSections),
                    'rules' => [
                        'Analyze the product, packaging, material and visual opportunities before planning.',
                        'If locked_sections exist, never change their count, order, titles or image prompts.',
                        'If only locked_section_count exists, return exactly that many distinct sections.',
                        'Without a locked count, recommend 3 to 12 sections and explain why.',
                        'Each section has exactly one standalone image prompt for one complete image.',
                        'The ecommerce design width is fixed at 750px. Choose each section height independently from its information density.',
                        'For every section choose exactly one ratio from 1:1, 3:4, 2:3, or 9:16. Use 1:1 for concise single-focus visuals, 3:4 for standard product storytelling, 2:3 for richer vertical storytelling, and 9:16 for copy-heavy processes, specifications, comparisons, or multi-part information.',
                        'Do not force all sections to use the same ratio unless the user explicitly requests one global ratio.',
                        'Reference image URLs are context only and must never appear in image_prompt.',
                        'Do not claim visual observations unless they are visible in supplied reference images.',
                    ],
                    'output_schema' => [
                        'product_summary' => 'string',
                        'detected_product_features' => ['string'],
                        'target_platform' => 'string',
                        'target_audience' => 'string',
                        'design_direction' => [
                            'style_keywords' => ['string'],
                            'palette' => ['string'],
                            'materials' => ['string'],
                            'lighting' => 'string',
                            'composition' => 'string',
                            'typography' => 'string',
                        ],
                        'recommended_section_count' => 'integer',
                        'count_reason' => 'string',
                        'detail_sections' => [[
                            'section_key' => 'string',
                            'title' => 'string',
                            'copy_content' => ['headline' => 'string', 'subheadline' => 'string'],
                            'image_prompt' => 'string',
                            'ratio' => 'string',
                        ]],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'system_prompt' => 'You are a senior ecommerce visual design strategist. Return one compact JSON object only.',
                'reference_images' => $references,
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => 6000,
                'source_app_code' => 'aigc_canvas',
                'source_type' => 'ecommerce_design_analysis',
            ]);
            $json = self::parseJson((string)($response['content'] ?? ''));
            if (empty($json)) {
                return [];
            }
            $json['detail_sections'] = self::normalizeSections((array)($json['detail_sections'] ?? []));
            $json['design_analysis'] = self::normalizeAnalysis($json, $slots, (int)($json['recommended_section_count'] ?? 0), true);
            return $json;
        } catch (Exception) {
            if (!empty($references)) {
                return self::resolveWithLlm($tenantId, $userId, $content, $slots, [], $explicitSections, $requestedCount);
            }
            return [];
        }
    }

    private static function normalizeAnalysis(array $source, array $slots, int $count, bool $llmSucceeded): array
    {
        $direction = is_array($source['design_direction'] ?? null) ? $source['design_direction'] : [];
        return [
            'product_summary' => trim((string)($source['product_summary'] ?? $slots['product_info'] ?? '')),
            'detected_product_features' => $llmSucceeded ? array_values(array_filter(array_map('strval', (array)($source['detected_product_features'] ?? [])))) : [],
            'target_platform' => trim((string)($source['target_platform'] ?? $slots['platform'] ?? '淘宝/京东')),
            'target_audience' => trim((string)($source['target_audience'] ?? $slots['target_audience'] ?? '')),
            'design_direction' => [
                'style_keywords' => array_values(array_filter(array_map('strval', (array)($direction['style_keywords'] ?? [$slots['style'] ?? '高端商业摄影'])))),
                'palette' => array_values(array_filter(array_map('strval', (array)($direction['palette'] ?? $slots['brand_colors'] ?? [])))),
                'materials' => array_values(array_filter(array_map('strval', (array)($direction['materials'] ?? [])))),
                'lighting' => trim((string)($direction['lighting'] ?? '商业摄影光线')),
                'composition' => trim((string)($direction['composition'] ?? '主体清晰、层级明确、保留文案空间')),
                'typography' => trim((string)($direction['typography'] ?? '适合电商详情页的信息层级')),
            ],
            'recommended_section_count' => $count,
        ];
    }

    private static function fallbackSections(int $count, array $slots, string $content): array
    {
        $names = ['品牌KV主视觉', '核心卖点', '痛点对比', '产地与信任', '工艺与材质', '核心体验', '差异化优势', '产品细节', '使用场景', '品牌背书', '规格参数', '购买引导'];
        $sections = [];
        for ($index = 0; $index < $count; $index++) {
            $title = $names[$index] ?? '详情区块' . ($index + 1);
            $sections[] = [
                'section_index' => $index + 1,
                'section_key' => 'section_' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT),
                'title' => $title,
                'copy_content' => [],
                'image_prompt' => self::fallbackPrompt($title, $slots, $content),
                'ratio' => self::fallbackRatio($index, $title, (string)($slots['ratio'] ?? '')),
            ];
        }
        return self::normalizeSections($sections);
    }

    private static function applyPlannedRatios(array $lockedSections, array $plannedSections, string $explicitRatio): array
    {
        $planned = self::normalizeSections($plannedSections);
        $byKey = [];
        foreach ($planned as $section) {
            $byKey[(string)($section['section_key'] ?? '')] = (string)($section['ratio'] ?? '');
        }
        foreach ($lockedSections as $index => &$section) {
            $key = (string)($section['section_key'] ?? '');
            $ratio = trim($explicitRatio) !== ''
                ? $explicitRatio
                : (string)($byKey[$key] ?? $planned[$index]['ratio'] ?? '');
            $section['ratio'] = self::normalizeRatio($ratio);
        }
        unset($section);
        return self::normalizeSections($lockedSections);
    }

    private static function fallbackRatio(int $index, string $title, string $explicitRatio): string
    {
        if (trim($explicitRatio) !== '') {
            return self::normalizeRatio($explicitRatio);
        }
        $text = mb_strtolower($title, 'UTF-8');
        foreach (['参数', '规格', '工艺', '对比', '解析', '流程', '背书'] as $keyword) {
            if (str_contains($text, $keyword)) {
                return '9:16';
            }
        }
        return $index === 0 ? '3:4' : '2:3';
    }

    private static function normalizeRatio(string $ratio): string
    {
        $ratio = str_replace(['／', '/'], ':', trim($ratio));
        return in_array($ratio, self::ALLOWED_RATIOS, true) ? $ratio : self::DEFAULT_RATIO;
    }

    private static function fallbackPrompt(string $title, array $slots, string $content): string
    {
        $product = trim((string)($slots['product_name'] ?? $slots['product_info'] ?? ''));
        if ($product === '' && preg_match('/《([^》]+)》/u', $content, $match)) {
            $product = trim((string)$match[1]);
        }
        $product = $product !== '' ? mb_substr($product, 0, 100, 'UTF-8') : '商品';
        $style = trim((string)($slots['style'] ?? '高端、真实、商业摄影'));
        return "{$product}，{$title}，{$style}，商品主体准确，构图完整，适合电商详情页纵向视觉";
    }

    private static function sanitizeImagePrompt(string $prompt): string
    {
        $prompt = preg_replace('/\[@(?:image|video|audio):[^\]]+\]/ui', '', $prompt) ?? $prompt;
        $prompt = preg_replace('/https?:\/\/\S+/ui', '', $prompt) ?? $prompt;
        $prompt = preg_replace('/^\s*(?:中文提示词|Prompt)\s*[:：]?\s*/ui', '', $prompt) ?? $prompt;
        return trim($prompt);
    }

    private static function withSingleFrameConstraint(string $prompt): string
    {
        $constraint = '生成单张独立完整画面，仅表现当前主题。禁止多宫格、拼贴、网格、联系表、多页面预览和多个详情页区块合成。';
        return str_contains($prompt, '禁止多宫格') ? trim($prompt) : trim($prompt . '。' . $constraint);
    }

    private static function fallbackAnalysisReply(array $plan): string
    {
        $analysis = (array)($plan['design_analysis'] ?? []);
        $direction = (array)($analysis['design_direction'] ?? []);
        $styles = implode('、', (array)($direction['style_keywords'] ?? []));
        $palette = implode('、', (array)($direction['palette'] ?? []));
        $titles = array_map(static fn(array $section): string => '第' . (int)$section['section_index'] . '张「' . (string)$section['title'] . '」', array_slice((array)($plan['detail_sections'] ?? []), 0, 5));
        $lines = [
            '我已经完成了商品资料和设计需求的分析。',
            trim((string)($analysis['product_summary'] ?? '')),
            $styles !== '' ? '视觉方向建议采用：' . $styles . '。' : '',
            $palette !== '' ? '主色建议使用：' . $palette . '。' : '',
            '这套详情页共规划 ' . (int)($plan['section_count'] ?? 0) . ' 张。第一批将生成：' . implode('、', $titles) . '。',
            '目前还没有提交图片任务，确认后我再开始生成第一批。',
        ];
        return implode("\n\n", array_values(array_filter($lines)));
    }

    private static function normalizeReferenceImages(array $images): array
    {
        $result = [];
        foreach ($images as $image) {
            $url = is_array($image) ? (string)($image['url'] ?? $image['uri'] ?? '') : (string)$image;
            $url = trim($url);
            if ($url !== '' && preg_match('/^https?:\/\//i', $url)) {
                $result[$url] = $url;
            }
        }
        return array_values($result);
    }

    private static function parseJson(string $content): array
    {
        $text = trim($content);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $json = json_decode($text, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $text, $match)) {
            $json = json_decode($match[0], true);
        }
        return is_array($json) ? $json : [];
    }
}
