<?php

declare(strict_types=1);
namespace app\common\service\license;

use app\common\service\update\UpdateLicenseService;
use app\common\service\update\UpdateSourceClient;
use think\facade\Db;
use WpOrg\Requests\Requests;

/** Global platform qualification. Never substitutes for tenant subscription or application access. */
class SiteLicenseAccessService
{
    private const TABLE = 'site_license_access_cache';

    public static function invalidate(): void
    {
        Db::name(self::TABLE)->where('id', '>', 0)->inc('generation')->update([
            'status'=>'invalidated', 'expires_at'=>0, 'next_retry_at'=>0, 'lock_until'=>0, 'lock_token'=>'',
        ]);
    }

    protected function context(): array
    {
        $context = (new UpdateLicenseService())->verifiedSiteContext();
        if (empty($context['verified'])) return $context;
        $source = UpdateSourceClient::getSource();
        $context['source'] = $source;
        $context['context_key'] = hash('sha256', SignedLicenseProtocol::json([
            $source['active_base_url'], hash('sha256',(string)$source['active_api_key']),
            hash('sha256',(string)$source['public_key']), hash('sha256',$context['raw_certificate']),
            $context['license_no'], $context['license_version'], $context['domain'],
            $context['machine_fingerprint_hash'], $context['ip'],
        ]));
        return $context;
    }

    public function snapshot(): array
    {
        $context = $this->context();
        $state = [
            'edition'=>$context['license_type'] ?? 'free', 'is_perpetual'=>($context['license_type'] ?? '')==='commercial',
            'certificate_status'=>$context['status'], 'can_customize'=>false, 'access_status'=>'pending',
            'reason_code'=>$context['error_code'] ?? 'LICENSE_SYNC_REQUIRED',
            'issuer'=>!empty($context['verified']) ? SignedLicenseProtocol::issuer($context['payload']) : [],
            'checked_at'=>0, 'issued_at'=>0, 'expires_at'=>0, 'next_refresh_at'=>0,
            'updates'=>\app\common\service\update\SystemUpdateProtocol::updates($context['payload'] ?? []),
        ];
        if (empty($context['verified'])) {
            $state['access_status']='unavailable';
            $state['reason_code']=$context['error_code'] ?? 'LICENSE_MISSING';
            return $state;
        }
        $row = $this->ensureRow($context);
        $state['checked_at']=(int)$row['checked_at'];
        $state['issued_at']=(int)$row['issued_at'];
        $state['expires_at']=(int)$row['expires_at'];
        $state['next_refresh_at']=(int)$row['next_retry_at'];
        $state['access_status']=$row['status'];
        $state['reason_code']=$row['error_code'];
        if ($context['status'] !== 'active') {
            $state['reason_code']='LICENSE_EXPIRED'; $state['access_status']='expired';
            return $state;
        }
        if (!in_array($row['status'], ['allowed','network_error'], true)) return $state;
        if ((int)$row['expires_at'] <= time()) {
            $state['access_status']='expired'; $state['reason_code']='LICENSE_ACCESS_EXPIRED';
            return $state;
        }
        try {
            $body=SignedLicenseProtocol::response($row['response_json'], $context['source']['public_key'], $context, $row['request_nonce']);
            $state['updates']=\app\common\service\update\SystemUpdateProtocol::updates($context['payload'], (array)($body['data']['updates'] ?? []));
            $state['can_customize']=$context['license_type']==='commercial' && ($body['code']??0)===1;
            $state['access_status']=$row['status']==='network_error'?'offline':'active';
            $state['reason_code']=$state['can_customize'] ? ($row['status']==='network_error'?'LICENSE_OFFLINE_CACHE':$row['error_code']) : 'COMMERCIAL_LICENSE_REQUIRED';
        } catch (\Throwable $e) {
            $state['access_status']='verification_failed'; $state['reason_code']=$e->getMessage();
        }
        return $state;
    }

    protected function ensureRow(array $context): array
    {
        $row=Db::name(self::TABLE)->where('context_key',$context['context_key'])->find();
        if (!$row) {
            try {
                Db::name(self::TABLE)->insert([
                    'context_key'=>$context['context_key'], 'license_no'=>$context['license_no'],
                    'license_version'=>$context['license_version'], 'domain'=>$context['domain'],
                    'status'=>'pending','error_code'=>'LICENSE_SYNC_REQUIRED','create_time'=>time(),
                ]);
            } catch (\Throwable $e) {
                if (!Db::name(self::TABLE)->where('context_key',$context['context_key'])->find()) throw $e;
            }
            $row=Db::name(self::TABLE)->where('context_key',$context['context_key'])->find();
        }
        return $row;
    }

