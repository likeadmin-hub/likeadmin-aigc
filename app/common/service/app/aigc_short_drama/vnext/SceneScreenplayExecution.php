<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use app\common\service\app\aigc_short_drama\ShortDramaOutputLimitException;
use RuntimeException;

/** A received output limit may replace one creative call with a frozen, bounded scene plan. */
final class SceneScreenplayExecution
{
    public const VERSION = 1;

    public static function run(array $context, callable $call, callable $checkpoint, array $execution): array
    {
        $plan = ($execution['screenplay_plan'])($context, null);
        if ($plan === null) {
            try {
                // Keep the successful short-episode request and stage key exactly as before.
                return $call('screenplay', GenerationPipeline::creativeContext($context), GenerationPipeline::screenplayInstruction($context),
                    static fn($reply) => ContentDocument::normalize($reply, $context));
            } catch (ShortDramaOutputLimitException $error) {
                if (($context['source_mode'] ?? '') === 'faithful') throw $error;
            }
            $proposal = $call('screenplay_plan', ['context' => GenerationPipeline::creativeContext($context), 'target_ids' => self::targets($context)],
                '完整正文超过输出容量。只制定本集有序分场计划，不输出正文、不改写用户要求、不演出后续集。'
                . '返回{title,entities:[{ref,kind:character|location|prop,name,description,identity_facts}],scenes:[{location,time_context,brief,target_ids:[]}]}。'
                . '按自然剧情边界安排2至32场，每场brief写清该场起因、推进与结束状态，控制单场篇幅。'
                . 'entities一次登记全部必要新人物、场景、道具，优先沿用context.entities；之后不允许更换登记身份。'
                . '每场使用全局地点ref。target_ids仅使用系统给出的本集要求/事件ID，整体须覆盖全部ID，可跨场承接。'
                . '通用人物/风格要求仍对每场有效；分配ID仅是创作计划，不是已完成的证据。原文、对白及修改意见仍以完整context为准。'
                . GenerationPipeline::historyInstruction($context), static fn($reply) => self::plan($context, $reply));
            $plan = ($execution['screenplay_plan'])($context, $proposal);
        }
        self::assertPlan($context, $plan);
        $raw = ['title' => $plan['title'], 'entities' => $plan['entities'], 'scenes' => []];
        $trace = [];
        foreach ($plan['scenes'] as $index => $scene) {
            $number = $index + 1; $key = 'screenplay_scene_' . $number;
            $part = $call($key, ['context' => GenerationPipeline::creativeContext($context), 'scene_plan' => $plan,
                'scene_number' => $number, 'previous_screenplay' => $raw],
                '只创作scene_number指定的一场完整正文。previous_screenplay是已经保存的全部前文，只读，不重复、不改写、不续写其他场。'
                . '返回{scene_number,scene:{location,time_context,units:[{type:action,text,characters:[],props:[]},{type:dialogue,speaker,speech_type:spoken|voiceover|narration,text}]}}。'
                . 'location及time_context逐字沿用本场计划。只能引用context.entities与scene_plan.entities中已登记的全局短别名，不新建或重命名人物、地点、道具。'
                . '完整实现该场brief及对应要求/事件，保留指定原文对白，承接完整前文及人物认知，不把计划概要当作正文，不截断本场结尾。'
                . '可使用source_links标注context.sources中的真实逐字引用，不伪造来源；叙述旁白speech_type=narration无需speaker。'
                . GenerationPipeline::historyInstruction($context),
                static function ($reply) use ($context, $raw, $number, $scene): array {
                    if (array_diff(array_keys($reply), ['scene_number', 'scene']) || ($reply['scene_number'] ?? null) !== $number
                        || !is_array($reply['scene'] ?? null)) throw new RuntimeException('分场正文序号或结构无效', 422);
                    $part = $reply['scene'];
                    if (array_diff(array_keys($part), ['location', 'time_context', 'units'])
                        || ($part['location'] ?? null) !== $scene['location'] || ($part['time_context'] ?? null) !== $scene['time_context']) {
                        throw new RuntimeException('分场正文不能改变已冻结场次', 422);
                    }
                    $merged = $raw; $merged['scenes'][] = $part;
                    return ['scene' => $part, 'document' => ContentDocument::normalize($merged, $context)];
                });
            $raw['scenes'][] = $part['scene']; $document = $part['document'];
            $canonicalScene = $document['scenes'][$index];
            $trace[] = ['code' => 'scene_creation', 'plan_hash' => $plan['plan_hash'], 'stage_key' => $key,
                'scene_number' => $number, 'scene_id' => $canonicalScene['scene_id'],
                'target_ids' => $scene['target_ids'], 'unit_ids' => array_column($canonicalScene['units'], 'unit_id')];
            // These are draft fragments. Only the normal full-document shot/review pipeline can publish.
            $document['transforms'] = array_merge($document['transforms'], $trace);
            $checkpoint(['document' => $document, 'screenplay_windows' => ['plan_hash' => $plan['plan_hash'],
                'completed' => $number, 'total' => count($plan['scenes']), 'complete' => $number === count($plan['scenes'])]]);
        }
        return $document;
    }

