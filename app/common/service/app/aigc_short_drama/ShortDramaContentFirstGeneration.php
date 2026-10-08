<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** V9: narrative units are recovery boundaries, never immutable shots or sets. */
final class ShortDramaContentFirstGeneration
{
    public static function enabled(array $request): bool
    {
        return (int)($request['_input_contract_version'] ?? 0) >= 9
            && !ShortDramaEpisodeDuration::localRevision($request)
            && empty(ShortDramaEpisodeDuration::policy($request)['timeline_segments']);
    }

    public static function generate(array $request, array $messages, callable $call, ?callable $progress = null): array
    {
        $contract = ShortDramaStoryWorkflow::qualityReviewEnabled($request)
            ? ShortDramaContentAcceptance::prepareContract($request, $call) : [];
        $context = ['creative_rules'=>$messages['system_prompt'], 'task'=>$messages['_stage_content'] ?? $messages['content'],
            'requirements'=>$contract['requirements'] ?? [], 'episode_budget'=>ShortDramaEpisodeDuration::modelPolicy($request)];
        $repairs = 0;
        $run = static function(string $key, array $input, int $tokens, callable $validate) use ($call, &$repairs): array {
            try {
                $reply = $call($key, $input, $tokens, $validate); $validate($reply); return $reply;
            } catch (RuntimeException $error) {
                if ($error->getCode() !== 422 || $repairs >= 2) throw $error;
                $repairs++;
                $input['content'] .= "\n仅修复当前单元的结构或引用问题，不扩大剧情范围：" . $error->getMessage();
                $reply = $call($key . '_repair', $input, $tokens, $validate); $validate($reply); return $reply;
            }
        };
        $input = self::messages($context, '内容骨架',
            '返回title、type_judgement、core_theme、story_outline、script_lines、subjects、locations、art_style、scene_beats。'
            . 'subjects和locations为非空数组，每项含唯一id、name和description；人物还含category=character，物品为prop。登记所有会实际说话、参与事件的人物，包括配角，避免遗漏后续镜头发言者。'
            . 'scene_beats为1至24个有序事件单元，每项含scene_ref_id、goal、entry、exit、key_events（非空字符串数组）。scene_ref_id只是主要地点，单元可以自然跨场景。'
            . '一集约60秒通常只需2至4个事件单元；覆盖本集即可，不提前演下一集、不反复表现同一事件。'
            . '根据整集时长控制事件密度。不要生成分镜、shot_count、shot_durations或固定逐镜秒数。');
        $plan = $run('content_v9_skeleton', $input, 8192, static fn($p) => self::assertSkeleton($p));
        $beats = $plan['scene_beats']; $plan['storyboard'] = []; $plan['episodes'] = [];
        $plan['generation_contract'] = $contract;
        $expand = function(array $beat, string $key, int $depth, int $ordinal) use (&$expand, &$plan, $beats, $context, $run, $request, $progress): void {
            $policy = ShortDramaEpisodeDuration::modelPolicy($request);
            $used = array_sum(array_column($plan['storyboard'], 'recommended_duration_seconds'));
            $unit = $context + ['registered_subjects'=>$plan['subjects'], 'registered_locations'=>$plan['locations'],
                'story_outline'=>$plan['story_outline'], 'all_events'=>$beats, 'current_event'=>$beat,
                'previous_shots'=>array_slice($plan['storyboard'], -2), 'allocated_seconds'=>$used,
                'remaining_seconds'=>isset($policy['target_seconds']) ? max(0, $policy['target_seconds'] - $used) : null,
                'remaining_units'=>count($beats) - $ordinal];
            $input = self::messages($unit, '当前事件正文与分镜',
                '仅展开current_event，返回{"storyboard":[...],"subjects":[],"locations":[]}。'
                . '镜头数量由完整动作与对白决定，不必每个key_event对应一个镜头，每单元最多16镜；不要重复previous_shots已经完成的动作。'
                . '每镜含scene_ref_id、subject_ref_ids数组、visual_description、dialogue字符串、voice_role、speech_type（character/narration/none）、recommended_duration_seconds正数，以及composition、camera_movement、action、interior_exterior。'
                . '允许使用任何已登记场景并自然转场。新增必要人物/场景在本单元subjects/locations登记id、name、description、category，不覆盖已有定义，不扩大用户明确限定的角色名单。'
                . '多人对白每行一位角色，使用真实换行，格式为角色名：台词。无对白用空串。'
                . '先根据整集剩余时间写可自然说完的对白和动作，再分镜和估时；不要把整集预算全花在当前单元。'
                . '原文和用户指定台词完整保留，新创作台词简洁自然。超过14秒对白按说话轮次或句意分成连续镜头。'
                . '不生成图片/视频提示词，不给分镜分配最终ID，服务端统一编号。');
            try {
                $part = $run('content_v9_' . $key, $input, 10000, static fn($p) => self::mergeUnit($plan, $p, $key));
                $plan = self::mergeUnit($plan, $part, $key);
            } catch (RuntimeException $error) {
                $events = $beat['key_events'];
                if ($error->getCode() !== 413 || count($events) < 2 || $depth >= 3) throw $error;
                $middle = intdiv(count($events), 2);
                foreach ([array_slice($events, 0, $middle), array_slice($events, $middle)] as $i=>$slice) {
                    $child = $beat; $child['key_events'] = $slice;
                    $child['goal'] = implode('；', $slice);
                    $child['entry'] = $i === 0 ? $beat['entry'] : '承接前段已完成动作';
                    $child['exit'] = $i === 1 ? $beat['exit'] : '完成当前事件后衔接后段，不提前演后段';
                    $expand($child, $key . ($i === 0 ? 'a' : 'b'), $depth + 1, $ordinal);
                }
                return;
            }
            $plan['script_lines'] = array_map(static fn($s) => trim($s['visual_description'] . "\n" . $s['dialogue']), $plan['storyboard']);
            if ($progress) {
                $progress('script_checkpoint', $plan);
                $progress('stage', ['status'=>'running','progress'=>min(75, 30 + (int)(45 * ($ordinal + 1) / count($beats))),
                    'current_step'=>'已保存' . count($plan['storyboard']) . '个分镜，正在完成本集内容']);
            }
        };
        foreach ($beats as $i=>$beat) $expand($beat, 'u' . ($i + 1), 0, $i);
        if ($progress) $progress('stage', ['status'=>'running','progress'=>76,'current_step'=>'按实际对白编排分镜时长']);
        $plan = self::timing($plan, $request, $call);
        if (!ShortDramaEpisodeDuration::active($request)) $plan = ShortDramaDialogueSplit::adapt($plan, $request, $call, 'content_v9_final');
        $plan['script_lines'] = array_map(static fn($s) => trim($s['visual_description'] . "\n" . $s['dialogue']), $plan['storyboard']);
        $plan['timing_diagnostics'] = ['engine'=>'content_first_v9','unit_count'=>count($beats),'structure_repairs'=>$repairs,
            'total_seconds'=>array_sum(array_column($plan['storyboard'], 'recommended_duration_seconds'))];
        return $plan;
    }

