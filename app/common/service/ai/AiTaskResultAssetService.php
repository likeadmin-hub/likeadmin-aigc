<?php

namespace app\common\service\ai;

use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiTaskResultAsset;
use app\common\service\app\aigc_image\AigcImageAssetService;
use app\common\service\app\aigc_music\AigcMusicAssetService;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_video\AigcVideoAssetService;
use Exception;

class AiTaskResultAssetService
{
    public static function recordConsumptionAssets(int $consumptionId, bool $forceTransfer = false): array
    {
        $consumption = AiConsumptionLog::findOrEmpty($consumptionId);
        if ($consumption->isEmpty()) {
            return [];
        }
        $summary = self::arrayValue($consumption['response_summary'] ?? []);
        $assets = [];
        foreach ([['images', 'image', 'image_uri'], ['videos', 'video', 'video_uri'], ['items', 'audio', 'audio_uri']] as [$key, $type, $uriKey]) {
            foreach ((array)($summary[$key] ?? []) as $item) {
                $item = is_array($item) ? $item : [];
                // Native tools also persist these two legacy names. Project
                // their recorded facts without overriding canonical values or
                // substituting current model defaults for historical results.
                if (!array_key_exists('mime_type',$item) && is_string($item['mime']??null)) $item['mime_type']=$item['mime'];
                if (!array_key_exists('file_size',$item) && is_numeric($item['size']??null) && is_finite((float)$item['size']) && (float)$item['size']>=0) $item['file_size']=(int)$item['size'];
                $uri = trim((string)($item[$uriKey] ?? $item['url'] ?? $item['audio_url'] ?? ''));
                if ($uri === '') {
                    continue;
                }
                $asset = AiTaskResultAsset::where(['consumption_id'=>$consumptionId,'asset_type'=>$type])
                    ->where(function($q)use($uri){$q->where('external_url',$uri)->whereOr('local_uri',$uri);})->findOrEmpty();
                if ($asset->isEmpty()) {
                    $asset = AiTaskResultAsset::create([
                        'app_task_id' => (int)$consumption['app_task_id'],
                        'consumption_id' => $consumptionId,
                        'tenant_id' => (int)$consumption['tenant_id'],
                        'user_id' => (int)$consumption['user_id'],
                        'asset_type' => $type,
                        'external_url' => $uri,
                        'external_expire_time' => 0,
                        'local_uri' => self::isExternal($uri) ? '' : $uri,
                        'storage_scope' => (string)($item['storage_scope'] ?? ''),
                        'storage_engine' => (string)($item['storage_engine'] ?? ''),
                        'storage_domain' => (string)($item['storage_domain'] ?? ''),
                        'storage_meta' => self::isExternal($uri) ? [] : array_intersect_key($item,array_flip([
                            'uri','stored','width','height','duration','mime_type','file_size',
                            'storage_scope','storage_engine','storage_domain',
                        ])),
                        'transfer_status' => self::isExternal($uri) ? 'external' : 'stored',
                        'transfer_attempts' => 0,
                        'last_error' => '',
                        'create_time' => time(),
                        'update_time' => time(),
                    ]);
                }
                $assets[] = $asset->toArray();
                if ($forceTransfer || AiTaskResultStorageService::transferEnabled((int)$consumption['tenant_id'])) {
                    AiTaskJobService::enqueueTransfer((int)$asset['id'], $forceTransfer);
                }
            }
        }
        // A worker can resume after the result URLs have already been turned
        // into durable rows. Keep that recovery path independent of a later
        // provider-summary refresh.
        if ($assets === []) {
            $assets = AiTaskResultAsset::where('consumption_id', $consumptionId)->select()->toArray();
        }
        if ($assets !== [] && ($forceTransfer || AiTaskResultStorageService::transferEnabled((int)$consumption['tenant_id']))) {
            foreach ($assets as $asset) {
                if ((string)($asset['transfer_status'] ?? '') !== 'stored') {
                    AiTaskJobService::enqueueTransfer((int)$asset['id'], $forceTransfer);
                }
            }
        }
        self::hydrateStoredSummary($consumptionId,$assets);
        return $assets;
    }

