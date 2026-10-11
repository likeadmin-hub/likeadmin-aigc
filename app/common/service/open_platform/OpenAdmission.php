<?php
namespace app\common\service\open_platform;
use app\common\service\point\PointService;
use think\facade\Db;
final class OpenAdmission
{
    /** Durable limits are serialized on the tenant setting row, independent of cache. */
    public static function begin(array $key,string $method,string $path,array $params,array $operation,array $budget,string $idem,array $files=[]): array
    {
        $tenantId=(int)$key['tenant_id'];$userId=(int)$key['user_id'];$fingerprint=OpenFiles::fingerprint($params,$files);
        $idemHash=$idem!==''?hash('sha256',$method.' '.$path.' '.$idem):null;
        if(strlen($idem)>200)throw new OpenException('invalid_request','幂等键过长');
        return Db::transaction(function()use($key,$method,$path,$params,$operation,$budget,$tenantId,$userId,$fingerprint,$idemHash){
            OpenStore::table('setting')->where('tenant_id',$tenantId)->lock(true)->find();
            $config=!empty($operation['history'])?OpenStore::settings($tenantId):OpenStore::requireEnabled($tenantId);
            $current=OpenStore::table('key')->where('id',$key['id'])->lock(true)->find();if(!$current||(int)$current['status']!==1||((int)$current['expires_at']>0&&(int)$current['expires_at']<=time()))throw new OpenException('invalid_api_key','密钥已失效',401);
            if($idemHash!==null){$old=OpenStore::owner('request',$tenantId,$userId)->where('idempotency_hash',$idemHash)->find();if($old){if($old['payload_hash']!==$fingerprint)throw new OpenException('idempotency_conflict','同一幂等键的参数不同',409);return ['replay'=>true,'row'=>$old];}}
            $scopes=OpenStore::json($current['scopes']);$scope=$operation['kind']==='market'?(string)($operation['scope']??$operation['product_id']??'market'):$operation['app_code'];if($scopes&&!in_array($scope,$scopes,true)&&!($operation['kind']==='market'&&in_array('market',$scopes,true)))throw new OpenException('permission_denied','密钥无权调用该能力',403);
            $slot=intdiv(time(),60);$rpm=max(1,min((int)$config['rpm'],(int)$current['rpm']?:PHP_INT_MAX));
            if(OpenStore::table('request')->where(['tenant_id'=>$tenantId,'rate_slot'=>$slot])->count()>=(int)$config['rpm']||OpenStore::table('request')->where(['key_id'=>$key['id'],'rate_slot'=>$slot])->count()>=$rpm)throw new OpenException('rate_limit_exceeded','请求频率超限',429);
            $concurrency=max(1,min((int)$config['concurrency'],(int)$current['concurrency']?:PHP_INT_MAX));
            if(!empty($operation['billable'])&&(OpenStore::table('request')->where(['key_id'=>$key['id'],'active'=>1])->count()>=$concurrency||OpenStore::owner('request',$tenantId,$userId)->where('active',1)->count()>=(int)$config['user_concurrency']||OpenStore::table('request')->where(['tenant_id'=>$tenantId,'active'=>1])->count()>=(int)$config['tenant_concurrency']))throw new OpenException('concurrency_exceeded','并发调用数量超限',429);
            foreach(['daily_limit'=>strtotime('today'),'monthly_limit'=>strtotime(date('Y-m-01'))] as $field=>$start){$limit=(float)$current[$field];if($limit>0){$used=(float)OpenStore::table('request')->where('key_id',$key['id'])->where('create_time','>=',$start)->sum('actual_user')+(float)OpenStore::table('request')->where('key_id',$key['id'])->where('active',1)->sum('budget_user');if($used+(float)$budget['user']>$limit)throw new OpenException('key_quota_exceeded','密钥消费额度不足',402);}}
            $pendingUser=(float)OpenStore::owner('request',$tenantId,$userId)->where('active',1)->sum('budget_user');$pendingTenant=(float)OpenStore::table('request')->where(['tenant_id'=>$tenantId,'active'=>1])->sum('budget_tenant');
            if(!empty($operation['billable'])){try{PointService::assertCanConsumeAmounts($tenantId,$userId,$pendingTenant+(float)$budget['tenant'],$pendingUser+(float)$budget['user']);}catch(\RuntimeException $e){throw new OpenException('insufficient_quota',$e->getMessage(),402);}}
            $id='req_'.bin2hex(random_bytes(16));$row=['request_id'=>$id,'tenant_id'=>$tenantId,'user_id'=>$userId,'key_id'=>$key['id'],'method'=>$method,'path'=>$path,'app_code'=>$operation['kind']==='market'?'':$scope,'kind'=>$operation['kind'],'contract_id'=>(int)($operation['contract_id']??0),'idempotency_hash'=>$idemHash,'payload_hash'=>$fingerprint,'status'=>'accepted','billing_status'=>!empty($operation['billable'])?'pending':'none','budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant'],'rate_slot'=>$slot,'active'=>1,'consumption_ids'=>'[]','snapshot'=>OpenStore::encode(array_replace($budget['snapshot']??[],['_execution_version'=>2,'dispatch_started'=>false])),'response_blob'=>'','next_run_time'=>time()+60]+OpenStore::timestamp();
            $row['id']=OpenStore::table('request')->insertGetId($row);OpenStore::table('key')->where('id',$key['id'])->update(['last_used_at'=>time()]);return ['replay'=>false,'row'=>$row];
        });
    }

    public static function replay(array $key,string $method,string $path,array $params,string $idem,array $files=[]): ?array
    {
        if($idem==='')return null;if(strlen($idem)>200)throw new OpenException('invalid_request','幂等键过长');
        $hash=hash('sha256',$method.' '.$path.' '.$idem);
        $row=OpenStore::owner('request',(int)$key['tenant_id'],(int)$key['user_id'])->where('idempotency_hash',$hash)->find();
        if(!$row)return null;
        if($row['payload_hash']!==OpenFiles::fingerprint($params,$files))throw new OpenException('idempotency_conflict','同一幂等键的参数不同',409);
        $scopes=OpenStore::json($key['scopes']);$snapshot=OpenStore::json($row['snapshot']);$scope=$row['kind']==='market'?(string)($snapshot['product_id']??'market'):$row['app_code'];
        if($scopes&&!in_array($scope,$scopes,true)&&!($row['kind']==='market'&&in_array('market',$scopes,true)))throw new OpenException('permission_denied','密钥无权访问原请求',403);
        return $row;
    }
    public static function finish(string $id,array $fields): void { OpenStore::table('request')->where('request_id',$id)->update($fields+['update_time'=>time()]); }
}
