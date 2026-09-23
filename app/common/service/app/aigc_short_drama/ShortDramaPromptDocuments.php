<?php

namespace app\common\service\app\aigc_short_drama;

use InvalidArgumentException;

/** Effect-oriented documents. Conditions are deterministic; no model call is used to edit/migrate them. */
final class ShortDramaPromptDocuments
{
    public const FORMAT = 3;
    private static array $active = [];

    public static function definition(): array
    {
        static $definition;
        return $definition ??= json_decode(file_get_contents(__DIR__ . '/prompts/documents.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function enabled(): bool { return (ShortDramaPromptCatalog::snapshot()['mode'] ?? '') === 'documents'; }

    public static function custom(string $id): bool
    {
        return self::enabled() && (ShortDramaPromptCatalog::snapshot()['documents'][$id]['mode'] ?? '') === 'custom';
    }

    public static function scope(array $ids, callable $callback): mixed
    {
        $previous = self::$active;
        self::$active = $ids;
        try { return $callback(); } finally { self::$active = $previous; }
    }

    public static function replacesRule(string $key): bool
    {
        if (!self::enabled()) return false;
        if ($key === 'global_system_prompt') return true; // Migrated global text is included once in each applicable document.
        $owners = self::owners($key);
        if (self::$active === [] && count($owners) > 1) return false;
        foreach ($owners as $id) {
            if (!self::custom($id)) continue;
            if (self::$active === [] || in_array($id, self::$active, true)) return true;
        }
        return false;
    }

    public static function owners(string $key): array
    {
        $owners = [];
        foreach (self::definition()['documents'] as $id => $doc) {
            foreach ($doc['sections'] as $keys) if (in_array($key, $keys, true)) { $owners[] = $id; break; }
        }
        return $owners;
    }

    public static function body(string $id, ?array $values = null): string
    {
        $values ??= ShortDramaPromptCatalog::defaults();
        $spec = self::definition();
        $sections = [];
        foreach ($spec['documents'][$id]['sections'] as $condition => $keys) {
            $texts = array_values(array_filter(array_map(static fn(string $key): string => (string)($values[$key] ?? ''), $keys), static fn(string $v): bool => trim($v) !== ''));
            foreach ($texts as $text) $sections[$condition][] = $text;
        }
        foreach (self::definition()['extra_defaults'] ?? [] as $extra) {
            if ($extra['document'] === $id) $sections[$extra['condition']][] = $extra['text'];
        }
        $parts = [];
        foreach ($sections as $condition => $texts) {
            $parts[] = '【适用：' . $spec['conditions'][$condition] . "】\n" . implode("\n\n", array_unique($texts));
        }
        return implode("\n\n", $parts);
    }

    public static function extra(string $key): string
    {
        $item = ShortDramaPromptCatalog::snapshot()['document_extra_defaults'][$key] ?? self::definition()['extra_defaults'][$key];
        return self::custom($item['document']) ? '' : $item['text'];
    }

    public static function withoutGeneratedDefaults(array $data): array
    {
        // Provenance is only trusted from stored project data, never inferred from text similarity.
        foreach ((array)($data['_prompt_field_sources'] ?? []) as $field => $source) {
            if ($source === 'application') unset($data[$field]);
        }
        unset($data['_prompt_field_sources']);
        return $data;
    }

    public static function markGeneratedFields(array $before, array $after, array $fields): array
    {
        if (!self::enabled()) return $after;
        $sources = (array)($before['_prompt_field_sources'] ?? []);
        foreach ($fields as $field) {
            if (!array_key_exists($field, $before)) $sources[$field] = 'application';
            // Existing model/user/historical content is never classified by its wording.
        }
        if ($sources !== []) $after['_prompt_field_sources'] = $sources;
        return $after;
    }

    public static function validate(array $settings): array
    {
        $spec = self::definition()['documents'];
        foreach ($settings as $id => &$entry) {
            if (!isset($spec[$id]) || !is_array($entry)) throw new InvalidArgumentException('未知创作文档：' . $id);
            $mode = $entry['mode'] ?? '';
            if (!in_array($mode, ['application', 'inherit', 'custom'], true)) throw new InvalidArgumentException('未知提示词来源');
            if ($mode !== 'custom') { $entry = ['mode' => $mode]; continue; }
            $body = $entry['body'] ?? null;
            if (!is_string($body) || mb_strlen($body, 'UTF-8') > 60000) throw new InvalidArgumentException($spec[$id]['label'] . '必须是 60000 字以内的文本');
            if (trim($body) === '') throw new InvalidArgumentException($spec[$id]['label'] . '正文不能为空；如需恢复请使用恢复应用默认');
            if (str_contains($body, '{{') || str_contains($body, '}}')) throw new InvalidArgumentException('当前任务信息自动填入，请移除旧模板变量后再保存');
            $entry = ['mode' => 'custom', 'body' => str_replace(["\r\n", "\r"], "\n", $body)];
            self::sections($entry['body']); // Reject mistyped condition markers instead of silently dropping content.
        }
        unset($entry);
        if (strlen(json_encode($settings, JSON_UNESCAPED_UNICODE)) > 1000000) throw new InvalidArgumentException('提示词配置总量不能超过 1MB');
        return $settings;
    }

    private static function sections(string $body): array
    {
        $labels = array_flip(ShortDramaPromptCatalog::snapshot()['document_conditions'] ?? self::definition()['conditions']);
        $parts = preg_split('/^【适用：([^】]+)】\s*$/mu', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        $sections = [['condition' => 'always', 'text' => trim((string)array_shift($parts))]];
        for ($i = 0; $i < count($parts); $i += 2) {
            if (!isset($labels[$parts[$i]])) throw new InvalidArgumentException('无法识别适用条件：' . $parts[$i] . '；请保留默认条件标题，或将正文移到全部任务段落');
            $sections[] = ['condition' => $labels[$parts[$i]], 'text' => trim((string)($parts[$i + 1] ?? ''))];
        }
        return $sections;
    }

    private static function matches(string $condition, array $context): bool
    {
        $stage = $context['stage'] ?? '';
        $empty = !empty($context['empty']);
        $prop = !empty($context['prop']);
        return match ($condition) {
            'always' => true,
            'single' => empty($context['multi']),
            'multi' => !empty($context['multi']),
            'story', 'episodes' => $stage === $condition,
            'production' => !empty($context['multi']) && in_array($stage, ['production', 'revision'], true),
            'revision' => !empty($context['revision']),
            'repair' => $stage === 'repair',
            'repair_under' => $stage === 'repair' && !empty($context['under_count']),
            'character' => !$prop && !$empty,
            'prop' => $prop,
            'empty' => $empty,
            'has_subject' => !$empty,
            'multi_subject' => !$empty && ($context['subject_count'] ?? 0) > 1,
            'first_character' => !$empty && !empty($context['first_frame']),
            'first_empty' => $empty && !empty($context['first_frame']),
            'last' => !empty($context['last_frame']),
            'missing' => !empty($context['missing']),
            'missing_character' => !empty($context['missing']) && !$prop,
            'missing_prop' => !empty($context['missing']) && $prop,
            'missing_subject' => !empty($context['missing']) && !$empty,
            'missing_empty' => !empty($context['missing']) && $empty,
            default => false,
        };
    }

    public static function render(string $id, array $context = [], bool $includeDefault = false): string
    {
        $document = ShortDramaPromptCatalog::snapshot()['documents'][$id] ?? null;
        if (!$document || (!$includeDefault && !self::custom($id))) return '';
        $parts = [];
        foreach (self::sections($document['body']) as $section) {
            if ($section['text'] !== '' && self::matches($section['condition'], $context)) $parts[] = $section['text'];
        }
        $text = implode("\n\n", array_values(array_unique($parts)));
        if ($text !== '') ShortDramaPromptCatalog::recordDocument($id, $text, $document['source']);
        return $text;
    }

    /** Render the same configured creative document for a frozen Agent turn.
     * Unlike render(), this also supports the older per-rule workspace mode
     * without consulting mutable tenant settings after the workflow starts. */
    public static function renderSnapshot(array $snapshot,string $id,array $context=[]): string
    {
        if (!isset(self::definition()['documents'][$id])) throw new InvalidArgumentException('未知创作文档：'.$id);
        $body=(string)($snapshot['documents'][$id]['body']??'');
        if ($body==='') $body=self::body($id,(array)($snapshot['values']??ShortDramaPromptCatalog::defaults()));
        $parts=[];
        foreach (self::sections($body) as $section) {
            if ($section['text']!=='' && self::matches($section['condition'],$context)) $parts[]=$section['text'];
        }
        return implode("\n\n",array_values(array_unique($parts)));
    }

    /** Planning has no per-node subject/shot flags yet. Preserve each rule's
     * applicability instead of silently treating every future node as a
     * character shot with a first frame. Final submission still uses render(). */
    public static function renderSnapshotGuidance(array $snapshot, string $id, array $conditions): string
    {
        if (!isset(self::definition()['documents'][$id])) throw new InvalidArgumentException('未知创作文档：' . $id);
        $body = (string)($snapshot['documents'][$id]['body'] ?? '');
        if ($body === '') $body = self::body($id, (array)($snapshot['values'] ?? ShortDramaPromptCatalog::defaults()));
        $labels = self::definition()['conditions'];
        $allowed = array_fill_keys(array_merge(['always'], $conditions), true);
        $parts = [];
        foreach (self::sections($body) as $section) {
            $condition = $section['condition'];
            $content = $section['text'];
            if ($content === '' || !isset($allowed[$condition])) continue;
            $part = $condition === 'always' ? $content : '【仅适用：' . $labels[$condition] . "】\n" . $content;
            $parts[$part] = true;
        }
        return implode("\n\n", array_keys($parts));
    }

    public static function append(string $prompt, string $id, array $context = []): string
    {
        $text = self::render($id, $context);
        return $text === '' ? $prompt : rtrim($prompt) . "\n\n创作要求：\n" . $text;
    }

    /** Resolve per-document sources; application mode deliberately bypasses platform overrides. */
    public static function resolve(array $base, array $settings, array $platform = []): array
    {
        $docs = [];
        foreach (self::definition()['documents'] as $id => $spec) {
            $entry = $settings[$id] ?? ['mode' => 'inherit'];
            $source = 'application';
            if ($entry['mode'] === 'inherit' && ($platform['mode'] ?? '') === 'documents') {
                $entry = $platform['documents'][$id] ?? ['mode' => 'application'];
                $source = ($entry['mode'] ?? '') === 'custom' ? 'platform' : 'application';
            } elseif ($entry['mode'] === 'custom') $source = 'tenant';
            $mode = ($entry['mode'] ?? '') === 'custom' ? 'custom' : 'application';
            $docs[$id] = ['mode' => $mode, 'body' => $mode === 'custom' ? $entry['body'] : self::body($id), 'source' => $source];
        }
        $base['mode'] = 'documents';
        $base['format_version'] = self::FORMAT;
        $base['assembler_version'] = 1;
        $base['document_default_version'] = self::definition()['version'];
        $base['document_extra_defaults'] = self::definition()['extra_defaults'];
        $base['document_conditions'] = self::definition()['conditions'];
        $base['application_default_hash'] = hash('sha256', json_encode([ShortDramaPromptCatalog::definition(), self::definition()], JSON_UNESCAPED_UNICODE));
        $base['document_settings'] = $settings;
        $base['documents'] = $docs;
        $base['values'] = ShortDramaPromptCatalog::defaults();
        $base['sources'] = array_fill_keys(array_keys($base['values']), 'application');
        $base['legacy_config'] = [];
        $base['overrides'] = [];
        unset($base['fingerprint']);
        $base['fingerprint'] = hash('sha256', json_encode($base, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $base;
    }

    public static function migration(array $snapshot): array
    {
        if (($snapshot['mode'] ?? '') === 'documents') return ['settings' => $snapshot['document_settings'], 'issues' => [], 'required' => false];
        $values = $snapshot['values'];
        $legacy = $snapshot['legacy_config'] ?? [];
        $old = (array)($legacy['prompt_config'] ?? []);
        $overrides = array_replace(array_filter($values, static fn($v, $k) => $v !== ShortDramaPromptCatalog::defaults()[$k], ARRAY_FILTER_USE_BOTH), array_intersect_key($old, $values));
        $settings = [];
        foreach (self::definition()['documents'] as $id => $doc) {
            $keys = array_merge(...array_values($doc['sections']));
            $affected = array_intersect_key($overrides, array_flip($keys));
            $global = (string)($overrides['global_system_prompt'] ?? '');
            if ($affected !== [] || ($global !== '' && $id !== 'storyboard')) {
                $body = self::body($id, array_replace($values, $overrides));
                if ($global !== '' && $id !== 'storyboard') $body = $global . "\n\n" . $body;
                $settings[$id] = ['mode' => 'custom', 'body' => $body];
            }
        }
        $issues = [];
        foreach ($legacy as $key => $value) if ($key !== 'prompt_config' && is_string($value) && trim($value) !== '') $issues[] = ['key' => $key, 'text' => $value, 'message' => '旧完整系统消息／包装模板需按用途迁入文档；保留原文供核对'];
        foreach ($old as $key => $value) if (!array_key_exists($key, $values) && trim((string)$value) !== '' && $value !== '{{prompt}}') $issues[] = ['key' => $key, 'text' => $value, 'message' => '旧包装模板需确认其创作要求已迁入对应文档'];
        if (isset($overrides['music.negative'])) $issues[] = ['key' => 'music.negative', 'text' => $overrides['music.negative'], 'message' => '旧音乐负面字段未进入模型请求；如希望生效，请迁入背景音乐正文'];
        return ['settings' => $settings, 'issues' => $issues, 'required' => $settings !== [] || $issues !== [] || $legacy !== []];
    }

    public static function detail(array $snapshot): array
    {
        $migration = self::migration($snapshot);
        $effective = ($snapshot['mode'] ?? '') === 'documents' ? $snapshot : self::resolve($snapshot, $migration['settings']);
        $groups = [];
        foreach (self::definition()['groups'] as $id => $label) {
            $items = [];
            foreach (self::definition()['documents'] as $key => $doc) if ($doc['group'] === $id) {
                $specialTriggers = [];
                foreach (self::definition()['extra_defaults'] as $extra) {
                    if ($extra['document'] === $key && !empty($extra['trigger_note'])) $specialTriggers[] = $extra['trigger_note'];
                }
                $items[] = ['key' => $key, 'label' => $doc['label'], 'used_at' => $doc['used_at'], 'help' => $doc['help'], 'stage' => $doc['stage'], 'default' => self::body($key), 'special_triggers' => array_values(array_unique($specialTriggers))] + $effective['documents'][$key];
            }
            $groups[] = ['key' => $id, 'label' => $label, 'auxiliary' => $id === 'auxiliary', 'items' => $items];
        }
        return ['format_version' => self::FORMAT, 'revision' => $snapshot['revision'], 'fingerprint' => $snapshot['fingerprint'], 'mode' => $snapshot['mode'],
            'default_version' => self::definition()['version'], 'groups' => $groups, 'document_settings' => (object)$migration['settings'],
            'migration_required' => $migration['required'], 'migration_issues' => $migration['issues'],
            'legacy_config' => (object)($snapshot['legacy_config'] ?? []), 'protected_rules' => ShortDramaPromptCatalog::priority()];
    }
}
