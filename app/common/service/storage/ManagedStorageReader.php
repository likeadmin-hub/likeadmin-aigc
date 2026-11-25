<?php
declare(strict_types=1);
namespace app\common\service\storage;

use app\common\service\FileService;
use app\common\service\open_platform\OpenSafeHttp;
use RuntimeException;

/** Reads an already-authorized database receipt from its exact storage configuration. No browser URLs. */
final class ManagedStorageReader
{
    public static function read(array $receipt,int $tenant,int $maxBytes,int $timeout=30): string
    {
        $scope=(string)($receipt['storage_scope']??'');$engine=(string)($receipt['storage_engine']??'');$key=(string)($receipt['uri']??'');
        if(!in_array($scope,['tenant','platform'],true)||!in_array($engine,['aliyun','qcloud','qiniu'],true)
            ||!str_starts_with($key,'uploads/')||str_contains($key,'//')||preg_match('~[\x00-\x20\x7f\\\\%?#:]|(?:^|/)\.\.?(/|$)~',$key)
            ||$maxBytes<=0||$maxBytes>268435456)throw new RuntimeException('托管素材身份无效');
        if($scope==='platform'&&($public=\app\common\service\app\SharedInspirationMedia::url($key,$engine,(string)($receipt['storage_domain']??'')))){
            $bytes=OpenSafeHttp::get($public,$maxBytes,$timeout)['body'];
            if(!is_string($bytes)||$bytes===''||strlen($bytes)>$maxBytes)throw new RuntimeException('共享素材读取失败或超出限制');
            return $bytes;
        }
        $config=StorageConfigService::getStoredFileConfig($tenant,$scope,$engine);
        $storage=(array)($config['engine'][$engine]??[]);
        if(($config['scope']??'')!==$scope||($config['default']??'')!==$engine
            ||rtrim((string)($storage['domain']??''),'/')!==rtrim((string)($receipt['storage_domain']??''),'/'))throw new RuntimeException('素材原存储配置已变化，请重新上传');
        if($engine==='aliyun'){
            // A configured bucket/key is an owned storage operation, like upload; it is never a URL proxy.
            $client=new \OSS\OssClient((string)$storage['access_key'],(string)$storage['secret_key'],(string)$storage['domain'],true);
            StorageOperationTimeout::oss($client,['operation_timeout'=>$timeout]);
            $meta=$client->getObjectMeta((string)$storage['bucket'],$key);
            $length=$meta['content-length']??$meta['Content-Length']??null;
            if(!is_numeric($length)||(int)$length<=0||(int)$length>$maxBytes)throw new RuntimeException('素材大小超出读取限制');
            $bytes=$client->getObject((string)$storage['bucket'],$key,[\OSS\OssClient::OSS_RANGE=>'0-'.$maxBytes]);
        }elseif($engine==='qcloud'){
            $bytes=self::readCos($storage,$key,$maxBytes,$timeout);
        }else{
            $signed=StorageSignedUrlService::resolve($key,$scope,$engine,(string)$receipt['storage_domain'],$tenant);
            $bytes=OpenSafeHttp::get($signed?:FileService::format((string)$receipt['storage_domain'],$key),$maxBytes,$timeout)['body'];
        }
        if(!is_string($bytes)||$bytes===''||strlen($bytes)>$maxBytes)throw new RuntimeException('素材读取失败或超出限制');
        return $bytes;
    }

    private static function readCos(array $storage, string $key, int $maxBytes, int $timeout): string
    {
        $bucket = (string)($storage['bucket'] ?? '');
        $region = (string)($storage['region'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,61}-[1-9][0-9]{4,19}$/D', $bucket)
            || !preg_match('/^[a-z]{2}(?:-[a-z0-9]+){1,4}$/D', $region)
            || empty($storage['access_key']) || empty($storage['secret_key'])) {
            throw new RuntimeException('素材原存储配置已变化，请重新上传');
        }
        // Read the owned key from the configured COS bucket, independently of its preview CDN.
        $client = new \Qcloud\Cos\Client(StorageOperationTimeout::cos(['operation_timeout' => $timeout]) + [
            'region' => $region,
            'scheme' => 'https',
            'credentials' => ['secretId' => $storage['access_key'], 'secretKey' => $storage['secret_key']],
        ]);
        $result = $client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Range' => 'bytes=0-' . $maxBytes,
            '@http' => [
                'allow_redirects' => false,
                'verify' => true,
                'on_headers' => static function ($response) use ($maxBytes): void {
                    $length = $response->getHeaderLine('Content-Length');
                    $range = $response->getHeaderLine('Content-Range');
                    if (($length !== '' && (!ctype_digit($length) || (int)$length > $maxBytes))
                        || ($range !== '' && (!preg_match('~^bytes 0-[0-9]+/([0-9]+)$~D', $range, $match)
                            || (int)$match[1] > $maxBytes))) {
                        throw new RuntimeException('素材大小超出读取限制');
                    }
                },
                'progress' => static function ($total, $downloaded) use ($maxBytes): void {
                    if ($total > $maxBytes || $downloaded > $maxBytes) throw new RuntimeException('素材大小超出读取限制');
                },
            ],
        ]);
        return (string)($result['Body'] ?? '');
    }
}
