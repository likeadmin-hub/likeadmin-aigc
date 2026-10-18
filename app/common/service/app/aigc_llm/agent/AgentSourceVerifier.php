<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

/** Exact quotation checks against live, authorized sources; never a semantic verdict or approval. */
final class AgentSourceVerifier
{
    public static function verify(array $run,array $input): array
    {
        $file=(string)($input['file_id']??'');$message=(int)($input['message_id']??0);
        $quote=(string)($input['quote']??'');$offset=max(0,(int)($input['offset']??0));
        if(($file!=='')===($message>0))throw new \InvalidArgumentException('请选择一条原消息或一份素材核对，不能同时指定');
        if(trim($quote)===''||mb_strlen($quote)>1000)throw new \InvalidArgumentException('请提供1000字以内的原文引句，不能使用摘要代替');
        // Include the ordinary page boundary plus quote/condition context in ONE source read.
        $windowChars=AgentContextService::READ_CHARS+mb_strlen($quote)-1+240;
        if($file!==''){
            $asset=AgentContextService::authorizedAsset($run,$file);
            $pdf=strtolower(pathinfo($asset['name'],PATHINFO_EXTENSION))==='pdf';
            if(!$pdf&&!AgentDocumentReader::supports($asset['name']))throw new \InvalidArgumentException('文字引文核对支持文档、PDF可提取文字和原消息；画面、扫描文字及声音不能据此自动核验');
            $page=AgentContextService::readAsset($run,$file,$offset,0,false,$windowChars);
            if(empty($page['content_available']))throw new \InvalidArgumentException($page['instruction']??'原文不可读取');
            $source=['kind'=>'asset','file_id'=>$file,'name'=>$page['name'],'unit'=>isset($page['total_pages'])?'页':'字','offset'=>$page['offset']];
        }else{
            $page=AgentContextService::readMessage($run,$message,$offset,$windowChars);
            $source=['kind'=>'message','message_id'=>$message,'role'=>$page['role'],'unit'=>'字','offset'=>$page['offset']];
        }
        $text=(string)$page['content'];$windowEnd=$source['offset']+mb_strlen($text);
        $position=mb_strpos($text,$quote);$count=$position===false?0:1;
        // Only distinguish unique from ambiguous; do not repeatedly scan a large PDF for every match.
        if($position!==false&&$position+1<mb_strlen($text)&&mb_strpos($text,$quote,$position+1)!==false)$count=2;
        $contextOffset=$position===false?0:max(0,$position-240);
        $context=mb_substr($text,$contextOffset,$position===false?600:mb_strlen($quote)+480);
        $source+=['text_offset'=>$position===false?null:$position,'character_offset'=>$position===false||$source['unit']!=='字'?null:$source['offset']+$position,
            'window_sha256'=>hash('sha256',$text),'window_end'=>$source['unit']==='字'?$windowEnd:($page['next_offset']??$page['total_pages'])];
        if($source['unit']==='页')$source+=['page_start'=>$source['offset']+1,'page_end'=>$source['window_end']];
        return ['matched'=>$position!==false,'check'=>'exact_quote','semantic_verified'=>false,'occurrences_in_window'=>$count,'count_is_lower_bound'=>$count===2,
            'quote'=>$quote,'source'=>$source,'context'=>$context,'context_text_offset'=>$contextOffset,
            'next_offset'=>$page['next_offset'],'instruction'=>'matched 仅说明引句与当前读取窗口中的文字一致，不证明结论正确、条件满足或执行已授权。必须检查上下文中的否定、例外、主体和后文更正；重复引句需进一步定位。未匹配只说明此窗口未找到，不代表全文没有。原消息若为助手回复不能作为任务/计费事实依据。PDF只核对提取文字，不核验扫描画面；来源变更后需重新核对。向用户引用时使用文件名/页码或原话，不展示内部标识。'];
    }
}
