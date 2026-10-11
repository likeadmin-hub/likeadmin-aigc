<?php
namespace app\common\service\open_platform;
use app\common\model\tenant\Tenant;
use app\common\model\user\User;
use app\common\service\tenant\TenantContractService;
use think\facade\Db;
final class OpenKeyService
{
    public static function create(int $tenantId,int $userId,array $params): array
    {
        $config=OpenStore::requireEnabled($tenantId); if (empty($config['features']['keys'])) throw new OpenException('feature_disabled','密钥创建已关闭',403);
        $name=trim((string)($params['name']??'')); if ($name==='' || mb_strlen($name)>100) throw new OpenException('invalid_request','请输入不超过 100 字的密钥名称');
        $scopes=array_values(array_unique(array_map('strval',(array)($params['scopes']??[]))));
        foreach($scopes as $scope) if (!in_array($scope,['market','assets'],true) && !in_array($scope,$config['apps'],true) && !in_array($scope,$config['models'],true)) throw new OpenException('permission_denied','密钥权限超出本站开放范围',403);
        $ips=array_values((array)($params['ip_allowlist']??[])); foreach($ips as $ip) {[$address,$bits]=array_pad(explode('/',(string)$ip,2),2,null);$packed=@inet_pton($address);if($packed===false || ($bits!==null && (!ctype_digit($bits)||(int)$bits>strlen($packed)*8))) throw new OpenException('invalid_request','IP 白名单无效');}
        $expires=(int)($params['expires_at']??0);if($expires>0 && $expires<=time()) throw new OpenException('invalid_request','有效期必须晚于当前时间');
        $raw='sk-la-'.bin2hex(random_bytes(32));$row=['tenant_id'=>$tenantId,'user_id'=>$userId,'name'=>$name,'key_hash'=>hash('sha256',$raw),'prefix'=>substr($raw,0,14),'status'=>1,'scopes'=>OpenStore::encode($scopes),'ip_allowlist'=>OpenStore::encode($ips),'expires_at'=>$expires,'rpm'=>max(0,(int)($params['rpm']??0)),'concurrency'=>max(0,(int)($params['concurrency']??0)),'daily_limit'=>max(0,(float)($params['daily_limit']??0)),'monthly_limit'=>max(0,(float)($params['monthly_limit']??0)),'last_used_at'=>0]+OpenStore::timestamp();
        $id=OpenStore::table('key')->insertGetId($row);unset($row['key_hash']);return ['id'=>$id,'key'=>$raw]+$row;
    }
    public static function lists(int $tenantId,int $userId): array { $rows=OpenStore::owner('key',$tenantId,$userId)->withoutField('key_hash')->order('id','desc')->select()->toArray();foreach($rows as &$row){$row['scopes']=OpenStore::json($row['scopes']);$row['ip_allowlist']=OpenStore::json($row['ip_allowlist']);}return $rows; }
    public static function revoke(int $tenantId,int $userId,int $id,int $status=0): void { if(!in_array($status,[0,1,-1],true))throw new OpenException('invalid_request','密钥状态无效');if($status===1)OpenStore::requireEnabled($tenantId);$row=OpenStore::owner('key',$tenantId,$userId)->where('id',$id)->find();if(!$row|| (int)$row['status']===-1)throw new OpenException('not_found','密钥不存在或已撤销',404);OpenStore::owner('key',$tenantId,$userId)->where('id',$id)->update(['status'=>$status,'update_time'=>time()]); }
    public static function rotate(int $tenantId,int $userId,int $id): array { return Db::transaction(function()use($tenantId,$userId,$id){$old=OpenStore::owner('key',$tenantId,$userId)->where('id',$id)->lock(true)->find();if(!$old||(int)$old['status']===-1)throw new OpenException('not_found','密钥不存在',404);$old['scopes']=OpenStore::json($old['scopes']);$old['ip_allowlist']=OpenStore::json($old['ip_allowlist']);$new=self::create($tenantId,$userId,$old);self::revoke($tenantId,$userId,$id,-1);return $new;}); }
    public static function authenticate($request): array
    {
        $auth=(string)$request->header('authorization','');$raw=str_starts_with(strtolower($auth),'bearer ')?trim(substr($auth,7)):trim((string)$request->header('x-api-key',''));
        if(!str_starts_with($raw,'sk-la-'))throw new OpenException('invalid_api_key','API Key 无效',401);
        $key=OpenStore::table('key')->where('key_hash',hash('sha256',$raw))->find();if(!$key||(int)$key['status']!==1||((int)$key['expires_at']>0&&(int)$key['expires_at']<=time()))throw new OpenException('invalid_api_key','API Key 无效或已失效',401);
        // REMOTE_ADDR is trusted; do not accept spoofable forwarded IP headers here.
        if(!OpenPolicy::ipAllowed((string)$request->server('REMOTE_ADDR',''),OpenStore::json($key['ip_allowlist'])))throw new OpenException('permission_denied','调用 IP 不在白名单',403);
        $tenantId=(int)$key['tenant_id'];$request->tenantId=$tenantId;
        $tenant=Tenant::findOrEmpty($tenantId);if($tenant->isEmpty()||!TenantContractService::enforceTenantActive($tenant))throw new OpenException('permission_denied','租户不可用',403);
        $request->tenantSn=(string)$tenant['sn'];$request->tenantResolveMode='api_key';
        $user=User::where(['id'=>(int)$key['user_id'],'tenant_id'=>$tenantId])->findOrEmpty();if($user->isEmpty()||!empty($user['is_disable']))throw new OpenException('permission_denied','用户不可用',403);
        $request->source=\app\common\enum\AdminTerminalEnum::USER;
        $request->userId=(int)$key['user_id'];$request->userInfo=['user_id'=>(int)$key['user_id'],'tenant_id'=>$tenantId,'terminal'=>4];$request->openPlatformKey=$key;
        return $key;
    }
}
