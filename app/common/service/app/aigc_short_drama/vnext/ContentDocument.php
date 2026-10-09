<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Canonical screenplay. Positions are zero-based Unicode code points, end exclusive. */
final class ContentDocument
{
    public const VERSION = 1;

    public static function hash(array $value): string
    {
        $sort = static function (array $item) use (&$sort): array {
            if ($item && array_keys($item) !== range(0, count($item) - 1)) ksort($item);
            foreach ($item as &$child) if (is_array($child)) $child = $sort($child);
            return $item;
        };
        return hash('sha256', json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function text($value, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('正文结构无效：' . $field, 422);
        }
        return $value;
    }
    public static function rows($value, string $field): array
    {
        if (!is_array($value) || ($value && array_keys($value) !== range(0, count($value) - 1))) throw new RuntimeException('列表结构无效：' . $field, 422);
        foreach ($value as $row) if (!is_array($row)) throw new RuntimeException('列表条目结构无效：' . $field, 422);
        return $value;
    }

    /** $context is server-owned, persisted before submission; models never allocate identity. */
    public static function normalize(array $reply, array $context): array
    {
        foreach (['revision_id', 'episode_id', 'entity_scope'] as $key) self::text($context[$key] ?? null, $key);
        $entities = []; $aliases = []; $transforms = [];
        $register = static function (array $entity, bool $confirmed) use (&$entities, &$aliases, &$transforms, $context): void {
            $ref = self::text($entity['ref'] ?? null, 'entity.ref');
            $name = trim(self::text($entity['name'] ?? null, 'entity.name'));
            $kind = $entity['kind'] ?? '';
            if (!in_array($kind, ['character', 'location', 'prop'], true)) throw new RuntimeException('实体类型无效', 422);
            $facts = $entity['identity_facts'] ?? [];
            if (!is_array($facts)) throw new RuntimeException('人物身份事实无效', 422);
            if (isset($entity['description']) && !is_string($entity['description'])) throw new RuntimeException('实体说明应为文本', 422);
            $names = array_values(array_unique(array_merge([$ref, $name], (array)($entity['aliases'] ?? []))));
            $matches = [];
            foreach ($names as $alias) {
                self::text($alias, 'entity.alias');
                foreach ($aliases[$kind . ':' . $alias] ?? [] as $id) $matches[$id] = true;
            }
            // A supplied short alias wins over names. Confirmed names may be ambiguous.
            if (isset($aliases[$kind . ':' . $ref])) $matches = array_fill_keys($aliases[$kind . ':' . $ref], true);
            if (!$confirmed && count($matches) > 1) throw new RuntimeException('实体别名存在歧义：' . $ref, 409);
            $id = $confirmed ? self::text($entity['entity_id'] ?? null, 'entity_id')
                : (key($matches) ?: 'ent_' . substr(self::hash([$context['entity_scope'], $kind, $name]), 0, 24));
            if ($confirmed && isset($aliases[$kind . ':' . $ref]) && !in_array($id, $aliases[$kind . ':' . $ref], true)) throw new RuntimeException('已确认实体短别名冲突：' . $ref, 409);
            if (isset($entities[$id])) {
                $old = $entities[$id];
                if ($old['kind'] !== $kind) throw new RuntimeException('实体类型冲突：' . $ref, 409);
                if ($name !== $old['canonical_name'] && !in_array($name, $old['aliases'], true)) throw new RuntimeException('实体姓名与已登记身份不符：' . $ref, 409);
                foreach ($facts as $key => $value) {
                    if (array_key_exists($key, $old['identity_facts']) && $old['identity_facts'][$key] !== $value) {
                        throw new RuntimeException('人物身份冲突：' . $name . '/' . $key, 409);
                    }
                }
                // Supplemental model prose is a candidate, never an overwrite of confirmed identity.
                $entities[$id]['candidate_descriptions'] = array_values(array_unique(array_merge(
                    $old['candidate_descriptions'], !empty($entity['description']) ? [(string)$entity['description']] : [])));
                $transforms[] = ['code' => 'compatible_entity_declaration', 'ref' => $ref, 'entity_id' => $id];
            } else {
                $entities[$id] = ['entity_id' => $id, 'kind' => $kind, 'canonical_name' => $name,
                    'identity_facts' => $facts, 'description' => (string)($entity['description'] ?? ''),
                    'confirmed' => $confirmed, 'aliases' => [], 'candidate_descriptions' => []];
            }
            foreach ($names as $alias) {
                $aliases[$kind . ':' . $alias] = array_values(array_unique(array_merge($aliases[$kind . ':' . $alias] ?? [], [$id])));
                $entities[$id]['aliases'][] = $alias;
            }
            $entities[$id]['aliases'] = array_values(array_unique($entities[$id]['aliases']));
        };
        foreach (self::rows($context['entities'] ?? [], 'context.entities') as $entity) $register($entity, true);
        foreach (self::rows($reply['entities'] ?? [], 'entities') as $entity) $register($entity, false);
        $resolve = static function ($ref, string $kind) use (&$aliases): string {
            $matches = $aliases[$kind . ':' . self::text($ref, $kind . '.ref')] ?? [];
            if (count($matches) !== 1) throw new RuntimeException('实体引用无法唯一定位：' . $ref, 422);
            return $matches[0];
        };
        if (empty($reply['scenes']) || !is_array($reply['scenes'])) throw new RuntimeException('正文缺少完整场次', 422);
        $scenes = []; $ordinal = 0;
        foreach (self::rows($reply['scenes'], 'scenes') as $i => $scene) {
            if (empty($scene['units']) || !is_array($scene['units'])) throw new RuntimeException('正文场次缺少动作或对白', 422);
            $normalized = ['scene_id' => self::id($context['revision_id'], 'scene', $i + 1),
                'location_id' => $resolve($scene['location'] ?? null, 'location'),
                'time_context' => (string)($scene['time_context'] ?? ''), 'units' => []];
            foreach (self::rows($scene['units'], 'units') as $unit) {
                $type = $unit['type'] ?? '';
                if (!in_array($type, ['action', 'dialogue'], true)) throw new RuntimeException('正文单元类型无效', 422);
                $text = self::text($unit['text'] ?? null, 'unit.text');
                $row = ['unit_id' => self::id($context['revision_id'], 'unit', ++$ordinal), 'ref' => 'u' . $ordinal,
                    'type' => $type, 'text' => $text, 'text_hash' => hash('sha256', $text), 'entity_ids' => []];
                if (isset($unit['characters']) && !is_array($unit['characters'])) throw new RuntimeException('人物引用必须为数组', 422);
                foreach ($unit['characters'] ?? [] as $ref) $row['entity_ids'][] = $resolve($ref, 'character');
                $row['entity_ids'] = array_values(array_unique($row['entity_ids']));
                if ($type === 'dialogue') {
                    $row['speech_type'] = $unit['speech_type'] ?? 'spoken';
                    if (!in_array($row['speech_type'], ['spoken', 'voiceover', 'narration'], true)) throw new RuntimeException('对白发声类型无效', 422);
                    $row['speaker_id'] = $row['speech_type'] === 'narration' ? null : $resolve($unit['speaker'] ?? null, 'character');
                    if ($row['speaker_id'] && !in_array($row['speaker_id'], $row['entity_ids'], true)) $row['entity_ids'][] = $row['speaker_id'];
                }
                $row['source_links'] = self::sourceLinks(self::rows($unit['source_links'] ?? [], 'source_links'), $context['sources'] ?? []);
                $normalized['units'][] = $row;
            }
            $scenes[] = $normalized;
        }
        $document = ['schema_version' => self::VERSION, 'revision_id' => $context['revision_id'],
            'episode_id' => $context['episode_id'], 'dependency_versions' => $context['dependency_versions'] ?? [],
            'title' => self::text($reply['title'] ?? null, 'title'), 'entities' => array_values($entities),
            'scenes' => $scenes, 'transforms' => $transforms, 'status' => 'draft'];
        return self::seal($document);
    }

    public static function sourceLinks(array $links, array $sources): array
    {
        $result = [];
        foreach ($links as $link) {
            $source = $sources[$link['source_id'] ?? ''] ?? null;
            if (!$source || ($link['source_hash'] ?? '') !== hash('sha256', $source['text'])) throw new RuntimeException('原文引用已过期或不存在', 422);
            self::slice($source['text'], $link['start'] ?? null, $link['end'] ?? null, $link['quote'] ?? null);
            $result[] = $link;
        }
        return $result;
    }

    public static function slice(string $text, $start, $end, $quote = null): string
    {
        if (!is_int($start) || !is_int($end) || $start < 0 || $end <= $start || $end > mb_strlen($text, 'UTF-8')) {
            throw new RuntimeException('正文引用范围无效', 422);
        }
        $value = mb_substr($text, $start, $end - $start, 'UTF-8');
        if ($quote !== null && $quote !== $value) throw new RuntimeException('引文与正文不符', 422);
        return $value;
    }

    public static function units(array $document): array
    {
        $result = [];
        foreach ($document['scenes'] as $scene) foreach ($scene['units'] as $unit) $result[$unit['unit_id']] = $unit + ['scene_id' => $scene['scene_id'], 'location_id' => $scene['location_id']];
        return $result;
    }

    public static function render(array $document): array
    {
        $names = array_column($document['entities'], 'canonical_name', 'entity_id'); $lines = [];
        foreach ($document['scenes'] as $scene) {
            $lines[] = trim($names[$scene['location_id']] . ' ' . $scene['time_context']);
            foreach ($scene['units'] as $unit) $lines[] = ($unit['type'] === 'dialogue'
                ? ($unit['speaker_id'] === null ? '旁白' : $names[$unit['speaker_id']]) . '：' : '') . $unit['text'];
        }
        return $lines;
    }

    /** Explicit text patches retain logical unit identities, invalidate every dependent hash. */
    public static function patch(array $document, array $patches, string $revision): array
    {
        self::text($revision, 'revision_id');
        if ($revision === $document['revision_id']) throw new RuntimeException('编辑必须建立新修订', 409);
        $units = self::units($document); $seen = [];
        foreach (self::rows($patches, 'patches') as $patch) {
            if (array_diff(array_keys($patch), ['unit_id', 'before_hash', 'text'])) throw new RuntimeException('局部补丁含不允许修改的字段', 422);
            $id = $patch['unit_id'] ?? '';
            if (!isset($units[$id]) || isset($seen[$id]) || ($patch['before_hash'] ?? '') !== $units[$id]['text_hash']) throw new RuntimeException('编辑基线已变化或正文引用重复', 409);
            self::text($patch['text'] ?? null, 'patch.text'); $seen[$id] = $patch['text'];
        }
        foreach ($document['scenes'] as &$scene) foreach ($scene['units'] as &$unit) if (isset($seen[$unit['unit_id']])) {
            $unit['text'] = $seen[$unit['unit_id']]; $unit['text_hash'] = hash('sha256', $unit['text']);
            $unit['source_links'] = []; // Previous exact text evidence remains on the historical revision.
        }
        unset($scene, $unit);
        $document['parent_revision_id'] = $document['revision_id']; $document['revision_id'] = $revision;
        $document['status'] = 'draft';
        return self::seal($document);
    }

    private static function seal(array $document): array
    {
        $content = $document; unset($content['content_hash'], $content['status'], $content['transforms']);
        $document['content_hash'] = self::hash($content);
        return $document;
    }

    private static function id(string $revision, string $kind, int $ordinal): string
    {
        return $kind . '_' . substr(self::hash([$revision, $kind, $ordinal]), 0, 24);
    }
}
