<?php
namespace app\common\service\open_platform;

/** A login-only description of one owned receipt's existing query, never an executor. */
final class OpenHistoryDebug
{
    public static function descriptor(int $tenantId,int $userId,string $requestId): array
    {
        if($tenantId<=0||$userId<=0||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$requestId))throw new OpenException('not_found','历史任务不可查询',404);
        $row=OpenStore::owner('request',$tenantId,$userId)->where(['request_id'=>$requestId,'kind'=>'market'])->find();
        if(!$row)throw new OpenException('not_found','历史任务不可查询',404);
        $contract=OpenStore::json($row['snapshot'])['contract']??[];$task=(string)$row['upstream_task_id'];
        if(($contract['query_path']??'')!=='/api/v1/tasks/{task_id}'||!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.:-]{0,254}$/D',$task))throw new OpenException('not_found','历史任务尚无已确认的查询映射',404);
        $typed=json_decode($row['snapshot']);$rawConfig=$typed->published_contract->config??$typed->contract??null;
        $publicConfig=$rawConfig instanceof \stdClass?OpenContracts::decodeConfig(OpenStore::encode($rawConfig)):(is_string($rawConfig)?OpenContracts::decodeConfig($rawConfig):$contract);
        if(($publicConfig['query_path']??'')!==$contract['query_path'])throw new OpenException('not_found','历史任务查询契约不一致',404);
        $responses=OpenPolicy::publicData(OpenDocuments::publicResponses(['responses'=>$publicConfig['query_responses']??[],'compiler'=>$publicConfig['compiler']??[]]));
        // Schema examples describe shape, not the supplier's prices or credentials.
        foreach($responses as $status=>$response)foreach($response['content']??[] as $type=>$content){
            foreach($content['examples']??[] as $name=>$example){if(!array_key_exists('value',$example))continue;$public=$type==='application/json'?self::publicExample($example['value']):null;if($public===null)unset($responses[$status]['content'][$type]['examples'][$name]);else $responses[$status]['content'][$type]['examples'][$name]['value']=$public;}
            if(array_key_exists('example',$content)){$public=$type==='application/json'?self::publicExample($content['example']):null;if($public===null)unset($responses[$status]['content'][$type]['example']);else $responses[$status]['content'][$type]['example']=$public;}
        }
        return ['history'=>true,'request_id'=>$requestId,'kind'=>'market','code'=>'history.market.'.$requestId,'name'=>'历史任务结果查询','method'=>'GET','path'=>'/api/v1/tasks/'.$task,'executor'=>'json','billable'=>false,'schema'=>['type'=>'object','properties'=>new \stdClass(),'additionalProperties'=>false],'example'=>new \stdClass(),'responses'=>$responses,'published'=>true,'available'=>true,'description'=>'查询不另收费；可能确认原任务并结算或退回原预占积分。'];
    }
    private static function publicExample(mixed $value): ?\stdClass
    {
        if(!is_array($value)&&!($value instanceof \stdClass))return null;
        $scrub=static function($item)use(&$scrub){if($item instanceof \stdClass){$clean=new \stdClass();foreach(OpenPolicy::publicData((array)$item) as $name=>$child)$clean->$name=$scrub($child);return $clean;}if(is_array($item)){$item=OpenPolicy::publicData($item);foreach($item as $name=>$child)$item[$name]=$scrub($child);}return $item;};
        $value=$scrub($value);$body=OpenStore::encode(is_array($value)&&!array_is_list($value)?(object)$value:$value);
        return OpenMarketResponse::bodySupported($body)?json_decode(OpenMarketResponse::project($body,['charged'=>0,'reserved'=>0]),false):null;
    }
}
