<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** The server records every provided fact, not a model's claim that it ignored a fact. */
final class FactConsumption
{
    public const VERSION=1;
    public static function emptyIndex(): array
    {
        $index=['schema_version'=>1,'document_hash'=>'','ledger_hash'=>'','entities'=>[],'facts'=>[]];
        $index['index_hash']=ContentDocument::hash($index);return $index;
    }
    public static function index(array $document,array $ledger): array
    {
        $unsigned=$document;unset($unsigned['content_hash'],$unsigned['status'],$unsigned['transforms']);
        if(($document['content_hash']??'')!==ContentDocument::hash($unsigned)||($ledger['content_hash']??'')!==NarrativeLedger::digest($ledger)
            ||($ledger['document_hash']??'')!==$document['content_hash']||($ledger['revision_id']??'')!==$document['revision_id'])throw new RuntimeException('事实依赖索引与发布版本不符',409);
        $entities=[];$facts=[];
        foreach($document['entities'] as $entity) {
            // Short model aliases are transport references; stable IDs, names and every identity/appearance field remain pinned.
            $projection=array_intersect_key($entity,array_flip(['entity_id','kind','canonical_name','description','identity_facts','aliases']));
            $projection['aliases']=array_values(array_filter($entity['aliases'],static fn($alias)=>!preg_match('/^[clp]\d+$/D',$alias)));
            $entities[$entity['entity_id']]=ContentDocument::hash($projection);
        }
        foreach($ledger['state'] as $fact) {
            $id=ContentDocument::text($fact['fact_id']??null,'consumption.fact_id');
            if(isset($facts[$id]))throw new RuntimeException('事实依赖标识不唯一',409);
            $facts[$id]=['subject_id'=>$fact['subject_id'],'observer_id'=>$fact['observer_id'],'kind'=>$fact['kind'],'hash'=>ContentDocument::hash($fact)];
        }
        ksort($entities);ksort($facts);
        $index=['schema_version'=>1,'document_hash'=>$document['content_hash'],'ledger_hash'=>$ledger['content_hash'],'entities'=>$entities,'facts'=>$facts];
        $index['index_hash']=ContentDocument::hash($index);return $index;
    }
    public static function assertIndex(array $index,?array $canonical=null): void
    {
        $unsigned=$index;unset($unsigned['index_hash']);
        if(array_diff(array_keys($index),['schema_version','document_hash','ledger_hash','entities','facts','index_hash'])||($index['schema_version']??0)!==1
            ||($index['index_hash']??'')!==ContentDocument::hash($unsigned)||!is_array($index['entities']??null)||!is_array($index['facts']??null))throw new RuntimeException('事实依赖索引无效',409);
        foreach($index['entities'] as $id=>$hash) {ContentDocument::text($id,'consumption.entity_id');self::digest($hash);}
        foreach($index['facts'] as $id=>$fact) {
            ContentDocument::text($id,'consumption.fact_id');
            if(!is_array($fact)||array_diff(array_keys($fact),['subject_id','observer_id','kind','hash'])||!in_array($fact['kind']??'',['world','belief'],true))throw new RuntimeException('事实依赖条目无效',409);
            ContentDocument::text($fact['subject_id']??null,'consumption.subject_id');
            if($fact['kind']==='world'&&($fact['observer_id']??null)!==null)throw new RuntimeException('世界事实依赖不能混入知情者',409);
            if($fact['kind']==='belief')ContentDocument::text($fact['observer_id']??null,'consumption.observer_id');self::digest($fact['hash']??null);
        }
        if($canonical&&(($index['document_hash']??'')!==($canonical['document_hash']??null)||($index['ledger_hash']??'')!==($canonical['ledger_hash']??null)))throw new RuntimeException('事实索引引用其他发布版本',409);
    }
    public static function capture(array $index,array $entityIds): array
    {
        self::assertIndex($index);$entityIds=array_values(array_unique($entityIds));sort($entityIds,SORT_STRING);
        foreach($entityIds as $id)ContentDocument::text($id,'consumption.entity_id');
        $facts=self::select($index,$entityIds);
        $result=['schema_version'=>1,'entity_ids'=>$entityIds,'entities'=>$index['entities'],'facts'=>$facts];
        $result['consumption_hash']=ContentDocument::hash($result);return $result;
    }
    public static function matches(array $consumption,array $index): bool
    {
        self::assertIndex($index);self::assertReceipt($consumption);
        // Registry additions/removals also matter: do not infer that an absent identity was irrelevant.
        return $consumption['entities']===$index['entities']&&$consumption['facts']===self::select($index,$consumption['entity_ids']);
    }
    public static function assertReceipt(array $receipt): void
    {
        $unsigned=$receipt;unset($unsigned['consumption_hash']);
        if(array_diff(array_keys($receipt),['schema_version','entity_ids','entities','facts','consumption_hash'])||($receipt['schema_version']??0)!==1
            ||($receipt['consumption_hash']??'')!==ContentDocument::hash($unsigned)||!is_array($receipt['entity_ids']??null)
            ||array_values($receipt['entity_ids'])!==$receipt['entity_ids']||count(array_unique($receipt['entity_ids'],SORT_REGULAR))!==count($receipt['entity_ids']))throw new RuntimeException('事实消费收据无效',409);
        foreach($receipt['entity_ids'] as $id)ContentDocument::text($id,'consumption.entity_id');
        $index=['schema_version'=>1,'document_hash'=>'','ledger_hash'=>'','entities'=>$receipt['entities']??null,'facts'=>$receipt['facts']??null];$index['index_hash']=ContentDocument::hash($index);self::assertIndex($index);
    }
    public static function applicable(array $canonical,array $dependencies,?array $previousIndex): bool
    {
        if(array_key_exists('fact_consumption',$canonical)) {
            if(!is_array($canonical['fact_consumption']))throw new RuntimeException('事实消费收据无效',409);
            self::assertReceipt($canonical['fact_consumption']);
        }
        if(($canonical['dependencies']??[])===$dependencies)return true;
        $receipt=$canonical['fact_consumption']??null;
        return $previousIndex!==null&&is_array($receipt)&&self::matches($receipt,$previousIndex);
    }
    private static function select(array $index,array $ids): array
    {
        return array_filter($index['facts'],static fn($fact)=>$fact['subject_id']==='world'||in_array($fact['subject_id'],$ids,true)||in_array($fact['observer_id'],$ids,true));
    }
    private static function digest($value): void {if(!is_string($value)||!preg_match('/^[a-f0-9]{64}$/D',$value))throw new RuntimeException('事实消费哈希无效',409);}
}