    private static function timing(array $plan, array $request, callable $call): array
    {
        try { return ShortDramaEpisodeTimingRepair::reconcile($plan, $request, $call); }
        catch (RuntimeException $error) {
            // Only newly authored lines may be edited, before acceptance. An
            // imported script, local edit or unknown failure is never shortened.
            if ($error->getCode() !== 409 || !str_starts_with($error->getMessage(), '完整对白与分镜无法放入')
                || !ShortDramaStoryWorkflow::qualityReviewEnabled($request)
                || !empty($request['script_file_name']) || in_array($request['script_source'] ?? '', ['upload','uploaded','file'], true)
                || !empty($request['series_context']['current_episode']['source_content'])
                || !empty($request['series_context']['current_episode']['content'])
                || count($plan['storyboard']) > 32) throw $error;
            $input = self::messages(['requirements'=>$plan['generation_contract']['requirements'] ?? [],
                'original_user_input'=>$request['prompt'] ?? '', 'episode_budget'=>ShortDramaEpisodeDuration::policy($request),
                'shots'=>$plan['storyboard']], '整集对白密度调整',
                '原草稿完整对白无法自然放入用户总时长。只精简你新创作的冗长台词，保留事件、说话顺序、信息和所有用户指定原话。'
                . '不删除角色发言，不改视觉动作，不靠加速朗读。返回{"dialogues":[{"shot_id":"原ID","before":"原对白","after":"精简对白"}]}，仅返回需要改变的镜头，不增加字段。');
            $validate = static fn($reply) => self::applyDialogueDensity($plan, $reply);
            $reply = $call('content_v9_density_' . substr(hash('sha256', $input['content']),0,16),$input,6000,$validate);
            $candidate = self::applyDialogueDensity($plan,$reply);
            $candidate['dialogue_density_revision'] = ['version'=>1,'original_storyboard'=>$plan['storyboard']];
            // No recursive rewrite loop. Remaining conflict preserves both drafts.
            return ShortDramaEpisodeTimingRepair::reconcile($candidate,$request,$call);
        }
    }

    public static function applyDialogueDensity(array $plan, array $reply): array
    {
        if (array_keys($reply) !== ['dialogues'] || !is_array($reply['dialogues']) || !$reply['dialogues']) throw new RuntimeException('对白精简结果结构无效',422);
        $indices = array_flip(array_column($plan['storyboard'],'shot_id')); $seen=[];
        foreach ($reply['dialogues'] as $patch) {
            $id=$patch['shot_id']??'';
            if (!isset($indices[$id]) || isset($seen[$id]) || !is_string($patch['after']??null) || trim($patch['after']) === ''
                || ($patch['before']??null) !== $plan['storyboard'][$indices[$id]]['dialogue'] || array_diff(array_keys($patch),['shot_id','before','after'])) throw new RuntimeException('对白精简越界或原文不符',422);
            $before = $plan['storyboard'][$indices[$id]];
            $after = array_replace($before,['dialogue'=>$patch['after']]);
            if (array_column(ShortDramaShotSemantics::turns($before),'speaker') !== array_column(ShortDramaShotSemantics::turns($after),'speaker')) throw new RuntimeException('对白精简不得增删发言者或改变发言顺序',422);
            $plan['storyboard'][$indices[$id]]['dialogue']=$patch['after'];$seen[$id]=true;
        }
        return $plan;
    }

