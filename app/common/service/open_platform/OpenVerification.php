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
        if(!empty($config['billable'])&&$row['billing_status']!=='settled')throw new OpenException('unverified_contract','计费接口必须完成结算验收');
        // Upload examples identify reviewed fixtures without storing their content.
        $files=(array)($config['example_files']??[]);
        foreach($files as $name=>$file)if(!preg_match('/^[a-zA-Z0-9_\[\]-]+$/D',(string)$name)||!preg_match('/^[a-f0-9]{64}$/D',(string)($file['sha256']??''))||!isset($file['name'],$file['size'],$file['mime']))throw new OpenException('unverified_contract','上传示例缺少经过审核的素材指纹');
        $exampleHash=OpenPolicy::fingerprint(['parameters'=>(array)$config['example'],'files'=>$files]);
        if($row['payload_hash']!==$exampleHash)throw new OpenException('verification_example_mismatch','文档示例与已验证请求参数或素材不同');
        $body=OpenVault::open($row['response_blob']);$response=$config['responses'][(string)$row['response_code']]??null;
        if(!$response)throw new OpenException('verification_response_mismatch','文档未声明已验证的响应状态');
        $schema=$response['content']['application/json']['schema']??null;
        if($schema&&str_contains($row['response_type'],'application/json'))OpenSchema::validate(OpenStore::json($body),$schema);
        return ['request_id'=>$id,'verified_at'=>(int)$row['update_time'],'contract_digest'=>self::digest($contract),'evidence'=>'gateway:'.$id];
    }
}