    /** Enrich legacy managed video metadata without uploading or registering the file again. */
    public static function measureStoredVideo(int $assetId, int $consumptionId, int $tenantId, int $userId): void
    {
        $owner=['id'=>$assetId,'consumption_id'=>$consumptionId,'tenant_id'=>$tenantId,'user_id'=>$userId,'asset_type'=>'video','transfer_status'=>'stored'];
        $asset=AiTaskResultAsset::where($owner)->findOrEmpty();
        if($asset->isEmpty() || trim((string)$asset['local_uri'])==='') throw new Exception('视频结果不存在或尚未完成转存');
        $meta=self::arrayValue($asset['storage_meta']);
        if(is_numeric($meta['duration']??null) && is_finite((float)$meta['duration']) && (float)$meta['duration']>0)return;
        $uri=(string)$asset['local_uri'];
        $engine=(string)$asset['storage_engine'];
        $public=realpath(root_path().'public');
        $path=$engine==='local'?realpath(root_path().'public/'.ltrim($uri,'/')):false;
        if($path!==false && $public!==false && str_starts_with($path,$public.DIRECTORY_SEPARATOR))
            $facts=\app\common\service\power\MarketGenerationMedia::localFacts($path);
        else {
            $url=\app\common\service\FileService::getFileUrlByStorage($uri,(string)$asset['storage_scope'],$engine,(string)$asset['storage_domain']);
            $facts=\app\common\service\power\MarketGenerationMedia::videoFacts($url);
        }
        \think\facade\Db::transaction(function()use($owner,$uri,$facts,$consumptionId){
            $current=AiTaskResultAsset::where($owner)->lock(true)->findOrEmpty();
            if($current->isEmpty() || (string)$current['local_uri']!==$uri)throw new Exception('视频结果归属或文件已变化');
            $meta=self::arrayValue($current['storage_meta']);
            $current->save(['storage_meta'=>array_replace($meta,array_intersect_key($facts,array_flip(['duration','width','height']))),'update_time'=>time()]);
            self::hydrateStoredSummary($consumptionId,[$current->toArray()]);
        });
    }

