<?php
namespace app\common\service\open_platform;
/** Publication evidence is a committed gateway receipt, never an administrator's assertion. */
final class OpenVerification
{
    public static function digest(array $contract): string
    {
        $config=OpenStore::json($contract['config']);unset($config['verification']);
        return OpenPolicy::fingerprint(['kind'=>$contract['kind'],'method'=>$contract['method'],'path'=>$contract['path'],'product_id'=>(int)($contract['product_id']??0),'config'=>$config]);
    }
    public static function assertReceipt(array $contract): array
    {
        $config=OpenStore::json($contract['config']);$id=(string)($config['verification']['request_id']??'');
        $row=$id!==''?OpenStore::table('request')->where('request_id',$id)->find():null;
        if(!$row||$row['status']!=='success'||(int)$row['active']!==0||!in_array($row['billing_status'],['settled','none'],true)||$row['response_blob']===''||(int)$row['response_code']<200||(int)$row['response_code']>=300)throw new OpenException('unverified_contract','请关联已成功完成并结算的真实网关请求');
        $snapshot=OpenStore::json($row['snapshot']);
        if(($snapshot['contract_digest']??'')!==self::digest($contract))throw new OpenException('verification_mismatch','验证请求与当前契约不同，请重新验收');
        if(!empty($config['billable'])){if($row['billing_status']!=='settled')throw new OpenException('unverified_contract','计费接口必须完成结算验收');self::assertLedger($row);}
        $exampleHash=OpenFiles::exampleFingerprint($config);
        if($row['payload_hash']!==$exampleHash)throw new OpenException('verification_example_mismatch','文档示例与已验证请求参数或素材不同');
        $body=OpenVault::open($row['response_blob']);$response=$config['responses'][(string)$row['response_code']]??null;
        if(!$response)throw new OpenException('verification_response_mismatch','文档未声明已验证的响应状态');
        $schema=$response['content']['application/json']['schema']??null;
        if($schema&&str_contains($row['response_type'],'application/json'))OpenSchema::validate(OpenStore::json($body),$schema);
        return ['request_id'=>$id,'verified_at'=>(int)$row['update_time'],'contract_digest'=>self::digest($contract),'evidence'=>'gateway:'.$id];
    }
    public static function assertLedger(array $row): void
    {
        $events=OpenStore::json($row['snapshot'])['point_events']??[];
        $totals=['user'=>0.,'tenant'=>0.];
        foreach($events as $event=>$amounts){
            [$kind,$source]=array_pad(explode(':',(string)$event,2),2,'');
            if(!in_array($kind,['consume','refund'],true)||$source==='')throw new OpenException('verification_ledger_mismatch','验证请求的积分流水关联不完整');
            $action=$kind==='consume'?2:1;
            foreach(['user','tenant'] as $side){
                $amount=round((float)($amounts[$side]??0),2);$q=\think\facade\Db::name($side==='user'?'user_account_log':'tenant_point_log')->where(['tenant_id'=>$row['tenant_id'],'source_sn'=>$source,'action'=>$action]);
                if($side==='user')$q->where('user_id',$row['user_id']);
                if(abs(round((float)$q->sum('change_amount'),2)-$amount)>0.000001)throw new OpenException('verification_ledger_mismatch','验证请求与独立积分流水不一致');
                $totals[$side]+=($kind==='consume'?1:-1)*$amount;
            }
        }
        foreach(['user','tenant'] as $side)if(abs(round($totals[$side],2)-(float)$row['actual_'.$side])>0.000001)throw new OpenException('verification_ledger_mismatch','验证请求的实际费用与流水不一致');
        $ids=OpenStore::json($row['consumption_ids']);
        if($ids){$records=\think\facade\Db::name('ai_consumption_log')->where(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id']])->whereIn('id',$ids)->field('id,billing_status')->select()->toArray();
            if(count($records)!==count(array_unique($ids)))throw new OpenException('verification_ledger_mismatch','验证请求关联了其他账户的消费');
            foreach($records as $record)if(!in_array($record['billing_status'],['settled','refunded'],true))throw new OpenException('verification_ledger_mismatch','验证请求仍有未完成的消费结算');
        }
    }

}
