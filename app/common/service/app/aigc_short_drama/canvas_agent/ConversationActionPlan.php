<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/**
 * A deliberately small, model-produced canvas proposal format.
 *
 * This is not a provider tool protocol: the model can only propose a bounded
 * number of text/image/video nodes. The server owns IDs, models, graph writes and
 * all later task submission.  Plain conversational replies remain plain
 * replies, which keeps a question from accidentally becoming a paid task.
 */
final class ConversationActionPlan
{
    private const OPEN = '<canvas-actions>';
    private const CLOSE = '</canvas-actions>';

    /**
     * These stages are not ordinary chat.  A successful reply must contain a
     * server-validated projection for the durable workflow artifacts; a
     * visually plausible markdown-only reply is not a successful stage.
     */
    private const STRUCTURED_WORKFLOW_STAGES=['script','art','assets','storyboard','video_plan','video_nodes','audio_plan'];

    public static function maximumNodesForStage(string $stage,bool $compact=false): int
    {
        return match ($stage) { 'video_nodes'=>60, 'audio_plan'=>1, 'script'=>$compact?2:3, 'art'=>64, 'assets'=>16, 'storyboard'=>24, 'video_plan'=>24, default=>4 };
    }

    public static function instruction(string $mode,string $workflowStage='',bool $compact=false,bool $stageGenerationPrompts=false): string
    {
        if ($compact) {
            if ($workflowStage==='script') return self::structuredEnvelopeInstruction('剧本与角色设定', 'story_setting、episode_script', '必须恰好输出两项：story_setting 合并故事设定、角色关系及全剧/分集大纲；episode_script 是当前制作单集的完整场景、动作、对白与镜头意图。两个节点的 prompt 都必须是可阅读的真实内容，不能是模板或占位符。不得用 depends_on 连接两个文本节点；仅实际作为媒体输入的素材才需要画布连线。');
            if ($stageGenerationPrompts && $workflowStage==='art') return self::structuredEnvelopeInstruction('画风与美术规划', 'art_bible、character_asset_spec、scene_asset_spec、prop_asset_spec、subject_image_prompt、three_view_prompt、scene_image_prompt、storyboard_image_prompt', '这些是真实的阶段规划，确认后只保存在对话工作流状态，不创建画布节点。优先为每个实际主体分别返回 subject_image_prompt 和 three_view_prompt，为每个实际场景分别返回 scene_image_prompt；每项 prompt 只写对应主体或场景的完整中文生图提示词，不把多个人或多个场景合并为一项。key 和 title 要能让后续阶段准确选到同一主体或场景。art_bible 和资产说明可作为额外规划，但不得取代上述独立提示词。相同主体或场景不得重复规划，不必为同一场景的每个分镜分别再建场景提示词。节点总数最多六十四项。不得输出模板或占位符。此阶段是纯文本规划，不需要 depends_on 或 reference_keys。');
            if ($workflowStage==='art') return self::structuredEnvelopeInstruction('画风与美术规划', 'art_bible、character_asset_spec、scene_asset_spec、prop_asset_spec、subject_image_prompt、three_view_prompt、scene_image_prompt、storyboard_image_prompt', '这些是真实的阶段规划，确认后只保存在对话工作流状态，不创建画布节点。按实际角色和场景分别输出明确、可用于后续生图的内容；不得输出模板或占位符。此阶段是纯文本规划，不需要 depends_on 或 reference_keys。');
            if ($workflowStage==='video_plan') return self::structuredEnvelopeInstruction('分镜视频规划', 'video_prompt_plan', '每镜包含 shot_number、duration、first_frame、last_frame、camera_motion、action_sequence、video_prompt、asset_references；确认后只保存在对话工作流状态，不创建画布文本节点。');
            if ($stageGenerationPrompts && $workflowStage==='assets') return self::mediaEnvelopeInstruction('当前为主体资产阶段。只输出 <canvas-actions>{"nodes":[...]}</canvas-actions>。节点只能为 image，artifact 为 subject 或 three_view；每个 three_view 必须 depends_on 同批对应 subject，且该主体图是必须连接的三视图媒体输入。每个 subject 的 reference_keys 必须且只能包含该主体对应的一项 art 阶段 subject_image_prompt；每个 three_view 必须且只能包含该主体对应的一项 art 阶段 three_view_prompt。服务端会把该已确认提示词原样放进节点输入框；不要重写为另一段提示词，也不要连接规划文本。prompt 字段同样填写该提示词。其他引用仅可选实际使用的已生成媒体或用户明确选中的素材；不要把全部历史产物连接到每个节点。按实际主体各建一组，最多十六项。每项只允许 type、artifact、title、prompt、key、depends_on、reference_keys；不得声明价格、模型、URL、素材 ID 或任务状态。');
            if ($stageGenerationPrompts && $workflowStage==='storyboard') return self::mediaEnvelopeInstruction('当前为场景与分镜图阶段。只输出 <canvas-actions>{"nodes":[...]}</canvas-actions>。节点只能为 image，artifact 为 scene 或 storyboard；每个 scene 的 reference_keys 必须且只能包含该场景对应的一项 art 阶段 scene_image_prompt；服务端会把这项已确认提示词原样放进场景图输入框，不要重写。每个 storyboard 必须 depends_on 同批对应 scene，reference_keys 只选该镜头确实出镜或决定画面一致性的主体图/三视图。storyboard 的 prompt 必须是这一镜头独立返回的中文 image_prompt，即实际提交的生图内容，只描述当前可见主体、动作、场景、构图、光线与画风，不要写策划说明或追加整份规划。关键道具写入该镜头 prompt，不默认创建道具图。必须覆盖已确认单集剧本的每个实际镜头，场景复用而非每镜重复创建；最多二十四项。每项只允许 type、artifact、title、prompt、key、depends_on、reference_keys；不得声明价格、模型、URL、素材 ID 或任务状态。');
            if ($stageGenerationPrompts && $workflowStage==='video_nodes') return self::mediaEnvelopeInstruction('当前为分镜视频节点阶段。只输出 <canvas-actions>{"nodes":[...]}</canvas-actions>。节点只能为 video，artifact 为 storyboard_video；每项 reference_keys 必须从 workflow_reference_catalog 选择唯一对应分镜图，可额外选实际需要的主体/场景图片，不得连接无关节点或以 depends_on 串联视频。每项 prompt 必须是该镜头独立返回的中文 video_prompt，直接显示在视频节点输入框；采用原短剧的六行导演稿：分镜编号与时间、景别、构图、运镜手法、画面内容、声音。不要写后端执行标签、英文生成描述或整份视频规划。只插入待用户生成节点，不自动报价、提交或计费；最多六十项。每项只允许 type、artifact、title、prompt、key、reference_keys；不得声明价格、模型、URL、素材 ID 或任务状态。');
            if ($workflowStage==='assets') return self::mediaEnvelopeInstruction('当前为主体资产阶段。只输出 <canvas-actions>{"nodes":[...]}</canvas-actions>。节点只能为 image，artifact 为 subject 或 three_view；每个 three_view 必须 depends_on 同批对应 subject，且该主体图是唯一必须的三视图媒体输入。每个 prompt 应根据已确认的剧本与美术规划写成完整生图提示词。reference_keys 只能选 workflow_reference_catalog 中实际用于本节点的已生成媒体或用户选择的素材；不要把全部历史产物连接到每个节点。按实际主体各建一组，最多十六项。每项只允许 type、artifact、title、prompt、key、depends_on、reference_keys；不得声明价格、模型、URL、素材 ID 或任务状态。');
            if ($workflowStage==='storyboard') return self::mediaEnvelopeInstruction('当前为场景与分镜图阶段。只输出 <canvas-actions>{"nodes":[...]}</canvas-actions>。节点只能为 image，artifact 为 scene 或 storyboard；每个 storyboard 必须 depends_on 同批对应 scene，且 reference_keys 只能从 workflow_reference_catalog 中选择该镜头确实出镜或决定画面一致性的主体图/三视图。关键道具写入该镜头 prompt，不默认创建道具图。每个 prompt 须包含真实场景、角色、动作、构图和画风；必须覆盖已确认单集剧本的每个实际镜头，场景复用，最多二十四项。每项只允许 type、artifact、title、prompt、key、depends_on、reference_keys；不得声明价格、模型、URL、素材 ID 或任务状态。');
            if ($workflowStage==='video_nodes') return '当前为分镜视频节点阶段。只输出 <canvas-actions>{"nodes":[...]}</canvas-actions>。节点只能为 video，artifact 为 storyboard_video；每项 reference_keys 必须从 workflow_reference_catalog 选择对应分镜图，可额外选实际需要的主体/场景图片，不得连接无关节点或以 depends_on 串联视频。只插入待用户生成节点，不自动报价、提交或计费；最多六十项。每项只允许 type、artifact、title、prompt、key、reference_keys；不得声明价格、模型、URL、素材 ID 或任务状态。';
        }
        if ($workflowStage==='script') return self::structuredEnvelopeInstruction('剧本与角色设定', 'story_setting、episode_outline、storyboard_script', '每项 prompt 是可直接阅读的结构化正文，必须覆盖 project_title、logline、world_setting、character_profiles、episode_outline、scene_script 或 storyboard_script 中与该节点匹配的字段；三个 artifact 均须各创建一项，episode_outline 必须依赖 story_setting，storyboard_script 必须依赖 episode_outline。');
        if ($workflowStage==='art') return self::structuredEnvelopeInstruction('画风与美术规划', 'art_bible、character_asset_spec、scene_asset_spec、prop_asset_spec、subject_image_prompt、three_view_prompt、scene_image_prompt 或 storyboard_image_prompt', '每项 prompt 是可直接阅读的结构化正文，覆盖 art_bible、角色/场景/道具资产说明及后续生图提示词；按已确认的创作需要输出完整的资产计划。');
        if ($workflowStage==='video_nodes') return self::mediaEnvelopeInstruction("当前为分镜视频节点阶段。只在答复末尾输出一次严格 JSON 包裹 <canvas-actions>{\"nodes\":[...]}</canvas-actions>；nodes 只能是 video，数量 1 至 60，artifact 必须是 storyboard_video，全部为待用户生成的分镜视频节点。每项只允许 type、artifact、title、prompt、key、depends_on、reference_keys；reference_keys 只能选用阶段输入 workflow_reference_catalog 的 reference_key。不得声明价格、模型、URL、素材 ID 或任务状态。");
        if ($workflowStage==='audio_plan') return self::mediaEnvelopeInstruction("当前为音频规划阶段。只在答复末尾输出一次严格 JSON 包裹 <canvas-actions>{\"nodes\":[{\"type\":\"audio\",\"artifact\":\"audio_plan\",\"title\":\"音频规划（暂未开放）\",\"prompt\":\"...\",\"key\":\"audio_plan\"}]}</canvas-actions>；只允许一个 audio 节点。prompt 必须根据已确认单集剧本逐镜写出对白、旁白、环境声、音效和配乐情绪的真实规划，不得返回占位符。此节点仅展示规划，绝不能生成或计费。");
        if ($workflowStage==='assets') return self::mediaEnvelopeInstruction("当前为短剧主体资产阶段。只在答复末尾输出严格 JSON 包裹 <canvas-actions>{\"nodes\":[...]}</canvas-actions>；nodes 只能是 image，artifact 只能是 subject 或 three_view；每个 three_view 必须通过 depends_on 引用前面同批的 subject。reference_keys 只能选 workflow_reference_catalog 的 reference_key：角色主体只引用该角色的 character_asset_spec 或 subject_image_prompt；三视图只引用对应的 three_view_prompt，主体通过 depends_on 连接。最多十六项，每项只允许 type、artifact、title、prompt、key、depends_on、reference_keys。不得声明价格、模型、URL、素材 ID 或任务状态。");
        if ($workflowStage==='storyboard') return self::mediaEnvelopeInstruction("当前为场景与分镜图阶段。只在答复末尾输出严格 JSON 包裹 <canvas-actions>{\"nodes\":[...]}</canvas-actions>；nodes 只能是 image，artifact 只能是 scene、prop 或 storyboard；storyboard 必须通过 depends_on 引用前面同批的 scene 或 prop。reference_keys 只能选 workflow_reference_catalog 中直接决定本节点的 reference_key，不得把所有前序剧本和资产都连接到同一节点。必须覆盖已确认单集剧本的每个实际镜头，最多二十四项，每项只允许 type、artifact、title、prompt、key、depends_on、reference_keys。不得声明价格、模型、URL、素材 ID 或任务状态。");
        if ($workflowStage==='video_plan') return self::structuredEnvelopeInstruction('分镜视频规划', 'video_prompt_plan', '每项 prompt 必须覆盖 shot_number、duration、first_frame、last_frame、camera_motion、action_sequence、video_prompt、asset_references；只规划，不报价、不提交视频任务。');
        $delivery = $mode === 'auto'
            ? '自动模式会在校验后创建节点；文本和图片节点会由页面自动提交。视频节点（尤其是分镜视频）只会插入画布并连接已有参考，绝不自动提交；用户必须在该节点点击生成并完成平台既有报价确认。'
            : '手动模式只会创建待生成节点，用户必须在画布节点上点击生成。';
        return "当且仅当用户明确要求创建或生成画布内容、且所需提示词已经足够时，你可以在正常答复末尾附加一个严格 JSON 包裹：<canvas-actions>{\"nodes\":[{\"type\":\"text|image|video\",\"title\":\"简短标题\",\"prompt\":\"生成提示词\",\"key\":\"step_1\"},{\"type\":\"image\",\"title\":\"后续图片\",\"prompt\":\"生成提示词\",\"key\":\"step_2\",\"depends_on\":[\"step_1\"]}]}</canvas-actions>。key 和 depends_on 仅在本次包裹中表达前序步骤依赖；depends_on 只能引用前面已出现的 key。最多 4 个节点；不要包含模型、价格、URL、身份、工具调用、素材 ID 或任何其它字段。用户只是咨询、信息不足、要求修改正式业务内容或要求批量媒体时，不要输出该包裹，而是说明或补问。{$delivery}";
    }

