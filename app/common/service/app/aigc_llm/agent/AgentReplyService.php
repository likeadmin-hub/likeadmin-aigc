<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

/** Durable user-visible text grouped by execution stage, not by provider/tool call. */
final class AgentReplyService
{
    private static function rows(array $run): array
    {
        $preferences=AgentStore::json($run['preferences']);
        if(isset($preferences['_reply_stages']))return $preferences['_reply_stages'];
        // Older sequences retained the earlier replies and the last stage boundary.
        $sequence=AgentSequenceService::state($run);$prefix=AgentSequenceService::prefix($run);
        $rows=[];
        if($prefix!=='')$rows[]=['reply'=>rtrim($prefix),'step_floor'=>0,'artifact_floor'=>0,'streaming'=>false];
        $reply=(string)$run['reply'];
        $rows[]=['reply'=>$prefix!==''&&str_starts_with($reply,$prefix)?substr($reply,strlen($prefix)):$reply,
            'step_floor'=>(int)($sequence['step_floor']??0),'artifact_floor'=>(int)($sequence['artifact_floor']??0),
            'streaming'=>$run['phase']==='chat'&&in_array($run['status'],['queued','running'],true)];
        return $rows;
    }
    public static function begin(array $run): string
    {
        $current=AgentStore::run((int)$run['tenant_id'],(int)$run['user_id'],(int)$run['id']);
        $preferences=AgentStore::json($current['preferences']);$rows=$preferences['_reply_stages']??[];
        if(!$rows&&AgentSequenceService::prefix($current)!==''){
            $rows=self::rows($current);array_pop($rows);
        }
        if(!$rows||!end($rows)['streaming'])$rows[]=['reply'=>'',
            'step_floor'=>(int)AgentStore::table('step')->where('run_id',$run['id'])->max('id'),
            'artifact_floor'=>(int)AgentStore::table('artifact')->where('run_id',$run['id'])->max('id'),'streaming'=>true];
        $preferences['_reply_stages']=$rows;
        AgentStore::update($run,['preferences'=>AgentStore::encode($preferences)]);
        return (string)end($rows)['reply'];
    }
    public static function append(string $before,string $text): string
    {
        if($text==='')return $before;
        return $before===''?$text:rtrim($before)."\n\n".$text;
    }
    public static function publish(array $run,string $text,bool $complete=false): void
    {
        $current=AgentStore::run((int)$run['tenant_id'],(int)$run['user_id'],(int)$run['id']);
        $preferences=AgentStore::json($current['preferences']);$rows=$preferences['_reply_stages'];
        $index=count($rows)-1;$rows[$index]['reply']=$text;$rows[$index]['streaming']=!$complete;
        $preferences['_reply_stages']=$rows;
        $reply=implode("\n\n",array_filter(array_column($rows,'reply'),static fn($s)=>$s!==''));
        AgentStore::update($run,['reply'=>$reply,'preferences'=>AgentStore::encode($preferences)]);
        AgentStore::event($run,'message.snapshot',['content'=>$reply]);
    }
    public static function view(array $run,array $steps): array
    {
        $rows=self::rows($run);
        $sequence=AgentSequenceService::state($run);
        if(isset(AgentStore::json($run['preferences'])['_reply_stages'])&&!empty($sequence['active'])
            &&$run['phase']==='chat'&&in_array($run['status'],['queued','running'],true)
            &&!end($rows)['streaming']&&((int)end($rows)['step_floor']<(int)$sequence['step_floor']||(int)end($rows)['artifact_floor']<(int)$sequence['artifact_floor'])){
            $rows[]=['reply'=>'','step_floor'=>(int)$sequence['step_floor'],'artifact_floor'=>(int)$sequence['artifact_floor'],'streaming'=>true];
        }
        $artifacts=AgentStore::owner('artifact',(int)$run['tenant_id'],(int)$run['user_id'])->where('run_id',$run['id'])->field('id')->select()->toArray();
        $out=[];
        foreach($rows as $i=>$row){
            $next=$rows[$i+1]??[];
            $ids=static fn($items,$floor,$ceiling)=>array_values(array_map('intval',array_column(array_filter($items,static fn($item)=>(int)$item['id']>$floor&&(int)$item['id']<=$ceiling),'id')));
            $out[]=['id'=>$i+1,'reply'=>$row['reply'],
                'step_ids'=>$ids($steps,(int)$row['step_floor'],(int)($next['step_floor']??PHP_INT_MAX)),
                'artifact_ids'=>$ids($artifacts,(int)$row['artifact_floor'],(int)($next['artifact_floor']??PHP_INT_MAX)),
                'streaming'=>(bool)$row['streaming']&&$run['phase']==='chat'&&in_array($run['status'],['queued','running'],true)];
        }
        return $out;
    }
}
