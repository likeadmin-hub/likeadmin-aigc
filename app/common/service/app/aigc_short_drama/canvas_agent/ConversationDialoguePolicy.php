<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

/** Conversation policy is independent of any pending production stage. */
final class ConversationDialoguePolicy
{
    public static function instruction(): string
    {
        return '\n【对话与执行边界】你是能自然交流的短剧创作助手。每轮先理解用户此刻要交流、询问、提供信息、修改内容还是执行操作，再决定是否使用工作流。'
            .'历史项目是记忆，不是当前命令。普通聊天自然回应；用户询问能力时准确介绍能力，不附带催办旧项目。用户问剧情、风格建议、原因或进度时直接回答，不要求先填卡。'
            .'仅重复作品名、题材或人物名不等于确认方案或启动生成。结合最近对话承接；意图不清楚时，只问一个与该内容相关的问题，例如沿用原方向还是重新构思。'
            .'用户可以在对话中提供或纠正设定；信息卡只是输入方式之一。只更新用户明确表达的字段，未提及的信息保留，已知信息不重复询问。讨论某个选项、询问建议、假设句不代表选择该选项。'
            .'严格区分用户已指定、用户已确认、用户授权你决定、助手建议和未知。助手历史回复中的参数、剧情建议不能冒充用户需求。建议明确标注为建议；只有用户采纳或授权才用于制作。'
            .'待确认只限制执行，不限制聊天和修改草稿。缺少必要参数时说明具体缺项，可让用户直接回答；不要使用“工作流有待确认卡片”“你的消息没有改动工作流”等系统口吻拒绝交流。'
            .'未获得执行权限时，不声称正在生成、已经创建、已经提交或已经完成。确认应对应清楚的下一步动作，不能用一次确认授权全部后续步骤。'
            .'能力说明必须符合实际流程：先确认剧本，再准备主体和场景；主体三视图就绪后再推进分镜；分镜视频确认后插入画布，由用户在节点上自行生成；音频仅支持规划，不能承诺已生成。'
            .'用户说暂停、先聊聊、先别生成时只交流，不推进。用户说继续时结合当前上下文，只执行已授权且满足前置条件的步骤；存在歧义则先澄清。';
    }

    /** Bounded conversational memory retains recency without turning old replies into orders. */
    public static function recentDialogue(array $messages): array
    {
        $result=[];
        foreach (array_slice($messages,-12) as $message) {
            if (!in_array($message['role']??'', ['user','assistant'],true) || !is_string($message['content']??null)) continue;
            $text=$message['content'];
            $result[]=['role'=>$message['role'],'content'=>mb_substr($text,0,2000),
                'truncated'=>mb_strlen($text)>2000];
        }
        return $result;
    }

    /** Public progress derives from this run's frozen execution context, never thread state. */
    public static function activity(array $context): array
    {
        if (!empty($context['intent_routing'])) return ['kind'=>'conversation'];
        $workflow=(array)($context['workflow']??[]);
        $key=(string)($workflow['stage_state']['key']??'');
        if ($key==='' || in_array($key,['intake','complete'],true)) return ['kind'=>'conversation'];
        $stages=(array)($workflow['workflow_snapshot']['stages']??[]);
        $completed=(array)($workflow['stage_state']['completed']??[]);
        foreach ($stages as $stage) {
            if (($stage['key']??'')!==$key || !is_string($stage['label']??null)) continue;
            return ['kind'=>'stage','stage_key'=>$key,'stage_label'=>$stage['label'],
                'completed'=>count(array_filter($stages,static fn($item)=>in_array($item['key']??null,$completed,true))),
                'total'=>count($stages)];
        }
        return ['kind'=>'conversation'];
    }
}