    public static function transfer(int $assetId): void
    {
        $asset = AiTaskResultAsset::findOrEmpty($assetId);
        if($asset->isEmpty())return;
        if((string)$asset['transfer_status']==='stored'){self::hydrateStoredSummary((int)$asset['consumption_id'],[$asset->toArray()]);if((int)$asset['consumption_id']>0)AiTaskJobService::enqueueProcessResult((int)$asset['consumption_id']);return;}
        $url = trim((string)$asset['external_url']);
        if (!self::isExternal($url)) {
            $asset->save(['local_uri' => $url, 'transfer_status' => 'stored', 'update_time' => time()]);
            return;
        }
        $tenantId = (int)$asset['tenant_id'];
        $userId = (int)$asset['user_id'];
        $storageConfig = self::shortDramaStorageConfig($asset, $tenantId) ?? \app\common\service\storage\StorageConfigService::getEffectiveConfig($tenantId);
        try {
            $stored = AiTaskManagedFileService::recover($asset, $storageConfig) ?? match ((string)$asset['asset_type']) {
                'image' => AigcImageAssetService::persistGeneratedImage($url, $tenantId, $userId, true,0,$storageConfig,$assetId),
                'video' => AigcVideoAssetService::persistGeneratedVideo($url, $tenantId, $userId, $storageConfig, $assetId),
                'audio' => AigcMusicAssetService::persistGeneratedAudio($url, $tenantId, $userId, $storageConfig, $assetId),
                default => throw new Exception('不支持的结果资源类型'),
            };
            if(empty($stored['stored'])||trim((string)($stored['uri']??''))==='')throw new Exception('结果资源尚未完成有效转存');
            $stored = json_decode(json_encode($stored, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            [$asset, $completed] = \think\facade\Db::transaction(function () use ($assetId, $tenantId, $userId, $stored) {
                $current = AiTaskResultAsset::lock(true)->findOrEmpty($assetId);
                if ($current->isEmpty() || (int)$current['tenant_id'] !== $tenantId || (int)$current['user_id'] !== $userId) throw new Exception('转存资产归属已变化');
                if ((string)$current['transfer_status'] === 'stored') {
                    if ((array)$current['storage_meta'] !== $stored || (string)$current['local_uri'] !== (string)$stored['uri']) throw new Exception('已完成转存回执已变化');
                    return [$current, false];
                }
                $current->save([
                'local_uri' => (string)($stored['uri'] ?? ''),
                'storage_scope' => (string)($stored['storage_scope'] ?? ''),
                'storage_engine' => (string)($stored['storage_engine'] ?? ''),
                'storage_domain' => (string)($stored['storage_domain'] ?? ''),
                'storage_meta' => $stored,
                'transfer_status' => 'stored',
                'transfer_attempts' => (int)$current['transfer_attempts'] + 1,
                'last_error' => '',
                'update_time' => time(),
                ]);
                return [$current, true];
            });
            self::hydrateStoredSummary((int)$asset['consumption_id'],[$asset->toArray()]);
            if((int)$asset['consumption_id']>0){
                if ($completed) AiTaskJobService::requeueProcessResult((int)$asset['consumption_id']);
                else AiTaskJobService::enqueueProcessResult((int)$asset['consumption_id']);
            }
        } catch (\Throwable $e) {
            // A later projection/queue error must not reopen a completed upload.
            AiTaskResultAsset::where('id', $assetId)->where('transfer_status', '<>', 'stored')->update([
                'transfer_status' => 'retrying',
                'transfer_attempts' => \think\facade\Db::raw('transfer_attempts + 1'),
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'update_time' => time(),
            ]);
            throw $e;
        }
    }

    /** Project only owned managed outputs; retain original URLs on the durable asset rows. */
    private static function hydrateStoredSummary(int $consumptionId,array $assets): void
    {
        if(!$assets)return;
        \think\facade\Db::transaction(function()use($consumptionId,$assets){
            $c=AiConsumptionLog::lock(true)->findOrEmpty($consumptionId);if($c->isEmpty())return;
            $summary=self::arrayValue($c['response_summary']);$before=$summary;
            foreach($assets as$asset){
                if((int)$asset['consumption_id']!==$consumptionId||(int)$asset['tenant_id']!==(int)$c['tenant_id']||(int)$asset['user_id']!==(int)$c['user_id']||$asset['transfer_status']!=='stored'||trim((string)$asset['local_uri'])===''||empty($asset['storage_scope'])||empty($asset['storage_engine']))continue;
                [$key,$uriKey]=match($asset['asset_type']){'image'=>['images','image_uri'],'video'=>['videos','video_uri'],'audio'=>['items','audio_uri'],default=>['','']};
                if($key===''||!is_array($summary[$key]??null))continue;
                $meta=self::arrayValue($asset['storage_meta']??[]);
                foreach($summary[$key]as&$row){
                    if(!is_array($row))continue;$uri=(string)($row[$uriKey]??$row['url']??$row['audio_url']??'');
                    if(!in_array($uri,[$asset['external_url'],$asset['local_uri']],true))continue;
                    $row[$uriKey]=$asset['local_uri'];foreach(['storage_scope','storage_engine','storage_domain']as$field)$row[$field]=(string)$asset[$field];
                    // Zero dimensions mean "not measured" for video storage.
                    // Preserve existing result facts rather than replacing them with unknowns.
                    foreach(['width','height','file_size']as$field)if(is_numeric($meta[$field]??null)&&(int)$meta[$field]>0)$row[$field]=(int)$meta[$field];
                    if(is_numeric($meta['duration']??null)&&is_finite((float)$meta['duration'])&&(float)$meta['duration']>0)$row['duration']=(float)$meta['duration'];
                    if(is_string($meta['mime_type']??null)&&$meta['mime_type']!=='')$row['mime_type']=$meta['mime_type'];
                }unset($row);
            }
            if($summary!==$before)$c->save(['response_summary'=>$summary,'update_time'=>time()]);
        });
    }

    private static function isExternal(string $uri): bool
    {
        return str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://') || str_starts_with($uri, 'data:');
    }

    private static function shortDramaStorageConfig(AiTaskResultAsset $asset, int $tenantId): ?array
    {
        if (!AiTaskBusinessResultService::requiresForcedTransfer((int)$asset['consumption_id'])) {
            return null;
        }
        return AigcShortDramaService::resultTransferStorageConfig($tenantId);
    }

    private static function arrayValue(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
