<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Immutable image inputs derived only from an accepted screenplay. */
final class ImageHandoff
{
    public const VERSION = 1;

    public static function targets(array $candidate, string $style): array
    {
        $document = $candidate['document']; $plan = $candidate['shot_plan'];
        ShotCompiler::assertCurrent($document, $plan);
        $targets = [];
        foreach ($document['entities'] as $entity) {
            $state = ['entity_id' => $entity['entity_id'], 'kind' => $entity['kind'], 'name' => $entity['canonical_name'],
                'identity_facts' => $entity['identity_facts'], 'appearance' => $entity['description'], 'style' => $style];
            $targets[] = ['target_id' => $entity['entity_id'], 'task_type' => $entity['kind'] === 'location' ? 'scene_image' : 'subject_image',
                'name' => $entity['canonical_name'], 'description' => $entity['description'], 'state_hash' => ContentDocument::hash($state),
                'entity_ids' => [$entity['entity_id']], 'state' => $state];
        }
        foreach ($plan['shots'] as $shot) {
            $targets[] = ['target_id' => $shot['shot_id'], 'task_type' => 'shot_image', 'name' => '分镜 ' . $shot['order'],
                'description' => $shot['visual_description'], 'state_hash' => ContentDocument::hash([$shot, $style]),
                'entity_ids' => array_values(array_unique(array_merge($shot['entity_ids'], [$shot['location_id']]))), 'shot' => $shot];
        }
        return $targets;
    }

    public static function compile(array $candidate, string $style, string $type, string $id, string $expectedRevision, array $bindings = []): array
    {
        $document = $candidate['document']; $plan = $candidate['shot_plan'];
        if ($expectedRevision !== $document['revision_id'] || ($candidate['coverage_report']['status'] ?? '') !== 'ready') throw new RuntimeException('剧本版本已变化或尚未就绪，请刷新后制作', 409);
        $target = null;
        foreach (self::targets($candidate, $style) as $row) if ($row['task_type'] === $type && $row['target_id'] === $id) $target = $row;
        if (!$target) throw new RuntimeException('制作对象不属于当前剧本', 422);
        $references = []; $unitRefs = []; $sourceLinks = [];
        if ($type === 'shot_image') {
            foreach ($target['entity_ids'] as $entity) {
                if (empty($bindings[$entity])) throw new RuntimeException('请先完成此分镜的主体和场景图片', 409);
                $references[] = $bindings[$entity];
            }
            $shot = $target['shot']; $unitRefs = $shot['unit_refs'];
            $prompt = "生成本分镜的一张画面，按参考图保持主体身份和场景一致；不要绘制文字或对白字幕。\n"
                . $shot['visual_description'] . "\n" . $shot['composition'];
        } else {
            $state = $target['state'];
            $prompt = ($type === 'scene_image' ? '生成场景设定图，不添加剧本未指定的人物。' : ($state['kind'] === 'prop' ? '生成道具设定图。' : '生成单个角色的主体设定图。'))
                . "\n名称：" . $state['name'] . "\n设定：" . $state['appearance'] . "\n已确认身份："
                . json_encode($state['identity_facts'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            foreach (ContentDocument::units($document) as $unit) if ($unit['location_id'] === $id || in_array($id, $unit['entity_ids'], true)) {
                $unitRefs[] = ['unit_id' => $unit['unit_id'], 'start' => 0, 'end' => mb_strlen($unit['text'], 'UTF-8')];
            }
        }
        $units = ContentDocument::units($document);
        foreach ($unitRefs as $ref) $sourceLinks[$ref['unit_id']] = $units[$ref['unit_id']]['source_links'];
        $prompt = trim($style . "\n" . $prompt);
        $trace = ['pipeline_version' => GenerationPipeline::VERSION, 'compiler_version' => self::VERSION,
            'document_revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash'],
            'shot_revision_id' => $plan['revision_id'], 'shot_hash' => $plan['content_hash'],
            'target_id' => $id, 'task_type' => $type, 'entity_ids' => $target['entity_ids'], 'state_hash' => $target['state_hash'],
            'unit_refs' => $unitRefs, 'source_links' => $sourceLinks, 'dependency_versions' => $document['dependency_versions'],
            'coverage_hash' => ContentDocument::hash($candidate['coverage_report']), 'bindings' => $references,
            'prompt_hash' => hash('sha256', $prompt)];
        $input = ['prompt' => $prompt, 'trace' => $trace];
        $input['input_hash'] = ContentDocument::hash($input);
        return $input;
    }

    public static function assertIntact(array $input): void
    {
        $hash = $input['input_hash'] ?? ''; unset($input['input_hash']);
        if ($hash !== ContentDocument::hash($input) || ($input['trace']['prompt_hash'] ?? '') !== hash('sha256', (string)($input['prompt'] ?? ''))) {
            throw new RuntimeException('制作输入校验失败，已停止提交', 409);
        }
    }
}