    /** Provider-neutral JSON-object mode for stages which must project data. */
    public static function responseFormat(string $workflowStage): ?array
    {
        return in_array($workflowStage,self::STRUCTURED_WORKFLOW_STAGES,true) ? ['type'=>'json_object'] : null;
    }

    /** Shape-only diagnostics for a rejected Provider reply. Never retain
     * the actual story, prompt or model output in an event. */
    public static function failureCategory(string $reply,string $stage,bool $compact=false): string
    {
        try { $value=json_decode(trim($reply),true,32,JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { return 'action_not_json'; }
        if (!is_array($value) || array_keys($value)!==['reply_markdown','canvas_actions']
            || !is_string($value['reply_markdown']??null)) return 'action_envelope';
        $action=$value['canvas_actions']??null;
        if (!is_array($action) || array_keys($action)!==['nodes'] || !is_array($action['nodes'])
            || !array_is_list($action['nodes']) || !$action['nodes']) return 'action_nodes';
        if (count($action['nodes'])>self::maximumNodesForStage($stage,$compact)) return 'action_count';
        $artifacts=[];$seen=[];
        foreach ($action['nodes'] as $node) {
            if (!is_array($node) || array_diff(array_keys($node),['type','artifact','title','prompt','key','depends_on','reference_keys','formal_fields'])) return 'action_node_fields';
            if (!is_string($node['type']??null) || !is_string($node['artifact']??null)
                || !is_string($node['title']??null) || !is_string($node['prompt']??null)
                || trim($node['prompt'])==='') return 'action_node_values';
            if (mb_strlen($node['title'])>80 || mb_strlen($node['prompt'])>20000) return 'action_node_length';
            $type=match ($stage) { 'script','art','video_plan'=>'text','assets','storyboard'=>'image','video_nodes'=>'video','audio_plan'=>'audio',default=>'' };
            if ($type!=='' && $node['type']!==$type) return 'action_node_type';
            $allowed=match ($stage) {
                'script'=>$compact?['story_setting','episode_script']:['story_setting','episode_outline','storyboard_script'],
                'art'=>['art_bible','character_asset_spec','scene_asset_spec','prop_asset_spec','subject_image_prompt','three_view_prompt','scene_image_prompt','storyboard_image_prompt'],
                'assets'=>['subject','three_view'],'storyboard'=>$compact?['scene','storyboard']:['scene','prop','storyboard'],
                'video_plan'=>['video_prompt_plan'],'video_nodes'=>['storyboard_video'],'audio_plan'=>['audio_plan'],default=>[],
            };
            if ($allowed && !in_array($node['artifact'],$allowed,true)) return 'action_node_artifact';
            $key=$node['key']??null;
            if ($key!==null && (!is_string($key) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$key))) return 'action_node_key_format';
            if ($key!==null && isset($seen[$key])) return 'action_node_key_duplicate';
            if (isset($node['depends_on'])) {
                if ($key===null) return 'action_dependency_missing_key';
                if (!is_array($node['depends_on']) || !array_is_list($node['depends_on'])) return 'action_dependency_shape';
                if (count($node['depends_on'])>3) return 'action_dependency_count';
                foreach ($node['depends_on'] as $dependency) if (!is_string($dependency) || !isset($seen[$dependency])) return 'action_node_dependency_order';
            }
            if (isset($node['reference_keys'])) {
                if (!is_array($node['reference_keys']) || !array_is_list($node['reference_keys']) || count($node['reference_keys'])>6) return 'action_node_references';
                foreach ($node['reference_keys'] as $reference) if (!is_string($reference) || !preg_match('/^[a-z][a-z0-9_-]{0,47}:[a-z][a-z0-9_-]{0,31}$/D',$reference)) return 'action_node_reference_format';
            }
            if ($node['artifact']==='three_view') {
                $depends=(array)($node['depends_on']??[]);
                if (count($depends)!==1 || ($seen[$depends[0]]??'')!=='subject') return 'action_three_view_dependency';
            }
            if ($node['artifact']==='storyboard') {
                $depends=(array)($node['depends_on']??[]);
                if (!$depends || !array_intersect(array_map(static fn($dependency): string=>$seen[$dependency]??'', $depends),$compact?['scene']:['scene','prop'])) return 'action_storyboard_dependency';
            }
            if ($key!==null) $seen[$key]=$node['artifact'];
            $artifacts[$node['artifact']]=($artifacts[$node['artifact']]??0)+1;
        }
        if ($compact && $stage==='script' && (count($action['nodes'])!==2
            || ($artifacts['story_setting']??0)!==1 || ($artifacts['episode_script']??0)!==1)) return 'action_script_artifacts';
        return 'action_contract';
    }

    /** Keep stage semantics identical when an intent envelope owns the top
     * level JSON shape. Legacy tag/standalone-JSON wording must not compete
     * with the active-turn router's five-field response contract. */
    public static function nestedInstruction(string $mode,string $workflowStage,bool $compact,bool $stageGenerationPrompts=false): string
    {
        $instruction=self::instruction($mode,$workflowStage,$compact,$stageGenerationPrompts);
        $instruction=str_replace(
            '只输出一个合法 JSON 对象，不要 Markdown 代码块、不要前后说明。对象只能有 reply_markdown 和 canvas_actions 两个字段：',
            'workflow_output 子对象只能有 reply_markdown 和 canvas_actions 两个字段：',
            $instruction
        );
        $instruction=str_replace('只输出一个合法 JSON 对象，不要 Markdown 代码块或前后说明。对象字段恰好为 reply_markdown 和 canvas_actions，canvas_actions 的结构为 {"nodes":[...]}。','workflow_output 子对象字段恰好为 reply_markdown 和 canvas_actions，canvas_actions 的结构为 {"nodes":[...]}。',$instruction);
        $instruction=preg_replace('/只输出 <canvas-actions>(.*?)<\/canvas-actions>。/u',
            'workflow_output.canvas_actions 必须是 $1。',$instruction)??$instruction;
        $instruction=preg_replace('/只在答复末尾输出(?:一次)?严格 JSON 包裹 <canvas-actions>(.*?)<\/canvas-actions>；/u',
            'workflow_output.canvas_actions 必须是 $1；',$instruction)??$instruction;
        return $instruction;
    }

    private static function structuredEnvelopeInstruction(string $label,string $artifacts,string $requirements): string
    {
        return "当前为{$label}阶段。只输出一个合法 JSON 对象，不要 Markdown 代码块、不要前后说明。对象只能有 reply_markdown 和 canvas_actions 两个字段：reply_markdown 只给用户一段简短自然语言，依据本次真实产物概括完成了什么、最重要的一两点和接下来需要用户做什么；当前产物尚未确认写入画布，不得声称节点已更新、已重写或已写入。不要复制完整剧本、规划、提示词、Markdown、JSON 或程序字段名。完整内容只放在对应 nodes 的 prompt。canvas_actions 只能是 {\"nodes\":[...]}。nodes 只能是 text，artifact 只能是 {$artifacts}。{$requirements} 每项只允许 type、artifact、title、prompt、key、depends_on、reference_keys；reference_keys 只能使用 workflow_reference_catalog 的 reference_key，且只选直接依赖的产物；不得输出模型、价格、URL、素材 ID、任务状态或任意画布 JSON。";
    }

    private static function mediaEnvelopeInstruction(string $instruction): string
    {
        $instruction=preg_replace('/只输出 <canvas-actions>.*?<\/canvas-actions>。/u','',$instruction)??$instruction;
        $instruction=preg_replace('/只在答复末尾输出(?:一次)?严格 JSON 包裹 <canvas-actions>.*?<\/canvas-actions>；/u','',$instruction)??$instruction;
        return '只输出一个合法 JSON 对象，不要 Markdown 代码块或前后说明。对象字段恰好为 reply_markdown 和 canvas_actions，canvas_actions 的结构为 {"nodes":[...]}。每个节点的 key 必填且全批次唯一，格式为小写英文字母开头、后接小写字母/数字/下划线/短横线，最长 32 字符，例如 subject_chenyu、view_chenyu；不能写中文、空格、冒号或重复 key。depends_on 必须是 JSON 字符串数组，例如 ["subject_chenyu"]，只引用同批更早节点的 key，不能直接写字符串、null 或对象；没有依赖时省略此字段。reference_keys 才使用 catalog 中带冒号的跨阶段 reference_key。reply_markdown 只用一句话概括已准备的内容与下一步，未确认写入前不得声称图片或视频已生成。'.$instruction;
    }

    /** @return array{text:string,nodes:list<array{type:string,title:string,prompt:string,key?:string,depends_on?:list<string>}>} */
    public static function parse(string $reply,string $workflowStage='',bool $compact=false): array
    {
        [$text,$action]=self::extract($reply,$workflowStage);
        if ($action===null) return ['text'=>$text,'nodes'=>[]];
        $allowedTypes=match ($workflowStage) {
            'script','art','video_plan'=>['text'], 'assets','storyboard'=>['image'], 'video_nodes'=>['video'], 'audio_plan'=>['audio'], default=>['text','image','video'],
        };
        $allowedArtifacts=match ($workflowStage) {
            'script'=>$compact?['story_setting','episode_script']:['story_setting','episode_outline','storyboard_script'],
            'art'=>['art_bible','character_asset_spec','scene_asset_spec','prop_asset_spec','subject_image_prompt','three_view_prompt','scene_image_prompt','storyboard_image_prompt'],
            'assets'=>['subject','three_view'], 'storyboard'=>$compact?['scene','storyboard']:['scene','prop','storyboard'], 'video_plan'=>['video_prompt_plan'], 'video_nodes'=>['storyboard_video'], 'audio_plan'=>['audio_plan'], default=>[],
        };
        $maximum=self::maximumNodesForStage($workflowStage,$compact);
        if (!is_array($action) || array_keys($action) !== ['nodes'] || !is_array($action['nodes']) || !array_is_list($action['nodes']) || !$action['nodes'] || count($action['nodes']) > $maximum) throw new RuntimeException('INVALID_AGENT_ACTION');
        $nodes = [];
        $keys = [];
        $keyArtifacts = [];
        foreach ($action['nodes'] as $node) {
            if ($compact && in_array($workflowStage,['art','video_plan'],true) && is_array($node)) {
                // Compact planning artifacts live in the conversation, not
                // the canvas graph. Model-proposed links cannot become media
                // inputs here; later image stages select authorized sources.
                unset($node['depends_on'],$node['reference_keys']);
            }
            // A single exact dependency key is unambiguous even if the
            // Provider encodes it as a scalar. Unknown keys remain invalid.
            if (in_array($workflowStage,['assets','storyboard'],true) && is_array($node)
                && is_string($node['depends_on']??null) && $node['depends_on']!=='') {
                $node['depends_on']=[$node['depends_on']];
            }
            // Audio is a disabled planning artifact, not an executable media
            // request. Ignore any provider-only annotations rather than
            // allowing them into the graph or billing path.
            if ($workflowStage==='audio_plan' && is_array($node)) {
                $node=array_intersect_key($node,array_flip(['type','artifact','title','prompt','key']));
            }
            $fields=$allowedArtifacts ? ['type','artifact','title','prompt','key','depends_on','reference_keys'] : ['type','title','prompt','key','depends_on'];
            // Older frozen replies may still carry this retired field. Ignore
            // it rather than failing an already-submitted Provider response.
            if ($compact && $workflowStage==='script') $fields[]='formal_fields';
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
            if (array_key_exists('reference_keys',$node)) {
                if (!is_array($node['reference_keys']) || !array_is_list($node['reference_keys']) || count($node['reference_keys'])>6) throw new RuntimeException('INVALID_AGENT_ACTION');
                $references=[];
                foreach ($node['reference_keys'] as $reference) {
                    if (!is_string($reference) || !preg_match('/^[a-z][a-z0-9_-]{0,47}:[a-z][a-z0-9_-]{0,31}$/D',$reference) || isset($references[$reference])) throw new RuntimeException('INVALID_AGENT_ACTION');
                    $references[$reference]=true;
                }
                // Structured JSON models commonly emit an empty array for an
                // optional reference field. It means no graph input, not an
                // invalid or implicit reference; all nonempty keys still pass
                // the strict catalog/type checks downstream.
                if ($references) $proposal['reference_keys']=array_keys($references);
            }
            if ($key !== '') {
                $keys[$key] = true;
                $keyArtifacts[$key] = (string)($proposal['artifact'] ?? '');
            }
            if (($proposal['artifact']??'')==='three_view') {
                $dependencies=(array)($proposal['depends_on']??[]);
                if (count($dependencies)!==1 || ($keyArtifacts[$dependencies[0]]??'')!=='subject') throw new RuntimeException('INVALID_AGENT_ACTION');
            }
            if ($compact && $workflowStage==='script' && !empty($proposal['depends_on'])) throw new RuntimeException('INVALID_AGENT_ACTION');
            if ($compact && $workflowStage==='script' && !empty($proposal['reference_keys'])) throw new RuntimeException('INVALID_AGENT_ACTION');
            if ($compact && $workflowStage==='video_nodes' && !empty($proposal['depends_on'])) throw new RuntimeException('INVALID_AGENT_ACTION');
            if ($compact && $workflowStage==='video_nodes' && !array_filter((array)($proposal['reference_keys']??[]),static fn(string $key): bool=>str_starts_with($key,'storyboard:'))) throw new RuntimeException('INVALID_AGENT_ACTION');
            if (($proposal['artifact']??'')==='storyboard') {
                $dependencies=(array)($proposal['depends_on']??[]);
                $artifacts=[];
                foreach ($dependencies as $dependency) $artifacts[]=$keyArtifacts[$dependency]??'';
                if (!$dependencies || !array_intersect($artifacts,$compact?['scene']:['scene','prop'])) throw new RuntimeException('INVALID_AGENT_ACTION');
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
        if ($compact && $workflowStage==='script') {
            $artifacts=array_count_values(array_column($nodes,'artifact'));
            if (count($nodes)!==2 || ($artifacts['story_setting']??0)!==1 || ($artifacts['episode_script']??0)!==1) throw new RuntimeException('INVALID_AGENT_ACTION');
        }
        return ['text' => $text === '' ? '已创建画布节点。' : $text, 'nodes' => $nodes];
    }

    /** @return array{0:string,1:?array} */
    private static function extract(string $reply,string $workflowStage): array
    {
        $trim=trim($reply);
        try {
            $envelope=json_decode($trim,true,32,JSON_THROW_ON_ERROR);
            if (is_array($envelope) && array_key_exists('canvas_actions',$envelope)) {
                if (array_keys($envelope)!==['reply_markdown','canvas_actions'] || !is_string($envelope['reply_markdown']) || !is_array($envelope['canvas_actions'])) throw new RuntimeException('INVALID_AGENT_ACTION');
                return [trim($envelope['reply_markdown']),$envelope['canvas_actions']];
            }
        } catch (RuntimeException $error) { throw $error; }
        catch (\Throwable $error) { /* Non-JSON replies can still use legacy tags. */ }
        $start=strpos($reply,self::OPEN);
        if ($start===false) {
            if (in_array($workflowStage,self::STRUCTURED_WORKFLOW_STAGES,true)) throw new RuntimeException('INVALID_AGENT_ACTION');
            return [$trim,null];
        }
        $end=strpos($reply,self::CLOSE,$start+strlen(self::OPEN));
        if ($end===false || strpos($reply,self::OPEN,$start+1)!==false || strpos($reply,self::CLOSE,$end+1)!==false) throw new RuntimeException('INVALID_AGENT_ACTION');
        $body=trim(substr($reply,$start+strlen(self::OPEN),$end-($start+strlen(self::OPEN))));
        try { $action=json_decode($body,true,32,JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw new RuntimeException('INVALID_AGENT_ACTION',0,$error); }
        return [trim(substr($reply,0,$start).substr($reply,$end+strlen(self::CLOSE))),$action];
    }
}
