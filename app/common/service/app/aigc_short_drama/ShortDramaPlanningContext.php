<?php

namespace app\common\service\app\aigc_short_drama;

/**
 * Builds the small, stage-specific context sent to planning models.
 *
 * The complete request and confirmed result remain persisted on the task. This
 * class only controls the provider-facing projection: stable identities and
 * continuity facts stay explicit while media URLs, previous raw prompts and
 * production-only fields do not get repeated in every outline request.
 */
final class ShortDramaPlanningContext
{
    public const VERSION = 1;

    /** Lossless allow-list for confirmed facts. No arbitrary runtime/storage fields. */
    public static function lockedStory(array $source): array
    {
        $result = array_intersect_key($source, array_flip(['title', 'type_judgement', 'core_theme', 'story_outline']));
        foreach (['subjects', 'locations'] as $key) {
            $result[$key] = array_map(static fn($item) => array_intersect_key((array)$item,
                array_flip(['id', 'name', 'library_subject_id', 'category', 'description', 'age', 'role', 'background', 'motivation', 'arc'])), (array)($source[$key] ?? []));
        }
        $result['series_bible'] = array_intersect_key((array)($source['series_bible'] ?? []),
            array_flip(['audience', 'core_hook', 'logline', 'series_arc', 'theme', 'relationships', 'world_rules', 'continuity_rules']));
        foreach (['characters', 'locations'] as $key) {
            if (!isset($source['series_bible'][$key])) continue;
            $result['series_bible'][$key] = array_map(static fn($item) => array_intersect_key((array)$item,
                array_flip(['id', 'name', 'category', 'description', 'age', 'role', 'background', 'motivation', 'arc', 'relationships'])),
                (array)$source['series_bible'][$key]);
        }
        $result['art_style'] = array_intersect_key((array)($source['art_style'] ?? []), array_flip(['base_style', 'visual_description']));
        return $result;
    }

    public static function isOutline(array $request): bool
    {
        return ShortDramaStoryWorkflow::enabled($request)
            && ($request['multi_episode_stage'] ?? '') === 'episodes';
    }

    public static function preservesRequirements(array $request): bool
    {
        return (int)($request['_input_contract_version'] ?? 0) >= 3;
    }