    /** A previously issued package grant cannot override a newer trusted site refusal. */
    public function assertNoTrustedSiteDenial(): void
    {
        $context = $this->context();
        if (empty($context['verified'])) return;
        $row = Db::name(self::TABLE)->where('context_key',$context['context_key'])->find();
        if (!$row || $row['status'] !== 'blocked' || empty($row['response_json'])) return;
        try {
            $body = SignedLicenseProtocol::response($row['response_json'],$context['source']['public_key'],$context,$row['request_nonce']);
        } catch (\Throwable $e) {
            return; // An unverified error must never be presented as a trusted revocation.
        }
        $error = (string)($body['data']['error_code'] ?? '');
        if (($body['code'] ?? null) !== 1 && in_array($error,[
            'LICENSE_INVALID','LICENSE_EXPIRED','LICENSE_REDOWNLOAD_REQUIRED',
            'LICENSE_DOMAIN_FORBIDDEN','LICENSE_MACHINE_FORBIDDEN','LICENSE_IP_FORBIDDEN',
        ],true)) {
            throw new \app\common\service\update\UpdateProtocolException($error,'本站授权已被拒绝，请刷新授权后再安装');
        }
    }

    public function refresh(bool $force = false, bool $allowCertificateRefresh = true, bool $refreshCertificateFirst = false): array
    {
        $context=$this->context();
        if (empty($context['verified']) || $context['status']!=='active') return $this->snapshot();
        $row=$this->ensureRow($context);
        if (!$force && (int)$row['next_retry_at'] > time()) return $this->snapshot();
        $token=bin2hex(random_bytes(16));
        $acquired=Db::name(self::TABLE)->where('id',$row['id'])->where('lock_until','<=',time())
            ->where('generation',$row['generation'])->inc('generation')->update(['lock_token'=>$token,'lock_until'=>time()+60]);
        if (!$acquired) return $this->snapshot();
        $generation=(int)$row['generation']+1;
        $nonce=bin2hex(random_bytes(16));
        $requestStarted=time();
        try {
            if ($context['ip_required'] && $context['ip']==='') throw new \RuntimeException('LICENSE_SITE_IP_REQUIRED');
            if ($refreshCertificateFirst) {
                $this->refreshCertificate($context,$row,$token,$generation);
                if (($this->context()['context_key'] ?? '') !== $context['context_key']) return $this->refresh(true,false);
                $allowCertificateRefresh = false;
            }
            $response=$this->send('license/access',$context,$nonce);
            $body=SignedLicenseProtocol::response($response,$context['source']['public_key'],$context,$nonce);
            if (abs((int)$body['server_time']-time())>300 || $body['data']['issued_at'] < $requestStarted-300) {
                throw new \RuntimeException('LICENSE_RESPONSE_MISMATCH');
            }
            $data=$body['data'];
            if ((int)$data['issued_at'] < (int)$row['trusted_issued_at']) throw new \RuntimeException('LICENSE_RESPONSE_STALE');
            if (($body['code'] ?? 0) === 1) {
                $this->commit($context,$row,$token,$generation,[
                    'status'=>'allowed','error_code'=>'','error_message'=>'','response_json'=>$response,
                    'request_nonce'=>$nonce,'issued_at'=>$data['issued_at'],'trusted_issued_at'=>$data['issued_at'],
                    'expires_at'=>$data['expires_at'],'next_retry_at'=>min($data['refresh_after'],$data['issued_at']+86400),
                    'failure_count'=>0,
                ]);
            } else {
                $error=(string)$data['error_code'];
                // A tied timestamp may not undo a denial; updates never expire commercial qualification.
                if ($error==='LICENSE_UPDATE_EXPIRED' || in_array($error,['LICENSE_APP_FORBIDDEN','LICENSE_REQUIRED'],true)) {
                    $values=$this->failure($row,$error,'权益校验失败',false);
                    if (in_array($row['status'],['allowed','network_error'],true) && (int)$row['expires_at']>time()) {
                        $values['status']=$row['status']; $values['expires_at']=(int)$row['expires_at'];
                    }
                    $this->commit($context,$row,$token,$generation,$values);
                } else {
                    $holdLock=$error==='LICENSE_REDOWNLOAD_REQUIRED' && $allowCertificateRefresh;
                    $saved=$this->commit($context,$row,$token,$generation,array_merge($this->failure($row,$error,'授权同步失败',false),[
                        'trusted_issued_at'=>$data['issued_at'],'response_json'=>$response,'request_nonce'=>$nonce,
                    ]),$holdLock);
                    if ($saved && $error==='LICENSE_REDOWNLOAD_REQUIRED' && $allowCertificateRefresh) {
                        $row=Db::name(self::TABLE)->where('id',$row['id'])->find();
                        $this->refreshCertificate($context,$row,$token,$generation);
                        return $this->refresh(true,false);
                    }
                }
            }
        } catch (\Throwable $e) {
            $network=$e instanceof LicenseTransportUnavailable;
            $this->commit($context,$row,$token,$generation,$this->failure($row,$e->getMessage(),'授权同步失败',$network));
        }
        return $this->snapshot();
    }

