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
        if ($workflowStage==='video_nodes') return "当前为分镜视频节点阶段。只在答复末尾输出一次严格 JSON 包裹 <canvas-actions>{\"nodes\":[...]}</canvas-actions>；nodes 只能是 video，数量 1 至 60，全部为待用户生成的分镜视频节点。每项只允许 type、title、prompt、key、depends_on；不得声明价格、模型、URL、素材 ID 或任务状态。";
        if ($workflowStage==='audio_plan') return "当前为音频规划阶段。只在答复末尾输出一次严格 JSON 包裹 <canvas-actions>{\"nodes\":[{\"type\":\"audio\",\"title\":\"音频规划（暂未开放）\",\"prompt\":\"...\",\"key\":\"audio_plan\"}]}</canvas-actions>；只允许一个 audio 节点。该节点仅展示规划，绝不能生成或计费。";
        if (in_array($workflowStage,['assets','storyboard'],true)) return "当前为短剧图片资产阶段。只在答复末尾输出严格 JSON 包裹 <canvas-actions>{\"nodes\":[...]}</canvas-actions>；nodes 只能是 image，最多 4 项，每项只允许 type、title、prompt、key、depends_on。不得声明价格、模型、URL、素材 ID 或任务状态。";
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
            'assets','storyboard'=>['image'], 'video_nodes'=>['video'], 'audio_plan'=>['audio'], default=>['text','image','video'],
        };
        $maximum=$workflowStage==='video_nodes'?60:($workflowStage==='audio_plan'?1:4);
        if (!is_array($action) || array_keys($action) !== ['nodes'] || !is_array($action['nodes']) || !array_is_list($action['nodes']) || !$action['nodes'] || count($action['nodes']) > $maximum) throw new RuntimeException('INVALID_AGENT_ACTION');
        $nodes = [];
        $keys = [];
        foreach ($action['nodes'] as $node) {
            if (!is_array($node) || array_diff(array_keys($node), ['type','title','prompt','key','depends_on'])) throw new RuntimeException('INVALID_AGENT_ACTION');
            $type = (string)($node['type'] ?? ''); $title = trim((string)($node['title'] ?? '')); $prompt = trim((string)($node['prompt'] ?? ''));
            if (!in_array($type, $allowedTypes, true) || $title === '' || mb_strlen($title) > 80 || $prompt === '' || mb_strlen($prompt) > 20000) throw new RuntimeException('INVALID_AGENT_ACTION');
            $proposal = ['type'=>$type, 'title'=>$title, 'prompt'=>$prompt];
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
            if ($key !== '') $keys[$key] = true;
            $nodes[] = $proposal;
        }
        $text = trim(substr($reply, 0, $start) . substr($reply, $end + strlen(self::CLOSE)));
        return ['text' => $text === '' ? '已创建画布节点。' : $text, 'nodes' => $nodes];
    }
}
