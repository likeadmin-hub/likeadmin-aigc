<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Only accepted world evidence can affect appearance; beliefs never become image state. */
final class SeriesVisualState
{
    private const PREDICATES = [
        'character' => ['appearance', 'clothing', 'hairstyle', 'makeup', 'injury'],
        'location' => ['scene_layout', 'scene_condition', 'lighting', 'time_of_day'],
        'prop' => ['prop_condition', 'prop_appearance'],
    ];
    public static function registry(array $document, array $ledger): array
    {
        $unsigned=$document;unset($unsigned['content_hash'],$unsigned['status'],$unsigned['transforms']);
        if (($document['content_hash']??'')!==ContentDocument::hash($unsigned)) throw new RuntimeException('制作状态正文校验失败',409);
        if ($ledger && (($ledger['content_hash']??'')!==NarrativeLedger::digest($ledger)
            || ($ledger['document_hash']??'')!==$document['content_hash'] || ($ledger['revision_id']??'')!==$document['revision_id'])) throw new RuntimeException('制作状态事实与正文版本不符',409);
        $result=[];
        foreach($document['entities'] as $entity) $result[$entity['entity_id']]=['entity_id'=>$entity['entity_id'],'kind'=>$entity['kind'],
            'name'=>$entity['canonical_name'],'identity_facts'=>$entity['identity_facts'],'appearance'=>$entity['description'],'visual_state'=>[]];
        foreach($ledger['state']??[] as $fact) {
            $id=$fact['subject_id'];$kind=$result[$id]['kind']??'';
            if (($fact['kind']??'')!=='world' || ($fact['certainty']??'')!=='confirmed' || ($fact['observer_id']??null)!==null
                || !in_array($fact['predicate'],self::PREDICATES[$kind]??[],true)) continue;
            $result[$id]['visual_state'][$fact['predicate']]=array_intersect_key($fact,array_flip(['fact_id','predicate','value','episode_id','revision_id','document_hash','evidence']));
        }
        foreach($result as &$state) {ksort($state['visual_state']);$state['state_hash']=ContentDocument::hash($state);}
        unset($state);return $result;
    }
    /** A published manual rename/appearance correction must not revert to the first outline. */
    public static function inherit(array $confirmed, array $published, array $identityReceipts = [], array $scope = []): array
    {
        EntityIdentityRevision::carry($identityReceipts, $published, $scope);
        $byId=array_column($published,null,'entity_id');
        foreach($confirmed as &$entity) {
            $latest=$byId[$entity['entity_id']]??null;if(!$latest) continue;
            if ($latest['kind']!==$entity['kind']) throw new RuntimeException('前集已登记主体类型与系列确认身份冲突，请明确修订',409);
            if (ContentDocument::hash($latest['identity_facts']) !== ContentDocument::hash($entity['identity_facts'])) {
                $receipt = $identityReceipts[$entity['entity_id']] ?? [];
                if (!$receipt || $receipt['base_facts_hash'] !== ContentDocument::hash($entity['identity_facts'])) throw new RuntimeException('前集已登记身份与系列确认身份冲突，请明确修订',409);
                $entity['identity_facts'] = $latest['identity_facts'];
            }
            $entity['aliases']=array_values(array_unique(array_merge($entity['aliases']??[],[$entity['name']],
                array_values(array_filter($latest['aliases'],static fn($alias)=>!preg_match('/^[clp]\d+$/D',$alias))))));
            $entity['name']=$latest['canonical_name'];$entity['description']=$latest['description'];
        }
        unset($entity);return $confirmed;
    }
}
