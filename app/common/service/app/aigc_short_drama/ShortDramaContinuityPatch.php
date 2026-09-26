<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Additive narrative repair. Existing prose, media and shot identities are immutable. */
final class ShortDramaContinuityPatch
{
    public static function meaningMessages(array $plan, array $review, array $context = []): array
    {
        return ['system_prompt' => '你是证据核对员，只返回JSON。核对事实含义，不创作、不改写。原文出现不等于支持所声称的事实。',
            'content' => json_encode(['instructions' => [
                '逐条检查changes的after或hooks的description是否由指定镜头及其实际上下文支持。只用storyboard作为已发生的可视剧情证据。',
                '禁止用无关的真实引用证明事实，例如泼酒不能证明否认婚约，站在商铺不能证明获封郡主。复合状态的全部关键含义都须得到支持。',
                '区分已发生事实与伏笔：open伏笔只核对当前镜头是否提供合理铺垫，不要求预示的未来事件已经发生，也不要把合理情绪推断当作硬性错误。resolved伏笔须结合previous_open_hooks核对是否确实兑现。',
                'script_lines仅用于指出遗漏情节，不可替代镜头证据。若镜头未展示但script_lines明确记载，请在reason引用该原文，供局部补镜。',
                '每条changes、hooks以及source_patch.shot_insertions都必须返回一个检查项，index为零基索引，补镜的collection为insertions。不要省略、重复或新增检查项。',
                'insertions核对新增镜头是否忠实呈现source_quote的含义；允许影视化改写，不要求逐字相同，但不得杜撰新剧情或用无关镜头替代。',
            ], 'storyboard' => $plan['storyboard'] ?? [], 'script_lines' => $plan['script_lines'] ?? [],
                'review' => $review, 'previous_open_hooks' => $context['continuity']['open_hooks'] ?? [], 'source_patch' => $plan['_continuity_source_patch'] ?? [], 'response_contract' => ['checks' => [['collection' => 'changes、hooks或insertions', 'index' => 0, 'supported' => true, 'reason' => '证据与事实的对应关系或缺失原因']]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }

    public static function assertMeaning(array $review, array $response, array $sourcePatch = []): void
    {
        if (!is_array($response['checks'] ?? null) || !array_is_list($response['checks'])) throw new RuntimeException('连续性语义核对回包不完整', 422);
        $expected = [];
        foreach (['changes', 'hooks'] as $group) foreach ($review[$group] ?? [] as $index => $item) $expected[$group . ':' . $index] = true;
        foreach ($sourcePatch['shot_insertions'] ?? [] as $index => $item) $expected['insertions:' . $index] = true;
        $failures = [];
        foreach ($response['checks'] as $item) {
            if (!is_array($item) || !is_string($item['collection'] ?? null) || !is_int($item['index'] ?? null)
                || !is_bool($item['supported'] ?? null) || !is_string($item['reason'] ?? null)) throw new RuntimeException('连续性语义核对格式无效', 422);
            $key = $item['collection'] . ':' . $item['index'];
            if (!isset($expected[$key])) throw new RuntimeException('连续性语义核对索引重复或越界', 422);
            unset($expected[$key]);
            if (!$item['supported']) $failures[] = $item;
        }
        if ($expected) throw new RuntimeException('连续性语义核对遗漏事实', 422);
        if ($failures) throw new RuntimeException('连续性事实缺少匹配含义的镜头证据：' . json_encode($failures, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 460);
    }

    public static function messages(array $plan, array $context, array $audit, string $error, array $rule): array
    {
        return ['system_prompt' => '你是短剧局部一致性修复器，只返回JSON。不能整集重写，不能删除内容，不能根据审校猜测添加原文没有的剧情。',
            'content' => json_encode(['script' => $plan, 'context' => $context, 'audit' => $audit, 'error' => $error,
                'duration_rule' => ShortDramaShotDuration::modelRule($rule),
                'instructions' => [
                    '仅处理script_lines明确存在但分镜遗漏的剧情，以及本集实体ID与前集或已确认实体冲突。审校只是线索，不是事实来源。',
                    'entity_id_remaps只能重分配本集实体ID，名称、描述不变。新ID必须与本集、已确认设定、前集状态中的ID均不重复；所有本集镜头引用由服务端同步。不要把不同地点或道具强行合并。',
                    'shot_insertions仅新增必要镜头，after_shot_id引用原镜头ID（空字符串表示开头），不改已有镜头。每项必须引用script_lines的零基source_line_index，以及该行连续原文source_quote。新镜头允许忠实的影视化改写，不要求逐字复制，但不得杜撰新剧情；后续会单独核对原文与新镜头含义。',
                    '新增镜头shot_id用新的repair_前缀ID；scene_ref_id和subject_ref_ids引用重分配后的本集实体。提供完整画面、构图、运镜、动作、台词角色与时长，时长匹配实际内容，不使用默认5秒。',
                    '无法在这些边界内修复则返回空数组，不能用无关原文凑证据。最多8个新增镜头。',
                ],
                'response_contract' => [
                    'entity_id_remaps' => [['collection' => 'subjects或locations', 'from' => '本集原ID', 'to' => '未占用的新ID', 'name' => '本集原名称']],
                    'shot_insertions' => [['after_shot_id' => '原镜头ID', 'source_line_index' => 0, 'source_quote' => '连续原文',
                        'shot' => ['shot_id' => 'repair_1', 'scene_ref_id' => '场景ID', 'subject_ref_ids' => ['主体ID'],
                            'visual_description' => '包含来源原文的画面', 'composition' => '构图', 'camera_movement' => '运镜',
                            'action' => '动作', 'dialogue' => '台词或空串', 'voice_role' => '角色名或空串',
                            'speech_type' => 'character或narration或none', 'recommended_duration_seconds' => '按内容估算的数值']]],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }

    public static function apply(array $plan, array $patch, array $context, array $rule): array
    {
        if (array_diff(array_keys($patch), ['entity_id_remaps', 'shot_insertions'])
            || !is_array($patch['entity_id_remaps'] ?? null) || !array_is_list($patch['entity_id_remaps'])
            || !is_array($patch['shot_insertions'] ?? null) || !array_is_list($patch['shot_insertions'])
            || count($patch['shot_insertions']) > 8) throw new RuntimeException('局部连续性补丁格式无效', 422);
        $occupied = [];
        $collect = static function (array $value) use (&$collect, &$occupied): void {
            foreach ($value as $key => $item) {
                if ($key === 'id' && is_scalar($item)) $occupied[(string)$item] = true;
                if (is_array($item)) $collect($item);
            }
        };
        $collect($plan); $collect($context);
        foreach (array_keys((array)($context['continuity']['state'] ?? [])) as $key) $occupied[explode(':', $key, 2)[0]] = true;
        $maps = ['subjects' => [], 'locations' => []];
        foreach ($patch['entity_id_remaps'] as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['collection', 'from', 'to', 'name'])) throw new RuntimeException('实体补丁字段越界', 422);
            $group = $item['collection'] ?? ''; $from = $item['from'] ?? ''; $to = $item['to'] ?? '';
            if (!is_string($group) || !isset($maps[$group]) || !is_string($from) || !is_string($to) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $to)
                || isset($occupied[$to]) || isset($maps[$group][$from])) throw new RuntimeException('实体补丁ID冲突', 422);
            $found = false;
            foreach ($plan[$group] as &$entity) {
                if ((string)($entity['id'] ?? '') !== $from) continue;
                if (($entity['name'] ?? '') !== ($item['name'] ?? null) || $found) throw new RuntimeException('实体补丁名称或标识不匹配', 422);
                $entity['id'] = $to; $found = true;
            }
            unset($entity);
            if (!$found) throw new RuntimeException('实体补丁引用不存在', 422);
            $maps[$group][$from] = $to; $occupied[$to] = true;
        }
        foreach ($plan['storyboard'] as &$shot) {
            if (isset($maps['locations'][$shot['scene_ref_id'] ?? ''])) $shot['scene_ref_id'] = $maps['locations'][$shot['scene_ref_id']];
            if (isset($shot['subject_ref_ids'])) $shot['subject_ref_ids'] = array_map(static fn($id) => $maps['subjects'][$id] ?? $id, $shot['subject_ref_ids']);
        }
        unset($shot);
        $ids = array_fill_keys(array_map('strval', array_column($plan['storyboard'], 'shot_id')), true);
        $subjects = array_column($plan['subjects'], null, 'id'); $locations = array_column($plan['locations'], null, 'id');
        $insertions = []; $added = [];
        foreach ($patch['shot_insertions'] as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['after_shot_id', 'source_line_index', 'source_quote', 'shot'])) throw new RuntimeException('新增分镜补丁字段越界', 422);
            $anchor = $item['after_shot_id'] ?? null; $index = $item['source_line_index'] ?? null;
            $quote = $item['source_quote'] ?? null; $shot = $item['shot'] ?? null;
            if (!is_string($anchor) || ($anchor !== '' && !isset($ids[$anchor])) || !is_int($index)
                || !is_string($plan['script_lines'][$index] ?? null) || !is_string($quote) || trim($quote) === ''
                || !str_contains($plan['script_lines'][$index], $quote) || !is_array($shot)) throw new RuntimeException('新增分镜缺少可验证原文或位置', 422);
            $allowed = ['shot_id', 'scene_ref_id', 'subject_ref_ids', 'visual_description', 'composition', 'camera_movement', 'action', 'dialogue', 'voice_role', 'speech_type', 'recommended_duration_seconds'];
            $id = $shot['shot_id'] ?? '';
            if (array_diff(array_keys($shot), $allowed) || !is_string($id) || !preg_match('/^repair_[a-zA-Z0-9_]{1,50}$/D', $id)
                || isset($ids[$id]) || isset($added[$id])) throw new RuntimeException('新增分镜标识或字段无效', 422);
            foreach (['visual_description', 'dialogue', 'voice_role', 'speech_type'] as $key) if (!is_string($shot[$key] ?? null)) throw new RuntimeException('新增分镜正文不完整', 422);
            if (trim($shot['visual_description']) === '') throw new RuntimeException('新增分镜画面不能为空', 422);
            if (!is_string($shot['scene_ref_id'] ?? null) || !isset($locations[$shot['scene_ref_id']]) || !is_array($shot['subject_ref_ids'] ?? null)
                || !array_is_list($shot['subject_ref_ids']) || count(array_filter($shot['subject_ref_ids'], 'is_string')) !== count($shot['subject_ref_ids'])
                || array_diff($shot['subject_ref_ids'], array_keys($subjects))) throw new RuntimeException('新增分镜引用无效', 422);
            if (!ShortDramaShotDuration::contains($shot['recommended_duration_seconds'] ?? null, $rule)) throw new RuntimeException('新增分镜时长不符合规则', 422);
            $insertions[$anchor][] = $shot; $added[$id] = true;
        }
        $shots = $insertions[''] ?? [];
        foreach ($plan['storyboard'] as $shot) {
            $shots[] = $shot;
            foreach ($insertions[(string)$shot['shot_id']] ?? [] as $addedShot) $shots[] = $addedShot;
        }
        $plan['storyboard'] = $shots;
        return ['plan' => $plan, 'added_ids' => array_keys($added), 'changed' => (bool)($added || $patch['entity_id_remaps'])];
    }
}