    /** Normalize plan data once, independently of model-supplied hashes or IDs. */
    public static function plan(array $context, array $reply): array
    {
        if (array_diff(array_keys($reply), ['title', 'entities', 'scenes'])) throw new RuntimeException('分场计划包含未授权字段', 422);
        $title = ContentDocument::text($reply['title'] ?? null, 'plan.title');
        $entities = ContentDocument::rows($reply['entities'] ?? [], 'plan.entities');
        $scenes = ContentDocument::rows($reply['scenes'] ?? [], 'plan.scenes');
        if (count($scenes) < 2 || count($scenes) > 32) throw new RuntimeException('分场计划须有2至32场', 422);
        $targets = self::targets($context); $assigned = []; $probeScenes = [];
        foreach ($scenes as &$scene) {
            if (array_diff(array_keys($scene), ['location', 'time_context', 'brief', 'target_ids'])) throw new RuntimeException('分场计划场次字段无效', 422);
            ContentDocument::text($scene['location'] ?? null, 'scene.location');
            ContentDocument::text($scene['brief'] ?? null, 'scene.brief');
            if (!isset($scene['time_context']) || !is_string($scene['time_context'])) throw new RuntimeException('分场计划时间无效', 422);
            $ids = $scene['target_ids'] ?? null;
            if (!is_array($ids) || ($ids && array_keys($ids) !== range(0, count($ids) - 1))) throw new RuntimeException('分场计划缺少要求分配', 422);
            $seen = [];
            foreach ($ids as $id) {
                if (!is_string($id) || !in_array($id, $targets, true) || isset($seen[$id])) throw new RuntimeException('分场计划要求标识无效或重复', 422);
                $seen[$id] = true; $assigned[$id] = true;
            }
            // Probe only identity/location resolution. Synthetic units are never saved or sent to a model.
            $probeScenes[] = ['location' => $scene['location'], 'time_context' => $scene['time_context'],
                'units' => [['type' => 'dialogue', 'speech_type' => 'narration', 'text' => '计划结构验证']]];
        }
        unset($scene);
        if (array_diff($targets, array_keys($assigned))) throw new RuntimeException('分场计划遗漏本集要求或事件', 422);
        ContentDocument::normalize(['title' => $title, 'entities' => $entities, 'scenes' => $probeScenes], $context);
        $plan = ['schema_version' => self::VERSION, 'context_hash' => ContentDocument::hash($context),
            'title' => $title, 'entities' => $entities, 'scenes' => $scenes];
        $plan['plan_hash'] = ContentDocument::hash($plan);
        return $plan;
    }

    public static function assertPlan(array $context, array $plan): void
    {
        if (($context['source_mode'] ?? '') === 'faithful' || isset($context['canonical_input']) || isset($context['structured_screenplay'])) {
            throw new RuntimeException('受保护正文不能分场重新创作', 409);
        }
        try { $expected = self::plan($context, array_intersect_key($plan, array_flip(['title', 'entities', 'scenes']))); }
        catch (RuntimeException $error) { throw new RuntimeException('已保存分场计划无效', 409, $error); }
        if (ContentDocument::hash($plan) !== ContentDocument::hash($expected)) throw new RuntimeException('分场计划与冻结输入不符', 409);
    }

    private static function targets(array $context): array
    {
        $ids = [];
        foreach (array_merge($context['requirements'] ?? [], $context['events'] ?? []) as $target) {
            if (!empty($target['episode_ids']) && !in_array($context['episode_id'], $target['episode_ids'], true)) continue;
            $id = ContentDocument::text($target['id'] ?? null, 'target.id');
            if (in_array($id, $ids, true)) throw new RuntimeException('本集要求标识重复', 422);
            $ids[] = $id;
        }
        return $ids;
    }
}
