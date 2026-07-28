<?php

namespace app\common\service\app\aigc_canvas\agent\planning;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use Exception;

final class RevisionPlanner
{
    public static function plan(
        int $tenantId,
        int $userId,
        string $instruction,
        array $decision,
        array $delivery
    ): array {
        $targets = self::targets($delivery, (array)($decision['target_scope']['section_keys'] ?? []));
        if (empty($targets)) {
            throw new Exception('没有找到需要修订的设计结果');
        }
        $planned = [];
        try {
            $result = AigcCanvasService::llmText($tenantId, $userId, [
                'content' => json_encode([
                    'task' => 'plan_independent_design_revisions',
                    'revision_instruction' => $instruction,
                    'decision' => $decision,
                    'design_analysis' => $delivery['design_analysis'] ?? [],
                    'targets' => array_map(static fn(array $target): array => [
                        'section_index' => $target['section_index'] ?? 0,
                        'section_key' => $target['section_key'] ?? '',
                        'title' => $target['title'] ?? '',
                        'original_prompt' => $target['prompt'] ?? '',
                        'copy_content' => $target['copy_content'] ?? [],
                        'ratio' => $target['ratio'] ?? '',
                    ], $targets),
                    'rules' => [
                        'Return exactly one revision item for every target section.',
                        'Apply only requested changes and preserve all other product identity, section theme and global style constraints.',
                        'Return semantic revision fields, never a provider-ready prompt.',
                        'Each revision describes one standalone complete image, never a grid, collage, contact sheet or multi-page preview.',
                        'When the request changes image text, update only that section copy and typography/layout direction.',
                        'Do not put source image URLs or conversation transcripts in the semantic fields.',
                    ],
                    'output_schema' => [
                        'summary' => 'string',
                        'revisions' => [[
                            'section_key' => 'string',
                            'updated_copy_content' => 'object',
                            'updated_media_config' => 'object',
                        'purpose' => 'string',
                        'narrative' => 'string',
                        'visual_direction' => 'object',
                        ]],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'system_prompt' => 'You are a senior visual revision planner. Return one compact JSON object only.',
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => 6000,
                'source_app_code' => AigcCanvasService::APP_CODE,
                'source_type' => 'canvas_revision_planner',
            ]);
            $json = self::parseJson((string)($result['content'] ?? ''));
            foreach ((array)($json['revisions'] ?? []) as $item) {
                if (is_array($item) && !empty($item['section_key'])) {
                    $planned[(string)$item['section_key']] = $item;
                }
            }
            $summary = trim((string)($json['summary'] ?? ''));
        } catch (Exception) {
            $summary = '';
        }

        $sections = [];
        foreach ($targets as $position => $target) {
            $key = (string)($target['section_key'] ?? 'section_' . ($position + 1));
            $item = $planned[$key] ?? [];
            $purpose = trim((string)($item['purpose'] ?? $target['title'] ?? ''));
            // Legacy LLM replies may still contain revised_prompt. Treat it as
            // semantic narration, never as a final provider prompt.
            $narrative = trim((string)($item['narrative'] ?? $item['revised_prompt'] ?? $instruction));
            $sections[] = [
                'section_index' => $position + 1,
                'section_key' => $key,
                'title' => (string)($target['title'] ?? ''),
                'purpose' => $purpose,
                'narrative' => $narrative,
                'visual_direction' => is_array($item['visual_direction'] ?? null) ? $item['visual_direction'] : [],
                'copy_content' => is_array($item['updated_copy_content'] ?? null)
                    ? $item['updated_copy_content']
                    : (array)($target['copy_content'] ?? []),
                'ratio' => (string)($item['updated_media_config']['ratio'] ?? $target['ratio'] ?? ''),
                'source_node_id' => (string)($target['node_id'] ?? ''),
                'source_target_element_id' => (string)($target['target_element_id'] ?? ''),
                'source_asset_url' => (string)($target['url'] ?? ''),
                'source_section_key' => $key,
            ];
        }
        return [
            'summary' => $summary,
            'sections' => $sections,
        ];
    }

    private static function targets(array $delivery, array $keys): array
    {
        $wanted = array_fill_keys(array_map('strval', $keys), true);
        return array_values(array_filter((array)($delivery['assets'] ?? []), static function ($asset) use ($wanted): bool {
            return is_array($asset)
                && (empty($wanted) || isset($wanted[(string)($asset['section_key'] ?? '')]));
        }));
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
