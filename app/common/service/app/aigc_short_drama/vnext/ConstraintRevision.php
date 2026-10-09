<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** An explicit, episode-local choice replaces only the selected requirements/events. */
final class ConstraintRevision
{
    public static function targets(array $context): array
    {
        $state=self::state($context);$items=[];
        foreach($state as $kind=>$rows) foreach($rows as $row) {
            if(!empty($row['episode_ids'])&&!in_array($context['episode_id']??'', $row['episode_ids'],true))continue;
            $items[]=['id'=>$row['id'],'kind'=>$kind,'label'=>$row['label']??$row['text']??''];
        }
        return ['version'=>1,'before_hash'=>ContentDocument::hash($state),'items'=>$items];
    }
    public static function apply(array $context,array $document,array $patch,string $revision,array $origin): array
    {
        ManualContentRevision::assertOrigin($origin);$state=self::state($context);
        if (array_diff(array_keys($patch),['before_hash','supersedes','text']) || ($patch['before_hash']??'')!==ContentDocument::hash($state)) throw new RuntimeException('本集要求已变化，请重新选择冲突内容',409);
        $ids=$patch['supersedes']??null;
        if(!is_array($ids)||array_values($ids)!==$ids||!$ids||count($ids)>64) throw new RuntimeException('请明确选择 1 至 64 项被替换的要求或事件',422);
        $seen=[];$selected=[];
        foreach($state as $kind=>$rows) foreach($rows as $row) {
            if(isset($seen[$row['id']])) throw new RuntimeException('本集要求标识不唯一',409);$seen[$row['id']]=true;
            if(in_array($row['id'],$ids,true)) {
                if(!empty($row['episode_ids'])&&!in_array($document['episode_id'],$row['episode_ids'],true))throw new RuntimeException('只能选择适用当前集的要求或事件',422);
                $selected[]=['kind'=>$kind,'record'=>$row];
            }
        }
        if(count($selected)!==count($ids)||count(array_unique($ids,SORT_REGULAR))!==count($ids)) throw new RuntimeException('选择的要求不属于当前集或目标重复',409);
        $text=ContentDocument::text($patch['text']??null,'constraint.text');
        if(mb_strlen($text,'UTF-8')>16000) throw new RuntimeException('单次确认要求不能超过 16000 字',413);
        $source=SourceTrace::capture('constraint_source_'.substr(ContentDocument::hash([$revision,$selected,$text]),0,24),$revision,$text,'inspiration');
        $source['origin']=['kind'=>'manual_constraint_choice']+$origin;
        $replacement=SourceTrace::requirements($source,[['start'=>0,'end'=>mb_strlen($text,'UTF-8'),'quote'=>$text,'episode_ids'=>[$document['episode_id']]]])[0];
        $replacement['kind']='manual_constraint';
        $proof=['schema_version'=>1,'episode_id'=>$document['episode_id'],'revision_id'=>$revision,'origin'=>$origin,
            'supersedes'=>$selected,'replacement'=>$replacement,'source'=>$source];$proof['receipt_hash']=ContentDocument::hash($proof);
        $receipts=$document['constraint_revision_receipts']??[];
        self::assertReceipts($receipts,$document['episode_id'],$origin);
        if(count($receipts)>=128) throw new RuntimeException('本集要求修订超过容量，请保留版本后整理要求',413);
        $receipts[]=$proof;$document['constraint_revision_receipts']=$receipts;
        foreach($state as $kind=>$rows)$state[$kind]=array_values(array_filter($rows,static fn($row)=>!in_array($row['id'],$ids,true)));
        $state['requirements'][]=$replacement;
        return ['document'=>$document,'requirements'=>$state['requirements'],'events'=>$state['events'],
            'sources'=>($context['sources']??[])+[$source['source_id']=>$source],
            'change'=>['id'=>$replacement['id'],'before'=>['selected'=>$selected],'after'=>['replacement'=>$replacement,'text'=>$text],'label'=>'本集要求冲突选择']];
    }
    public static function assertReceipts(array $receipts,string $episode,array $scope=[]): void
    {
        if(array_values($receipts)!==$receipts||count($receipts)>128) throw new RuntimeException('本集要求选择记录无效',409);
        foreach($receipts as $proof) {
            if(!is_array($proof)) throw new RuntimeException('本集要求选择证明无效',409);
            $unsigned=$proof;unset($unsigned['receipt_hash']);
            if(array_diff(array_keys($proof),['schema_version','episode_id','revision_id','origin','supersedes','replacement','source','receipt_hash'])
                ||($proof['schema_version']??0)!==1||($proof['episode_id']??'')!==$episode||($proof['receipt_hash']??'')!==ContentDocument::hash($unsigned)) throw new RuntimeException('本集要求选择来源或版本不符',409);
            if(!is_array($proof['origin']??null)||!is_array($proof['source']??null)||!is_array($proof['replacement']??null)) throw new RuntimeException('本集要求选择证明缺失',409);
            ManualContentRevision::assertOrigin($proof['origin']);ContentDocument::text($proof['revision_id']??null,'constraint.revision_id');
            foreach(['tenant_id','user_id'] as $key)if(isset($scope[$key])&&$scope[$key]!==$proof['origin'][$key])throw new RuntimeException('本集要求选择不属于当前用户',409);
            $source=$proof['source'];
            if(($source['version']??'')!==$proof['revision_id']||($source['hash']??'')!==hash('sha256',$source['text']??'')||($source['origin']??[])!==(['kind'=>'manual_constraint_choice']+$proof['origin']))throw new RuntimeException('本集确认要求原文不符',409);
            $expected=SourceTrace::requirements($source,[['start'=>0,'end'=>mb_strlen($source['text'],'UTF-8'),'quote'=>$source['text'],'episode_ids'=>[$episode]]])[0]+['kind'=>'manual_constraint'];
            if($proof['replacement']!==$expected)throw new RuntimeException('本集确认要求与引文不符',409);
            $selected=ContentDocument::rows($proof['supersedes']??null,'constraint.supersedes');$ids=[];
            if(!$selected||count($selected)>64)throw new RuntimeException('本集被替换要求缺失',409);
            foreach($selected as $entry) {
                if(array_diff(array_keys($entry),['kind','record'])||!in_array($entry['kind']??'',['requirements','events'],true)||!is_array($entry['record']??null))throw new RuntimeException('被替换要求类型无效',409);
                $id=ContentDocument::text($entry['record']['id']??null,'constraint.selected_id');if(isset($ids[$id]))throw new RuntimeException('被替换要求重复',409);$ids[$id]=true;
            }
        }
    }
    /** Recheck after an upstream edit: never suppress a newly changed requirement under an old choice. */
    public static function replay(array $context,array $document,array $scope=[]): array
    {
        $receipts=$document['constraint_revision_receipts']??[];self::assertReceipts($receipts,$document['episode_id'],$scope);
        foreach($receipts as $proof) {
            $replacement=$proof['replacement'];$active=array_column(array_merge($context['requirements']??[],$context['events']??[]),null,'id');
            if(isset($active[$replacement['id']])) {
                if($active[$replacement['id']]!==$replacement)throw new RuntimeException('本集确认要求已变化',409);
                foreach($proof['supersedes'] as $selected)if(isset($active[$selected['record']['id']]))throw new RuntimeException('本集旧要求仍在，需重新确认',409);
            } else {
                foreach($proof['supersedes'] as $selected) {
                    $id=$selected['record']['id'];
                    if(($active[$id]??null)!==$selected['record'])throw new RuntimeException('前置要求已变化，请重新确认本集冲突选择；正文保留',409);
                    $kind=$selected['kind'];$context[$kind]=array_values(array_filter($context[$kind]??[],static fn($row)=>$row['id']!==$id));
                }
                $context['requirements'][]=$replacement;
            }
            $source=$proof['source'];$existing=$context['sources'][$source['source_id']]??$source;
            if($existing!==$source)throw new RuntimeException('本集确认要求原文已变化',409);
            $context['sources'][$source['source_id']]=$source;
        }
        return $context;
    }
    public static function modelChoices(array $document): array
    {
        $receipts=$document['constraint_revision_receipts']??[];self::assertReceipts($receipts,$document['episode_id']);$result=[];
        foreach($receipts as $proof)$result[]=['episode_id'=>$proof['episode_id'],'supersedes'=>array_map(static fn($row)=>['id'=>$row['record']['id'],'text'=>$row['record']['text']??$row['record']['label']??''],$proof['supersedes']),
            'replacement_id'=>$proof['replacement']['id'],'text'=>$proof['source']['text']];
        return $result;
    }
    private static function state(array $context): array {return ['requirements'=>$context['requirements']??[],'events'=>$context['events']??[]];}
}
