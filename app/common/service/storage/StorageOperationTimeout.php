<?php
declare(strict_types=1);
namespace app\common\service\storage;

/** Optional per-call seconds. Ordinary uploads retain their existing SDK defaults. */
final class StorageOperationTimeout
{
    public static function seconds(array $config): ?int
    {return array_key_exists('operation_timeout',$config)?max(1,min(60,(int)$config['operation_timeout'])):null;}
    public static function oss(object $client,array $config): void
    {
        $seconds=self::seconds($config);if($seconds===null)return;
        $client->setTimeout($seconds);$client->setConnectTimeout(min(3,$seconds));$client->setMaxTries(0);
    }
    public static function cos(array $config): array
    {
        $seconds=self::seconds($config);return $seconds===null?[]:['timeout'=>$seconds,'connect_timeout'=>min(3,$seconds),'retry'=>0];
    }
    public static function qiniu(array $config,bool $oneRequest=false): ?\Qiniu\Http\RequestOptions
    {
        $seconds=self::seconds($config);if($seconds===null)return null;
        // Region discovery and multipart upload share the operation budget.
        $perRequest=$oneRequest?$seconds:max(1,(int)floor($seconds/2));
        return new \Qiniu\Http\RequestOptions(min(3,$perRequest),null,$perRequest);
    }
}
