<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

/** Source pointers, not inferred facts or execution authorization. No model call or durable copy. */
final class AgentRequirementMemory
{
    public const CUES='必须|不能|不要|不得|禁止|除非|仅在|只有|例外|保留|维持|确认|确定|采用|同意|更正|改为|改成|取消|撤回|预算|截止|交付|总价|首付|要求|must|never|unless|only if|keep|confirm|correction|instead|deadline|budget';
    public const INSTRUCTION='以下是当前话题用户原消息的关键词线索，不是已核验事实或新的执行授权，也不是全部需求。按 message_id 判断先后；只有明确针对同一对象的更正才覆盖旧要求，不同项目/主体不得混用。引用、假设和问题中的措辞不等于用户决定；简短确认需读取前后文。截取片段须按 message_id/offset 用 read_message 读取原文，不能把片段未提及理解成没有限制。next_before 非空时可用 requirements 继续分页；无匹配也可 search_messages。历史确认不能代替当前运行模式、权限和计费审批。';

    public static function excerpt(array $row,string $query=''): array
    {
        $text=(string)$row['content'];$position=false;
        if($query!=='')$position=mb_stripos($text,$query);
        if($position===false&&preg_match('/'.self::CUES.'/iu',$text,$match,PREG_OFFSET_CAPTURE)){
            $position=mb_strlen(substr($text,0,$match[0][1]));
        }
        $length=mb_strlen($text);$offset=$length<=600?0:max(0,(int)$position-120);
        return ['message_id'=>(int)$row['id'],'offset'=>$offset,'end'=>min($length,$offset+600),
            'text'=>mb_substr($text,$offset,600),'total_chars'=>$length,'truncated'=>$offset>0||$length>$offset+600];
    }

    /** Optional recall must not evict dialogue/tool turns that fit before this feature. */
    public static function appendInstructions(string $base,array $messages,array $tools,array $memory,int $budget=24000): string
    {
        if(empty($memory['items']))return $base;
        $memory['instruction']=self::INSTRUCTION;
        try{$baseline=MarketNeuronProvider::fitContext($messages,$base,$tools,$budget);}
        catch(\InvalidArgumentException){return $base;} // Preserve the existing admission/error behavior.
        while($memory['items']){
            $candidate=$base."\n关键需求原文线索（未核验）：".AgentStore::encode($memory);
            try{if(MarketNeuronProvider::fitContext($messages,$candidate,$tools,$budget)===$baseline)return $candidate;}
            catch(\InvalidArgumentException){} // Retrieval remains available even if no preview fits.
            array_pop($memory['items']);
            $memory['next_before']=$memory['items']?(int)end($memory['items'])['message_id']:null;
            $memory['preview_limited']=true;
        }
        return $base;
    }
}