    protected function failure(array $row,string $error,string $message,bool $keepGrant): array
    {
        $count=(int)$row['failure_count']+1;
        $delay=[60,300,1800,3600][min($count-1,3)];
        $canKeep=$keepGrant && in_array($row['status'],['allowed','network_error'],true) && (int)$row['expires_at']>time();
        return ['status'=>$canKeep?'network_error':'blocked','error_code'=>$error,'error_message'=>$message,
            'failure_count'=>$count,'next_retry_at'=>time()+$delay, 'expires_at'=>$canKeep?(int)$row['expires_at']:0];
    }

    protected function commit(array $context,array $row,string $token,int $generation,array $values,bool $holdLock = false): bool
    {
        // Configuration/certificate changes are checked again after the remote call.
        $current=$this->context();
        if (($current['context_key']??'')!==$context['context_key']) return false;
        return (bool)Db::name(self::TABLE)->where('id',$row['id'])->where('generation',$generation)->where('lock_token',$token)
            ->update(array_merge($values,['checked_at'=>time(),'update_time'=>time(),'lock_token'=>$holdLock?$token:'','lock_until'=>$holdLock?time()+60:0]));
    }

    protected function send(string $endpoint,array $context,string $nonce): string
    {
        $source=$context['source'];
        if (empty($source['public_key'])) throw new \RuntimeException('LICENSE_PUBLIC_KEY_MISSING');
        if (empty($source['active_base_url']) || empty($source['active_api_key'])) throw new \RuntimeException('LICENSE_SOURCE_MISSING');
        $base=rtrim($source['active_base_url'],'/');
        // Update-source URLs may already contain the protocol prefix.
        $url=str_ends_with($base,'/aigc/v1') ? $base.'/'.ltrim($endpoint,'/') : $base.UpdateSourceClient::path($endpoint);
        $params=['license'=>$context['raw_certificate'],'domain'=>$context['domain'],
            'machine_fingerprint_hash'=>$context['machine_fingerprint_hash'],'timestamp'=>time(),'nonce'=>$nonce];
        if ($context['ip']!=='') $params['ip']=$context['ip'];
        try {
            $response=Requests::post($url,['Content-Type'=>'application/json','Authorization'=>'Bearer '.$source['active_api_key']],
                SignedLicenseProtocol::json($params),['timeout'=>15,'verify'=>(bool)$source['ssl_verify']]);
        } catch (\WpOrg\Requests\Exception $e) {
            throw new LicenseTransportUnavailable('LICENSE_NETWORK_UNAVAILABLE',0,$e);
        }
        if (in_array($response->status_code,[502,503,504],true) && !str_contains((string)$response->body,'"signature"')) {
            throw new LicenseTransportUnavailable('LICENSE_NETWORK_UNAVAILABLE');
        }
        return (string)$response->body;
    }

    private function refreshCertificate(array $context,array $row,string $token,int $generation): void
    {
        $response=$this->send('license/refresh',$context,bin2hex(random_bytes(16)));
        $object=SignedLicenseProtocol::decode($response);
        SignedLicenseProtocol::verify($object,$context['source']['public_key']);
        if (($object->code??0)!==1 || abs((int)($object->server_time??0)-time())>300 || !isset($object->data->license)) {
            throw new \RuntimeException('LICENSE_REFRESH_FAILED');
        }
        $raw=SignedLicenseProtocol::json($object->data->license);
        $payload=SignedLicenseProtocol::certificate($raw,$context['source']['public_key']);
        if (($payload['license_no']??$payload['license_id']??'')!==$context['license_no']
            || (int)($payload['version']??0)<$context['license_version']
            || ($this->context()['context_key']??'')!==$context['context_key']) throw new \RuntimeException('LICENSE_REFRESH_MISMATCH');
        if ($payload === SignedLicenseProtocol::certificate($context['raw_certificate'],$context['source']['public_key'])) return;
        Db::transaction(function () use ($context,$raw,$row,$token,$generation) {
            $current=Db::name(self::TABLE)->where('id',$row['id'])->lock(true)->find();
            if ((int)$current['generation']!==$generation || $current['lock_token']!==$token
                || ($this->context()['context_key']??'')!==$context['context_key']) throw new \RuntimeException('LICENSE_REFRESH_STALE');
            (new UpdateLicenseService())->storeCertificate($raw,true);
        });
    }
}
