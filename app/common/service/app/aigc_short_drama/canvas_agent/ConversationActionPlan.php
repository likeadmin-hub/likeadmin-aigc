<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/**
 * A deliberately small, model-produced canvas proposal format.
 *
 * This is not a provider tool protocol: the model can only propose up to four
 * new text/image/video nodes.  The server owns IDs, models, graph writes and
 * all later task submission.  Plain conversational replies remain plain
 * replies, which keeps a question from accidentally becoming a paid task.
 */
final class ConversationActionPlan
{
    private const OPEN = '<canvas-actions>';
    private const CLOSE = '</canvas-actions>';

    public static function instruction(string $mode,string $workflowStage=''): string
    {
        if ($workflowStage==='script') return "当前为剧本与角色设定阶段。答复先用清晰的短剧排版说明结论，再在末尾输出一次严格 JSON：<canvas-actions>{\"nodes\":[...]}</canvas-actions>。nodes 只能是 text，artifact 只能是 story_setting、episode_outline 或 storyboard_script，至少创建故事设定和分集大纲；每项的 prompt 是可直接阅读的结构化正文，必须覆盖 project_title、logline、world_setting、character_profiles、episode_outline、scene_script 或 storyboard_script 中与该节点匹配的字段。每项只允许 type、artifact、title、prompt、key、depends_on；不得输出模型、价格、URL、素材 ID、任务状态或任意画布 JSON。";
        if ($workflowStage==='art') return "当前为画风与美术规划阶段。答复先用清晰的短剧排版说明结论，再在末尾输出一次严格 JSON：<canvas-actions>{\"nodes\":[...]}</canvas-actions>。nodes 只能是 text，artifact 只能是 art_bible、character_asset_spec、scene_asset_spec、prop_asset_spec、subject_image_prompt、three_view_prompt、scene_image_prompt 或 storyboard_image_prompt。每项 prompt 是可直接阅读的结构化正文，必须覆盖 art_bible、角色/场景/道具资产说明和后续生图提示词。每项只允许 type、artifact、title、prompt、key、depends_on；不得输出模型、价格、URL、素材 ID、任务状态或任意画布 JSON。";
        if ($workflowStage==='video_nodes') return "当前为分镜视频节点阶段。只在答复末尾输出一次严格 JSON 包裹 <canvas-actions>{\"nodes\":[...]}</canvas-actions>；nodes 只能是 video，数量 1 至 60，artifact 必须是 storyboard_video，全部为待用户生成的分镜视频节点。每项只允许 type、artifact、title、prompt、key、depends_on；不得声明价格、模型、URL、素材 ID 或任务状态。";
        if ($workflowStage==='audio_plan') return "当前为音频规划阶段。只在答复末尾输出一次严格 JSON 包裹 <canvas-actions>{\"nodes\":[{\"type\":\"audio\",\"artifact\":\"audio_plan\",\"title\":\"音频规划（暂未开放）\",\"prompt\":\"...\",\"key\":\"audio_plan\"}]}</canvas-actions>；只允许一个 audio 节点。该节点仅展示规划，绝不能生成或计费。";
        if ($workflowStage==='assets') return "当前为短剧主体资产阶段。只在答复末尾输出严格 JSON 包裹 <canvas-actions>{\"nodes\":[...]}</canvas-actions>；nodes 只能是 image，artifact 只能是 subject 或 three_view；每个 three_view 必须通过 depends_on 引用前面同批的 subject。最多 4 项，每项只允许 type、artifact、title、prompt、key、depends_on。不得声明价格、模型、URL、素材 ID 或任务状态。";
        if ($workflowStage==='storyboard') return "当前为场景与分镜图阶段。只在答复末尾输出严格 JSON 包裹 <canvas-actions>{\"nodes\":[...]}</canvas-actions>；nodes 只能是 image，artifact 只能是 scene、prop 或 storyboard；storyboard 必须通过 depends_on 引用前面同批的 scene 或 prop。最多 4 项，每项只允许 type、artifact、title、prompt、key、depends_on。不得声明价格、模型、URL、素材 ID 或任务状态。";
        if ($workflowStage==='video_plan') return "当前为分镜视频规划阶段。答复先用清晰的短剧排版说明镜头制作计划，再在末尾输出一次严格 JSON：<canvas-actions>{\"nodes\":[...]}</canvas-actions>。nodes 只能是 text，artifact 必须是 video_prompt_plan；每项 prompt 必须覆盖 shot_number、duration、first_frame、last_frame、camera_motion、action_sequence、video_prompt、asset_references。每项只允许 type、artifact、title、prompt、key、depends_on；只规划，不报价、不提交视频任务。";
        $delivery = $mode === 'auto'
            ? '自动模式会在校验后创建节点；文本和图片节点会由页面自动提交。视频节点（尤其是分镜视频）只会插入画布并连接已有参考，绝不自动提交；用户必须在该节点点击生成并完成平台既有报价确认。'
            : '手动模式只会创建待生成节点，用户必须在画布节点上点击生成。';
        return "当且仅当用户明确要求创建或生成画布内容、且所需提示词已经足够时，你可以在正常答复末尾附加一个严格 JSON 包裹：<canvas-actions>{\"nodes\":[{\"type\":\"text|image|video\",\"title\":\"简短标题\",\"prompt\":\"生成提示词\",\"key\":\"step_1\"},{\"type\":\"image\",\"title\":\"后续图片\",\"prompt\":\"生成提示词\",\"key\":\"step_2\",\"depends_on\":[\"step_1\"]}]}</canvas-actions>。key 和 depends_on 仅在本次包裹中表达前序步骤依赖；depends_on 只能引用前面已出现的 key。最多 4 个节点；不要包含模型、价格、URL、身份、工具调用、素材 ID 或任何其它字段。用户只是咨询、信息不足、要求修改正式业务内容或要求批量媒体时，不要输出该包裹，而是说明或补问。{$delivery}";
    }