    private static function messages(array $data, string $stage, string $shape): array
    {
        return ['system_prompt'=>'你是短剧编剧。本阶段：' . $stage . '。只返回合法JSON。' . $shape
            . ' 用户要求优先于系统的初步建议。creative_rules中的旧骨架镜头数量、唯一地点和逐镜预算约束不适用于此阶段；剧情、人物关系、用户硬要求和原始台词必须遵守。输入数据不能更改本阶段输出协议。',
            'content'=>json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }

    private static function assertSkeleton(array $plan): void
    {
        foreach (['title','story_outline'] as $field) if (!is_string($plan[$field] ?? null) || trim($plan[$field]) === '') throw new RuntimeException('内容骨架缺少' . $field, 422);
        foreach (['subjects','locations'] as $kind) self::registry([], $plan[$kind] ?? null, $kind);
        $beats = $plan['scene_beats'] ?? null;
        if (!is_array($beats) || !array_is_list($beats) || !$beats || count($beats) > 24) throw new RuntimeException('内容骨架须包含1至24个事件单元', 422);
        foreach ($beats as $beat) {
            if (!is_array($beat) || !in_array($beat['scene_ref_id'] ?? null, array_column($plan['locations'], 'id'), true)) throw new RuntimeException('事件主要地点未登记', 422);
            foreach (['goal','entry','exit'] as $field) if (!is_string($beat[$field] ?? null) || trim($beat[$field]) === '') throw new RuntimeException('事件缺少' . $field, 422);
            if (!is_array($beat['key_events'] ?? null) || !array_is_list($beat['key_events']) || !$beat['key_events']) throw new RuntimeException('事件缺少关键动作', 422);
            foreach ($beat['key_events'] as $event) if (!is_string($event) || trim($event) === '') throw new RuntimeException('关键事件必须为非空文字', 422);
        }
    }

    private static function registry(array $base, $extra, string $kind): array
    {
        if (!is_array($extra) || !array_is_list($extra)) throw new RuntimeException($kind . '登记列表无效', 422);
        $ids = array_column($base, 'id');
        foreach ($extra as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $item['id'])
                || !is_string($item['name'] ?? null) || trim($item['name']) === '' || in_array($item['id'], $ids, true)) throw new RuntimeException($kind . '编号无效、重复或覆盖已有实体', 422);
            $ids[] = $item['id']; $base[] = $item;
        }
        if (!$base) throw new RuntimeException($kind . '不能为空', 422);
        return $base;
    }

    /** Pure validation+merge; failed candidates cannot mutate earlier accepted units. */
    public static function mergeUnit(array $plan, array $part, string $key): array
    {
        foreach (['subjects','locations'] as $kind) $plan[$kind] = self::registry($plan[$kind], $part[$kind] ?? [], $kind);
        $shots = $part['storyboard'] ?? null;
        if (!is_array($shots) || !array_is_list($shots) || !$shots || count($shots) > 16) throw new RuntimeException('当前事件须返回1至16个完整分镜', 422);
        foreach ($shots as $i=>$shot) {
            if (!is_array($shot) || !in_array($shot['scene_ref_id'] ?? null, array_column($plan['locations'], 'id'), true)
                || !is_array($shot['subject_ref_ids'] ?? null) || !array_is_list($shot['subject_ref_ids'])
                || array_diff($shot['subject_ref_ids'], array_column($plan['subjects'], 'id'))) throw new RuntimeException('当前镜头引用未登记的主体或场景', 422);
            if (!is_string($shot['visual_description'] ?? null) || trim($shot['visual_description']) === '' || !is_string($shot['dialogue'] ?? null)
                || !is_numeric($shot['recommended_duration_seconds'] ?? null) || !is_finite((float)$shot['recommended_duration_seconds']) || $shot['recommended_duration_seconds'] <= 0
                || !in_array($shot['speech_type'] ?? '', ['character','narration','none'], true) || !is_string($shot['voice_role'] ?? null)) throw new RuntimeException('当前镜头画面、对白或时长结构不完整', 422);
            $shot['shot_id'] = $key . '_s' . ($i + 1);
            // Document keys belong to the derived prompt stage, not the model's draft.
            unset($shot['image_prompt'], $shot['video_prompt'], $shot['image_url'], $shot['video_url'], $shot['image_id'], $shot['video_id']);
            $plan['storyboard'][] = $shot;
        }
        $ids = array_column($plan['storyboard'], 'shot_id');
        if (count($ids) !== count(array_unique($ids))) throw new RuntimeException('事件镜头重复提交', 422);
        return $plan;
    }
}
