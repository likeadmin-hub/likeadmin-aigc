<?php
namespace app\common\service\open_platform;
/** Privileged draft verification; caller still needs its own Key, permissions and balance. */
final class OpenContractPreview
{
    public static function issue(int $contractId,int $keyId,string $path=''): array
    {
        $contract=OpenContracts::detail($contractId);OpenContracts::validate($contract);
        $key=OpenStore::table('key')->where(['id'=>$keyId,'status'=>1])->find();
        if($contract['status']!=='draft'||!$key||((int)$key['expires_at']>0&&(int)$key['expires_at']<=time()))throw new OpenException('invalid_verification','请选择草稿和有效的测试密钥');
        $path=$path?:$contract['path'];if(str_contains($path,'{')||OpenPolicy::matches($contract['path'],$path)===null)throw new OpenException('invalid_verification','请提供与契约匹配的实际验收路径');
        $config=OpenStore::json($contract['config']);$idem='verify_'.bin2hex(random_bytes(16));$expires=time()+300;
        $claims=['contract_id'=>$contractId,'contract_digest'=>OpenVerification::digest($contract),'key_id'=>$keyId,'tenant_id'=>(int)$key['tenant_id'],'user_id'=>(int)$key['user_id'],'method'=>$contract['method'],'path'=>$path,'idempotency_key'=>$idem,'payload_hash'=>OpenPolicy::fingerprint(['parameters'=>(array)$config['example'],'files'=>(array)($config['example_files']??[])]),'expires_at'=>$expires];
        return ['preview_token'=>OpenVault::seal(OpenStore::encode($claims)),'idempotency_key'=>$idem,'expires_at'=>$expires,'method'=>$contract['method'],'path'=>$path,'example'=>$config['example'],'tenant_id'=>$key['tenant_id'],'user_id'=>$key['user_id'],'billable'=>(bool)$config['billable']];
    }
    public static function resolve(string $token,array $key,string $method,string $path,array $params,string $idem,array $files=[]): array
    {
        try{if(strlen($token)>4096)throw new \RuntimeException('Oversized proof');$claim=OpenStore::json(OpenVault::open($token));}catch(\Throwable){throw new OpenException('invalid_verification','验收凭据无效',403);}
        foreach(['key_id','tenant_id','user_id'] as $field)if((int)($claim[$field]??0)!==(int)$key[$field])throw new OpenException('invalid_verification','验收凭据不属于当前测试账户',403);
        if((int)($claim['expires_at']??0)<time()||($claim['method']??'')!==$method||($claim['path']??'')!==$path||$idem===''||($claim['idempotency_key']??'')!==$idem)throw new OpenException('invalid_verification','验收凭据已过期或请求不匹配',403);
        $contract=OpenContracts::detail((int)$claim['contract_id']);$config=OpenStore::json($contract['config']);$typed=$method==='GET'?OpenSchema::query($params,$config['input']):$params;
        if($contract['status']!=='draft'||!hash_equals((string)$claim['contract_digest'],OpenVerification::digest($contract))||!hash_equals((string)$claim['payload_hash'],OpenFiles::fingerprint($typed,$files)))throw new OpenException('invalid_verification','草稿或示例已变化，请重新获取验收凭据',403);
        OpenContracts::validate($contract);$bindings=OpenPolicy::matches($contract['path'],$path);if($bindings===null||!OpenContracts::selected($config,$typed))throw new OpenException('invalid_verification','请求不符合草稿契约',403);
        $identity=['kind'=>$contract['kind'],'contract_id'=>$contract['id'],'product_id'=>$contract['product_id'],'version'=>$contract['version'],'code'=>$contract['code'],'path'=>$contract['path'],'method'=>$method,'input'=>$config['input'],'params'=>$bindings];
        return $contract['kind']==='tool'?array_replace(OpenToolRegistry::match($method,$path),$identity):$identity+$config;
    }
}
