<?php
namespace app\common\service\app\aigc_depth_replication;

use app\common\service\ai\StudioMediaInputService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\FileService;
use InvalidArgumentException;
use think\facade\Db;

final class DepthJobs
{
    public const APP = 'aigc_depth_replication';
    public const TABLE = self::APP.'_job';

    public static function config(int $tenant): array
    {
        $limit = Db::name(self::APP.'_config')->whereIn('tenant_id',[0,$tenant])->order('tenant_id','desc')->value('max_seconds');
        return ['max_seconds'=>(int)($limit ?: 15),'controls'=>Lighting::CONTROLS];
    }

    public static function setup(int $tenant, array $params): array
    {
        $max = filter_var($params['max_seconds'] ?? null,FILTER_VALIDATE_INT);
        if (!$max || $max < 1 || $max > 15) throw new InvalidArgumentException('视频时长须为 1～15 秒');
        return Db::transaction(function () use ($tenant,$max,$params) {
            Db::name(self::APP.'_config')->insert(['tenant_id'=>$tenant,'max_seconds'=>$max], true);
            AppDisplayConfigService::saveFromConfigPayload($tenant,self::APP,$params);
            return self::config($tenant);
        });
    }

    public static function owned(int $tenant, int $user, int $id, bool $includeDeleted = false): array
    {
        $row = Db::name(self::TABLE)->where(['id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user])->find();
        if (!$row || !$tenant || !$user || (!$includeDeleted && $row['delete_time'])) {
            throw new InvalidArgumentException('创作记录不存在');
        }
        return $row;
    }

    public static function delete(int $tenant, int $user, int $id): array
    {
        $row = self::owned($tenant, $user, $id, true);
        if (!$row['delete_time']) {
            if (!in_array($row['status'], ['success', 'failed'], true)) {
                throw new InvalidArgumentException('请等待任务结束后再删除记录');
            }
            Db::name(self::TABLE)->where(['id'=>$id, 'tenant_id'=>$tenant, 'user_id'=>$user, 'delete_time'=>0])
                ->update(['delete_time'=>time()]);
        }
        return ['id'=>$id];
    }

    /** A visible export retains its source analysis even after the analysis card is removed. */
    public static function analysisFor(array $row): array
    {
        if ($row['kind'] === 'analyze') return $row;
        return self::owned((int)$row['tenant_id'], (int)$row['user_id'], (int)$row['analysis_id'], true);
    }

    public static function folder(int $id): string
    {
        return root_path().'runtime/depth_replication/'.$id;
    }

    public static function index(array $row): array
    {
        if ($row['kind'] !== 'analyze' || $row['status'] !== 'success') throw new InvalidArgumentException('深度分析尚未完成');
        $path = self::folder((int)$row['id']).'/cache/index.json';
        if (!is_file($path)) throw new InvalidArgumentException('深度缓存已过期，请重新分析原片');
        return json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    }

    public static function frames(array $row, int $start): array
    {
        $row = self::analysisFor($row);
        $meta = self::index($row);
        $count = count($meta['pts']);
        if ($start < 0 || $start >= $count) throw new InvalidArgumentException('预览时间超出视频范围');
        $frames = [];
        for ($i=$start; $i<min($count,$start+6); ++$i) {
            $prefix = self::folder((int)$row['id']).'/cache/'.$i;
            if (!is_file($prefix.'.png') || !is_file($prefix.'.jpg')) throw new InvalidArgumentException('预览缓存缺失，请重新分析');
            $frames[] = ['basis'=>base64_encode(file_get_contents($prefix.'.png')),'original'=>base64_encode(file_get_contents($prefix.'.jpg'))];
        }
        return ['frames'=>$frames];
    }

    public static function settings(array $data, array $index): array
    {
        $global = Lighting::values((array)($data['global'] ?? []));
        $shots = [];
        foreach ((array)($data['shots'] ?? []) as $start=>$overrides) {
            if (!ctype_digit((string)$start) || !in_array((int)$start,$index['shots'],true) || !is_array($overrides)) {
                throw new InvalidArgumentException('镜头设置无效');
            }
            $shots[$start] = array_intersect_key(Lighting::values($overrides),$overrides);
        }
        return ['global'=>$global,'shots'=>$shots];
    }

    public static function create(int $tenant, int $user, array $params): array
    {
        $key = (string)($params['request_id'] ?? '');
        if (!$tenant || !$user || !preg_match('/^[\w-]{8,80}$/D',$key)) throw new InvalidArgumentException('提交标识无效');
        $kind = $params['kind'] ?? '';
        $input = [$kind, $params['uri']??null, $params['analysis_id']??null, $params['settings']??null];
        if (isset($params['source_job_id'])) $input[] = (int)$params['source_job_id'];
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $identity = ['tenant_id'=>$tenant,'user_id'=>$user,'request_id'=>$key];
        $existing = static function () use ($identity,$hash) {
            $row = Db::name(self::TABLE)->where($identity)->find();
            if ($row && $row['delete_time']) throw new InvalidArgumentException('创作记录已删除');
            if ($row && !hash_equals($row['input_hash'],$hash)) throw new InvalidArgumentException('此提交标识已用于另一项创作');
            return $row;
        };
        if ($prior = $existing()) return self::dto($prior);
        $settings = []; $analysis = 0;
        if ($kind === 'analyze') {
            try {
                $source = StudioMediaInputService::validateVideo($tenant,$user,(string)($params['uri']??''),self::config($tenant)['max_seconds']);
            } catch (\RuntimeException $e) { throw new InvalidArgumentException('请重新选择可读取的本人视频素材',0,$e); }
            if ($source['file_size'] > 100*1048576) throw new InvalidArgumentException('视频不能超过 100 MB');
        } elseif ($kind === 'render') {
            $sourceJob = self::owned($tenant, $user, (int)($params['source_job_id'] ?? $params['analysis_id'] ?? 0));
            $row = self::analysisFor($sourceJob);
            $analysis = (int)$row['id'];
            $settings = self::settings((array)($params['settings']??[]),self::index($row));
            $source = json_decode($row['source_json'],true);
        } else throw new InvalidArgumentException('创作类型无效');
        $time = time();
        $row = $identity + ['kind'=>$kind,'analysis_id'=>$analysis,'input_hash'=>$hash,'status'=>'queued','result_json'=>'{}',
            'source_json'=>json_encode($source,JSON_THROW_ON_ERROR),'settings_json'=>json_encode($settings,JSON_THROW_ON_ERROR),'create_time'=>$time,'update_time'=>$time];
        try { $id = Db::name(self::TABLE)->insertGetId($row); }
        catch (\Throwable $e) { if ($prior = $existing()) return self::dto($prior); throw $e; }
        return self::dto(['id'=>$id]+$row);
    }

    public static function dto(array $row, bool $withIndex=false, bool $forEdit=false): array
    {
        $source = json_decode($row['source_json'],true);
        $result = json_decode($row['result_json'],true) ?: [];
        $url = static fn($asset) => FileService::getFileUrlByStorage($asset['uri'],$asset['storage_scope'],$asset['storage_engine'],$asset['storage_domain'],(int)$row['tenant_id']);
        $dto = ['id'=>(int)$row['id'],'analysis_id'=>(int)$row['analysis_id'],'kind'=>$row['kind'],'status'=>$row['status'],
            'name'=>$source['name'],'source'=>['uri'=>$source['uri'],'url'=>$url($source),'name'=>$source['name']],
            'settings'=>json_decode($row['settings_json'],true),'create_time'=>(int)$row['create_time'],'error'=>$row['error']??'',
            'seconds'=>$result['analyze_seconds']??$result['render_seconds']??null,'url'=>isset($result['asset'])?$url($result['asset']):''];
        if ($withIndex && $row['status']==='success' && ($row['kind']==='analyze' || $forEdit)) {
            $analysis = self::analysisFor($row);
            if (is_file(self::folder((int)$analysis['id']).'/cache/index.json')) $dto['index'] = self::index($analysis);
        }
        return $dto;
    }

    public static function lists(int $tenant, ?int $user, int $before, bool $platform=false): array
    {
        $q = Db::name(self::TABLE)->where('delete_time',0);
        if (!$platform || $tenant>0) $q->where('tenant_id',$tenant);
        if ($user !== null) $q->where('user_id',$user);
        if ($before>0) $q->where('id','<',$before);
        $rows = $q->order('id','desc')->limit(21)->select()->toArray();
        return ['lists'=>array_map([self::class,'dto'],array_slice($rows,0,20)), 'more'=>count($rows)>20];
    }
}
