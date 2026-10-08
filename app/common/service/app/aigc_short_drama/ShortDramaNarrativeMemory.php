<?php
namespace app\common\service\app\aigc_short_drama;

/** Provider-facing working memory. The persisted ledger is never modified here. */
final class ShortDramaNarrativeMemory
{
    public const VERSION = 1;
    public const INSTRUCTION = '剧情记忆是按当前任务检索的局部视图，不是完整历史。未显示不代表不存在或已经失效；不得以检索遗漏为依据改写正文或回收伏笔。状态与伏笔使用已有准确标识；核验具体记录时服务器会补齐其原值。';

    private static function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function terms(string $text): array
    {
        $text = mb_strtolower(preg_replace('/[\s\p{P}]+/u', '', $text) ?? $text);
        $terms = [];
        for ($i = 0, $n = mb_strlen($text) - 1; $i < $n; $i++) $terms[mb_substr($text, $i, 2)] = true;
        return $terms;
    }

    /** Whole records only. Exact references are mandatory even if they require subdivision. */
    public static function select(array $records, string $query, int $budget, array $required = []): array
    {
        $selected = []; $used = 0;
        foreach (array_unique($required) as $key) if (array_key_exists($key, $records)) {
            $selected[$key] = $records[$key]; $used += mb_strlen(self::json([$key=>$records[$key]]));
        }
        $terms = self::terms($query); $ranked = [];
        foreach ($records as $key => $value) {
            if (array_key_exists($key, $selected)) continue;
            $text = (string)$key . ' ' . self::json($value);
            $ranked[] = ['key'=>$key, 'size'=>mb_strlen(self::json([$key=>$value])),
                'score'=>count(array_intersect_key($terms, self::terms($text)))];
        }
        usort($ranked, static fn($a,$b) => ($b['score'] <=> $a['score']) ?: strcmp((string)$a['key'], (string)$b['key']));
        foreach ($ranked as $item) {
            if ($used + $item['size'] > $budget) continue;
            $selected[$item['key']] = $records[$item['key']]; $used += $item['size'];
        }
        return $selected;
    }

    /** Do not rank historical facts against themselves or against output-schema instructions. */
    public static function query(array $data): string
    {
        $parts = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['continuity','previous','previous_state','previous_open_hooks','previous_episodes','history',
                'response_contract','confirmed','outline','series_roadmap','memory_scope'], true)) continue;
            if (is_array($value)) $parts[] = self::query($value);
            elseif (is_string($value)) {
                $nested = in_array($key, ['creative_context','task'], true) ? json_decode($value, true) : null;
                $parts[] = is_array($nested) ? self::query($nested) : $value;
            }
        }
        return implode(' ', $parts);
    }

    public static function dependency(array $continuity, string $query): string
    {
        $view = self::continuity($continuity, $query);
        // A retrieval miss is not proof of irrelevance. Any authoritative fact
        // change invalidates the dependency; only unchanged facts can skip review.
        $view['authoritative_state'] = $continuity['state'] ?? [];
        $view['authoritative_hooks'] = $continuity['open_hooks'] ?? [];
        // Provenance identifies sources but is not itself a narrative change.
        unset($view['memory_scope'], $view['previous_digest'], $view['version']);
        foreach (['history','recent_episodes'] as $field) foreach ((array)($view[$field] ?? []) as $i => $episode) {
            unset($episode['digest']); $view[$field][$i] = $episode;
        }
        $sort = static function(array $data) use (&$sort): array {
            ksort($data);
            foreach ($data as &$value) if (is_array($value)) $value = $sort($value);
            unset($value); return $data;
        };
        return hash('sha256', self::json($sort($view)));
    }

    public static function continuity(array $source, string $query, int $budget = 10000, array $review = []): array
    {
        $out = ShortDramaPlanningContext::continuityFacts($source);
        $requiredState = array_map(static fn($r) => ($r['entity_id'] ?? '') . ':' . ($r['field'] ?? ''), $review['changes'] ?? []);
        $requiredHooks = array_map(static fn($r) => preg_replace('/_resolved$/', '', (string)($r['id'] ?? '')), $review['hooks'] ?? []);
        $requiredHooks = array_merge($requiredHooks, array_column($review['hooks'] ?? [], 'id'));
        foreach (['state'=>.55, 'open_hooks'=>.25, 'history'=>.2] as $field => $share) {
            if (!isset($source[$field])) continue;
            $out[$field] = self::select((array)$source[$field], $query, (int)($budget * $share),
                $field === 'state' ? $requiredState : ($field === 'open_hooks' ? $requiredHooks : []));
        }
        // The full ledger remains on the server, including records not selected here.
        unset($out['fact_sources']);
        $out['memory_scope'] = ['version'=>self::VERSION, 'instruction'=>self::INSTRUCTION];
        foreach (['state','open_hooks','history'] as $field) {
            $out['memory_scope'][$field] = ['total'=>count($source[$field] ?? []), 'included'=>count($out[$field] ?? [])];
        }
        return $out;
    }

    /** Only machine-built JSON prefixes are projected. User text and repair suffixes stay exact. */
    public static function messages(array $messages, int $budget = 10000): array
    {
        foreach (['content','_stage_content'] as $field) {
            $text = $messages[$field] ?? null;
            if (!is_string($text) || !str_starts_with($text, '{')) continue;
            try { $data = ShortDramaStructuredResponse::decode(['content'=>$text]); }
            catch (\RuntimeException) { continue; }
            $prefix = self::json($data);
            if (!str_starts_with($text, $prefix)) continue;
            $query = self::query($data);
            $walk = static function(array $node) use (&$walk, $query, $budget): array {
                $review = (array)($node['review'] ?? []);
                if (isset($node['previous_state']) || isset($node['previous_open_hooks'])) {
                    $view = self::continuity(['state'=>$node['previous_state'] ?? [], 'open_hooks'=>$node['previous_open_hooks'] ?? []], $query, $budget, $review);
                    $node['previous_state'] = $view['state']; $node['previous_open_hooks'] = $view['open_hooks'];
                    $node['memory_scope'] = $view['memory_scope'];
                }
                foreach ($node as $key => &$value) {
                    if (in_array($key, ['continuity','previous'], true) && is_array($value)
                        && (isset($value['state']) || isset($value['open_hooks']))) $value = self::continuity($value, $query, $budget, $review);
                    elseif (in_array($key, ['creative_context','task'], true) && is_string($value)) {
                        $nested = json_decode($value, true);
                        if (is_array($nested)) $value = self::json($walk($nested));
                    } elseif (is_array($value) && !in_array($key, ['previous_state','previous_open_hooks','memory_scope','history'], true)) $value = $walk($value);
                }
                unset($value);
                if (isset($node['continuity'], $node['previous_episodes'])) unset($node['previous_episodes']);
                return $node;
            };
            $messages[$field] = self::json($walk($data)) . substr($text, strlen($prefix));
        }
        return $messages;
    }
}