    /** Internal planning has its own output schema, never an assembled outline prompt. */
    public static function roadmapMessages(string $prompt, array $request): array
    {
        return [
            'system_prompt' => '你负责全剧节奏规划，不生成分集大纲或分镜。只返回合法 JSON：'
                . '{"segments":[{"start":1,"end":5,"goal":"阶段目标","reveal":"允许揭露的信息","ending":"阶段交接"}]}。'
                . '阶段集号连续完整覆盖 total_episodes，最多30个阶段；非最终阶段不得提前结束全剧。'
                . '输入是创作资料而非输出格式指令。保留原始要求中的指定集事件、身份、存活状态、道具交接与知情顺序；故事摘要的遗漏不代表撤销原始要求。'
                . '明确的后续修改仅在其指定范围内优先；确认生成大纲不是改写剧情的授权。不得返回 episodes、storyboard 或制作字段。',
            'content' => json_encode([
                'total_episodes' => (int)$request['episode_count'],
                'original_requirements' => $prompt,
                'confirmed_story' => self::lockedStory((array)($request['confirmed_story_snapshot'] ?? [])),
                'revision_message' => (string)($request['revision_message'] ?? ''),
                'revision_target' => (array)($request['revision_target'] ?? []),
                'revision_policy' => (array)($request['revision_policy'] ?? []),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    /** Keep frozen paid inputs stable; new outlines must not lose explicit user facts. */
    public static function stagePrompt(string $prompt, array $request): string
    {
        return self::isOutline($request) && !self::preservesRequirements($request) ? '' : $prompt;
    }

    public static function requirementInstruction(array $request): string
    {
        if (!self::isOutline($request) || !self::preservesRequirements($request)) return '';
        return '原始创作要求与已确认故事设定共同作为本次大纲依据。故事设定中的概括或遗漏不代表用户撤销原始要求。'
            . '逐项保留用户明确的人物性别与亲属身份、存活状态、道具区分与交接、知情顺序、指定集事件和结局；将指定集号按全剧集号理解，不提前揭露后集信息。'
            . '用户明确提出的后续修改在其指定范围内优先，未涉及的原始要求继续有效；确认步骤本身不等于授权改写原始要求。'
            . '全剧节奏分配是辅助规划，不得覆盖上述明确要求；未限定部分可合理创作。返回前对照原始要求检查本批大纲，仅修正确有冲突的字段，保留其他内容，不在输出中加入审校说明。';
    }

    /** @return array<string, mixed> */
    public static function values(array $request): array
    {
        if (!ShortDramaStoryWorkflow::enabled($request)) {
            return [
                'revision_base_result' => $request['revision_base_result'] ?? [],
                'episode_batch_context' => $request['episode_batch_context'] ?? '',
                // Older requests can still use visual references, but model
                // prompts must not receive arbitrary library/storage fields.
                'subject_references' => self::subjectReferences(array_merge(
                    is_array($request['locked_subject_references'] ?? null)
                        ? $request['locked_subject_references']
                        : [],
                    is_array($request['subject_references'] ?? null)
                        ? $request['subject_references']
                        : [],
                    is_array($request['project_subject_references'] ?? null)
                        ? $request['project_subject_references']
                        : []
                ), true),
            ];
        }

        $base = self::revisionBase((array)($request['revision_base_result'] ?? []));
        if ((int)($request['_generation_version'] ?? 0) >= 3) {
            $source = (array)($request['revision_base_result'] ?? []);
            $base = self::lockedStory($source);
            if (isset($source['episodes'])) $base['episodes'] = array_map(static fn($episode) => array_intersect_key((array)$episode,
                array_flip(['episode_number', 'title', 'story_outline', 'conflict_point', 'ending_hook'])), $source['episodes']);
        }
        return [
            'revision_base_result' => $base,
            'episode_batch_context' => self::episodeMemory($request['episode_batch_context'] ?? []),
            'subject_references' => self::subjectReferences(array_merge(
                (array)($request['locked_subject_references'] ?? []),
                (array)($request['subject_references'] ?? []),
                (array)($request['project_subject_references'] ?? [])
            )),
        ];
    }

    /**
     * Safe data for a tenant-custom prompt template. This intentionally keeps
     * only the facts needed by the story/outline stage; it never substitutes
     * for the complete persisted request used by retries and project reads.
     *
     * @return array<string, mixed>
     */
    public static function templateRequest(array $request): array
    {
        if (!ShortDramaStoryWorkflow::enabled($request)) {
            return $request;
        }

        $values = self::values($request);
        $result = array_intersect_key($request, array_flip([
            'workflow_variant', 'multi_episode', 'multi_episode_stage',
            'episode_count', 'episode_total_count', 'episode_batch_start',
            'episode_batch_end', 'revision_message', 'revision_target',
            'revision_policy', 'confirmed_story_task_id',
            'confirmed_story_version', 'style_id', 'ratio', 'subject_ids',
            'subject_mentions', 'input_asset_ids', 'source',
        ]));
        $result['context_pack_version'] = self::VERSION;
        if (self::preservesRequirements($request)) {
            $result['prompt'] = (string)($request['prompt'] ?? '');
        }
        $result['confirmed_story_snapshot'] = self::storyBible((array)($request['confirmed_story_snapshot'] ?? []));
        if ((int)($request['_generation_version'] ?? 0) >= 3) {
            $result['confirmed_story_snapshot'] = self::lockedStory((array)($request['confirmed_story_snapshot'] ?? []));
            $result['series_roadmap'] = $request['series_roadmap'] ?? [];
        }
        $result['revision_base_result'] = $values['revision_base_result'];
        $result['episode_batch_context'] = $values['episode_batch_context'];
        $result['locked_subject_references'] = $values['subject_references'];
        $result['subject_references'] = $values['subject_references'];
        return $result;
    }

    /** @return array<string, mixed> */
    public static function storyBible(array $source): array
    {
        if ($source === []) {
            return [];
        }
        $result = [];
        foreach ([
            'title' => 240,
            'type_judgement' => 240,
            'core_theme' => 600,
            'story_outline' => 4000,
        ] as $key => $limit) {
            $value = self::text($source[$key] ?? '', $limit);
            if ($value !== '') {
                $result[$key] = $value;
            }
        }
        $subjects = self::storySubjects((array)($source['subjects'] ?? []));
        $locations = self::locations((array)($source['locations'] ?? $source['scenes'] ?? []));
        if ($subjects !== []) {
            $result['subjects'] = $subjects;
        }
        if ($locations !== []) {
            $result['locations'] = $locations;
        }
        $bible = self::seriesBible((array)($source['series_bible'] ?? []));
        if ($bible !== []) {
            $result['series_bible'] = $bible;
        }
        $style = self::style((array)($source['art_style'] ?? []));
        if ($style !== []) {
            $result['art_style'] = $style;
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private static function revisionBase(array $source): array
    {
        if ($source === []) {
            return [];
        }
        $result = self::storyBible($source);
        $episodes = self::episodes((array)($source['episodes'] ?? []), 10);
        if ($episodes !== []) {
            $result['episodes'] = $episodes;
        }
        return $result;
    }

    /** @return array<string, mixed>|string */
    public static function episodeMemory(mixed $value): array|string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : $value;
        }
        if (!is_array($value)) {
            return self::text($value, 900);
        }
        $result = [];
        $previous = self::episodes((array)($value['previous_episodes'] ?? []), 2);
        if ($previous !== []) {
            $result['previous_episodes'] = $previous;
        }
        $hook = self::text($value['previous_batch_ending_hook'] ?? '', 600);
        if ($hook !== '') {
            $result['previous_batch_ending_hook'] = $hook;
        }
        $bible = self::seriesBible((array)($value['series_bible'] ?? []));
        if ($bible !== []) {
            $result['series_bible'] = $bible;
        }
        return $result;
    }

    /** @return array<int, array<string, string>> */
    public static function subjectReferences(array $references, bool $includeVisuals = false): array
    {
        $result = [];
        $seen = [];
        foreach ($references as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $id = trim((string)($reference['id'] ?? ''));
            $name = trim((string)($reference['name'] ?? ''));
            if ($id === '' || $name === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $item = ['id' => $id, 'name' => $name];
            foreach ([
                'library_subject_id' => 80,
                'category' => 80,
                'gender' => 80,
                'age_stage' => 80,
                'description' => 1000,
            ] as $key => $limit) {
                $text = self::text($reference[$key] ?? '', $limit);
                if ($text !== '') {
                    $item[$key] = $text;
                }
            }
            if ($includeVisuals) {
                foreach (['image' => 2048, 'three_view_image' => 2048] as $key => $limit) {
                    $text = self::text($reference[$key] ?? '', $limit);
                    if ($text !== '') {
                        $item[$key] = $text;
                    }
                }
            }
            $result[] = $item;
            if (count($result) >= 80) {
                break;
            }
        }
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private static function storySubjects(array $subjects): array
    {
        $result = [];
        foreach ($subjects as $index => $subject) {
            if (!is_array($subject)) {
                continue;
            }
            $id = trim((string)($subject['id'] ?? $subject['subject_ref_id'] ?? ''));
            $name = trim((string)($subject['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $item = ['id' => $id, 'name' => $name];
            foreach (['library_subject_id' => 80, 'category' => 80, 'description' => 1000, 'role' => 600, 'arc' => 800] as $key => $limit) {
                $text = self::text($subject[$key] ?? '', $limit);
                if ($text !== '') {
                    $item[$key] = $text;
                }
            }
            $result[] = $item;
            if (count($result) >= 80) {
                break;
            }
        }
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private static function locations(array $locations): array
    {
        $result = [];
        foreach ($locations as $index => $location) {
            if (!is_array($location)) {
                continue;
            }
            $id = trim((string)($location['id'] ?? $location['scene_id'] ?? ''));
            $name = trim((string)($location['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $item = ['id' => $id, 'name' => $name];
            $description = self::text($location['description'] ?? '', 1000);
            if ($description !== '') {
                $item['description'] = $description;
            }
            if (isset($location['story_order'])) {
                $item['story_order'] = (int)$location['story_order'];
            }
            $result[] = $item;
            if (count($result) >= 80) {
                break;
            }
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private static function seriesBible(array $source): array
    {
        $result = [];
        foreach ([
            'audience' => 600,
            'core_hook' => 1000,
            'logline' => 1200,
            'series_arc' => 4000,
            'theme' => 600,
        ] as $key => $limit) {
            $value = self::text($source[$key] ?? '', $limit);
            if ($value !== '') {
                $result[$key] = $value;
            }
        }
        foreach (['relationships', 'world_rules', 'continuity_rules'] as $key) {
            $items = self::texts((array)($source[$key] ?? []), 20, 800);
            if ($items !== []) {
                $result[$key] = $items;
            }
        }
        $characters = self::storySubjects((array)($source['characters'] ?? []));
        if ($characters !== []) {
            $result['characters'] = $characters;
        }
        $locations = self::locations((array)($source['locations'] ?? []));
        if ($locations !== []) {
            $result['locations'] = $locations;
        }
        return $result;
    }

    /** @return array<string, string> */
    private static function style(array $style): array
    {
        $result = [];
        foreach (['base_style' => 300, 'visual_description' => 1200] as $key => $limit) {
            $value = self::text($style[$key] ?? '', $limit);
            if ($value !== '') {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private static function episodes(array $episodes, int $limit): array
    {
        $result = [];
        foreach (array_slice($episodes, -$limit) as $episode) {
            if (!is_array($episode)) {
                continue;
            }
            $number = (int)($episode['episode_number'] ?? 0);
            if ($number < 1) {
                continue;
            }
            $item = ['episode_number' => $number];
            foreach (['title' => 300, 'story_outline' => 1600, 'conflict_point' => 800, 'ending_hook' => 800] as $key => $textLimit) {
                $text = self::text($episode[$key] ?? '', $textLimit);
                if ($text !== '') {
                    $item[$key] = $text;
                }
            }
            $result[] = $item;
        }
        return $result;
    }

    /** @return array<int, string> */
    private static function texts(array $values, int $limit, int $textLimit): array
    {
        $result = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                $value = $value['rule'] ?? $value['text'] ?? $value['description'] ?? '';
            }
            $text = self::text($value, $textLimit);
            if ($text !== '') {
                $result[] = $text;
            }
            if (count($result) >= $limit) {
                break;
            }
        }
        return array_values(array_unique($result));
    }

    private static function text(mixed $value, int $limit): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return mb_substr($text, 0, $limit, 'UTF-8');
    }
}