    /** @return array{text:string,nodes:list<array{type:string,title:string,prompt:string,key?:string,depends_on?:list<string>}>} */
    public static function parse(string $reply,string $workflowStage=''): array
    {
        $start = strpos($reply, self::OPEN);
        if ($start === false) return ['text' => trim($reply), 'nodes' => []];
        $end = strpos($reply, self::CLOSE, $start + strlen(self::OPEN));
        if ($end === false || strpos($reply, self::OPEN, $start + 1) !== false || strpos($reply, self::CLOSE, $end + 1) !== false) {
            throw new RuntimeException('INVALID_AGENT_ACTION');
        }
        $body = trim(substr($reply, $start + strlen(self::OPEN), $end - ($start + strlen(self::OPEN))));
        try { $action = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw new RuntimeException('INVALID_AGENT_ACTION', 0, $error); }
        $allowedTypes=match ($workflowStage) {
            'script','art','video_plan'=>['text'], 'assets','storyboard'=>['image'], 'video_nodes'=>['video'], 'audio_plan'=>['audio'], default=>['text','image','video'],
        };
        $allowedArtifacts=match ($workflowStage) {
            'script'=>['story_setting','episode_outline','storyboard_script'],
            'art'=>['art_bible','character_asset_spec','scene_asset_spec','prop_asset_spec','subject_image_prompt','three_view_prompt','scene_image_prompt','storyboard_image_prompt'],
            'assets'=>['subject','three_view'], 'storyboard'=>['scene','prop','storyboard'], 'video_plan'=>['video_prompt_plan'], 'video_nodes'=>['storyboard_video'], 'audio_plan'=>['audio_plan'], default=>[],
        };
        $maximum=match ($workflowStage) { 'video_nodes'=>60, 'audio_plan'=>1, 'script'=>3, 'art','video_plan'=>8, default=>4 };
        if (!is_array($action) || array_keys($action) !== ['nodes'] || !is_array($action['nodes']) || !array_is_list($action['nodes']) || !$action['nodes'] || count($action['nodes']) > $maximum) throw new RuntimeException('INVALID_AGENT_ACTION');
        $nodes = [];
        $keys = [];
        $keyArtifacts = [];
        foreach ($action['nodes'] as $node) {
            $fields=$allowedArtifacts ? ['type','artifact','title','prompt','key','depends_on'] : ['type','title','prompt','key','depends_on'];
            if (!is_array($node) || array_diff(array_keys($node), $fields)) throw new RuntimeException('INVALID_AGENT_ACTION');
            $type = (string)($node['type'] ?? ''); $title = trim((string)($node['title'] ?? '')); $prompt = trim((string)($node['prompt'] ?? ''));
            if (!in_array($type, $allowedTypes, true) || $title === '' || mb_strlen($title) > 80 || $prompt === '' || mb_strlen($prompt) > 20000) throw new RuntimeException('INVALID_AGENT_ACTION');
            $proposal = ['type'=>$type, 'title'=>$title, 'prompt'=>$prompt];
            if ($allowedArtifacts) {
                $artifact=(string)($node['artifact']??'');
                if (!in_array($artifact,$allowedArtifacts,true)) throw new RuntimeException('INVALID_AGENT_ACTION');
                $proposal['artifact']=$artifact;
            }
            if (array_key_exists('key', $node) && !is_string($node['key'])) throw new RuntimeException('INVALID_AGENT_ACTION');
            $key = trim((string)($node['key'] ?? ''));
            if (array_key_exists('key', $node)) {
                if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $key) || isset($keys[$key])) throw new RuntimeException('INVALID_AGENT_ACTION');
                $proposal['key'] = $key;
            }
            if (array_key_exists('depends_on', $node)) {
                if ($key === '' || !is_array($node['depends_on']) || !array_is_list($node['depends_on']) || count($node['depends_on']) > 3) throw new RuntimeException('INVALID_AGENT_ACTION');
                $dependencies=[];
                foreach ($node['depends_on'] as $dependency) {
                    if (!is_string($dependency) || !isset($keys[$dependency]) || isset($dependencies[$dependency])) throw new RuntimeException('INVALID_AGENT_ACTION');
                    $dependencies[$dependency] = true;
                }
                $proposal['depends_on'] = array_keys($dependencies);
            }
            if ($key !== '') {
                $keys[$key] = true;
                $keyArtifacts[$key] = (string)($proposal['artifact'] ?? '');
            }
            if (($proposal['artifact']??'')==='three_view') {
                $dependencies=(array)($proposal['depends_on']??[]);
                if (count($dependencies)!==1 || ($keyArtifacts[$dependencies[0]]??'')!=='subject') throw new RuntimeException('INVALID_AGENT_ACTION');
            }
            if (($proposal['artifact']??'')==='storyboard') {
                $dependencies=(array)($proposal['depends_on']??[]);
                $artifacts=[];
                foreach ($dependencies as $dependency) $artifacts[]=$keyArtifacts[$dependency]??'';
                if (!$dependencies || !array_intersect($artifacts,['scene','prop'])) throw new RuntimeException('INVALID_AGENT_ACTION');
            }
            if (($proposal['artifact']??'')==='episode_outline') {
                $dependencies=(array)($proposal['depends_on']??[]);
                if (count($dependencies)!==1 || ($keyArtifacts[$dependencies[0]]??'')!=='story_setting') throw new RuntimeException('INVALID_AGENT_ACTION');
            }
            if (($proposal['artifact']??'')==='storyboard_script') {
                $dependencies=(array)($proposal['depends_on']??[]);
                if (count($dependencies)!==1 || ($keyArtifacts[$dependencies[0]]??'')!=='episode_outline') throw new RuntimeException('INVALID_AGENT_ACTION');
            }
            $nodes[] = $proposal;
        }
        $text = trim(substr($reply, 0, $start) . substr($reply, $end + strlen(self::CLOSE)));
        return ['text' => $text === '' ? '已创建画布节点。' : $text, 'nodes' => $nodes];
    }
}
