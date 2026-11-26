<?php
declare(strict_types=1);

namespace app\common\service\app\aigc_product_video;

use app\common\model\app\AppCase;
use app\common\service\ai\StudioMediaService as Media;
use app\common\service\app\AppCaseService;
use app\common\service\app\StudioBuiltinCatalog;
use app\common\service\app\StudioBuiltinTemplates as Builtins;
use app\common\service\app\StudioTemplateModel;
use app\common\service\SubmitLockService;
use InvalidArgumentException;
use think\facade\Db;

final class VideoReplicationTemplates
{
    public const APP = ProductVideoPolicy::REPLICATION;

    public static function snapshot(array $input): array
    {
        $fields = ['reference_video','clip','images','prompt','video_selection','market_region','language'];
        $body = ProductVideoPolicy::body(array_intersect_key($input, array_flip($fields)), self::APP);
        $body = array_intersect_key($body, array_flip($fields));
        $body['video_selection'] = array_intersect_key($body['video_selection'], array_flip(['model_id','duration','ratio','quality']));
        if (!$body['reference_video'] || !$body['images'] || $body['prompt'] === '') {
            throw new InvalidArgumentException('请添加参考视频、商品或场景图并填写分镜脚本');
        }
        VideoReplicationWorkflow::checkSegment($body);
        $fields = array_flip(['uri','name','storage_scope','storage_engine','storage_domain','file_id','tenant_id','user_id','reference_slot_id','mention_name','duration','format','file_size','width','height','mime_type']);
        $body['reference_video'] = array_intersect_key($body['reference_video'], $fields);
        $body['images'] = array_map(static fn($asset) => array_intersect_key($asset, $fields), $body['images']);
        return $body;
    }

    public static function portableBody(array $input): array
    {
        $body = self::snapshot($input);
        $body['images'] = array_map(static fn($a) => StudioBuiltinCatalog::publicAsset($a + ['type'=>'image']), $body['images']);
        $video = $body['reference_video'];
        $body['reference_video'] = StudioBuiltinCatalog::publicAsset($video + ['type'=>'video']);
        $body['reference_video']['format'] = str_contains(','.$video['format'].',', ',mp4,') ? 'mp4' : 'mov';
        $body['video_selection']['model_id'] = '';
        return $body;
    }

    public static function validateModel(array $model, array $body): void
    {
        if (!empty($model['replication_reference_modes'])) VideoReplicationWorkflow::renderSpec($model, $body);
        else ProductVideoCatalog::spec($model, $body + ['mode'=>'normal','generation_assets'=>[]]);
    }

    private static function query(int $scope, bool $public)
    {
        Builtins::sync(self::APP);
        $query = AppCase::where(['app_code'=>self::APP, 'delete_time'=>0]);
        if ($public || $scope > 0) $query->whereIn('tenant_id', [0, $scope]);
        if ($public) $query->where('status', 1);
        return $query;
    }

    private static function row(int $scope, int $id, bool $public = false): array
    {
        $row = self::query($scope, $public)->where('id', $id)->find();
        if (!$row) throw new InvalidArgumentException('模板不存在或无权访问');
        return $row->toArray();
    }

    public static function lists(int $scope, array $params, bool $public = false): array
    {
        if ($public) ProductVideoService::available($scope);
        $query = self::query($scope, $public);
        if (!$public && isset($params['status']) && $params['status'] !== '') $query->where('status', (int)$params['status']);
        if (($params['keyword'] ?? '') !== '') $query->whereLike('title', '%'.addcslashes(ProductVideoPolicy::text($params['keyword'], 100), '\\%_').'%');
        $count = (clone $query)->count();
        $rows = $query->order(['sort'=>'desc','id'=>'desc'])->page(max(1,(int)($params['page_no'] ?? 1)), max(1,min(30,(int)($params['page_size'] ?? 20))))->select()->toArray();
        return ['lists'=>array_map(fn($row) => self::present($row, $public, $scope), $rows), 'count'=>$count];
    }

    public static function detail(int $scope, int $id, bool $public = false): array
    {
        if ($public) ProductVideoService::available($scope);
        return self::present(self::row($scope, $id, $public), $public, $scope);
    }

    private static function present(array $row, bool $public, int $scope): array
    {
        $owner = (int)$row['tenant_id'];
        $body = ProductVideoService::publicBody(Builtins::stored(self::APP, $row), $owner);
        $preview = ProductVideoService::publicBody(Builtins::media(self::APP, $row), $owner);
        $dto = ['id'=>(int)$row['id'], 'title'=>$row['title'], 'configuration'=>$body, 'prompt'=>$body['prompt'],
            'cover_url'=>$body['images'][0]['url'] ?? '', 'media_type'=>'video', 'media_url'=>$preview['url'] ?? '', 'ratio'=>$body['video_selection']['ratio'] ?? ''];
        if ($public) return $dto;
        $builtin = Builtins::info(self::APP, (int)$row['id']);
        return $dto + ['tenant_id'=>$owner, 'source_job_id'=>(int)$row['source_task_id'], 'status'=>(int)$row['status'], 'sort'=>(int)$row['sort'],
            'readonly'=>$scope > 0 && $scope !== $owner, 'is_builtin'=>(bool)$builtin, 'builtin_version'=>(int)($builtin['version'] ?? 0),
            'published_builtin_version'=>(int)Builtins::table()->where(['app_code'=>self::APP,'source_case_id'=>$row['id']])->value('version')];
    }

    public static function galleryRow(array $row): array
    {
        $view = self::present($row, true, 0);
        return array_replace($row, ['cover_url'=>$view['cover_url'], 'media_url'=>$view['media_url'], 'reference_image_urls'=>array_column($view['configuration']['images'],'url')]);
    }

    public static function options(int $scope, array $params): array
    {
        if (!empty($params['job_id'])) $scope = (int)ProductVideoJobs::owned($scope, 0, (int)$params['job_id'], true)['tenant_id'];
        elseif (!empty($params['id'])) $scope = (int)self::row($scope,(int)$params['id'])['tenant_id'];
        return ProductVideoService::models($scope, 'video', (string)($params['model_id'] ?? ''));
    }

    private static function selectAsset(int $owner, array $input, array $existing, int $type): array
    {
        foreach ($existing as $asset) {
            if (!empty($asset['uri']) && $asset['uri'] === ($input['uri'] ?? '') && empty($input['file_id'])) return Media::verify($owner, $asset, $type);
        }
        $asset = Media::adminAsset($owner, $input, $type);
        if ($type === 20) {
            $facts = Media::facts($owner, $asset, $type);
            $asset += $facts;
            $asset['format'] = match ($facts['mime_type']) {'video/mp4'=>'mp4','video/quicktime'=>'mov',default=>''};
        }
        return $asset;
    }

    private static function persist(int $owner, ?array $old, array $source, array $preview, int $job, array $params): array
    {
        $body = $source;
        if (isset($params['configuration'])) {
            $patch = $params['configuration'];
            $body = array_replace($source, array_intersect_key($patch, array_flip(['clip','prompt','market_region','language','video_selection'])));
            $body['reference_video'] = self::selectAsset($owner, (array)($patch['reference_video'] ?? []), [$source['reference_video'] ?? []], 20);
            $body['images'] = [];
            foreach ((array)($patch['images'] ?? []) as $image) $body['images'][] = self::selectAsset($owner, $image, $source['images'] ?? [], 10);
        }
        if (!empty($params['preview']['file_id'])) $preview = Media::adminAsset($owner, $params['preview'], 20);
        $preview = Media::verify($owner, $preview, 20);
        $preview = array_intersect_key($preview, array_flip(['uri','name','storage_scope','storage_engine','storage_domain','file_id','user_id','tenant_id']));
        $body = self::snapshot($body);
        self::validateModel(ProductVideoCatalog::model($owner, $body), $body);
        $title = ProductVideoPolicy::text($params['title'] ?? $old['title'] ?? '视频复刻灵感', 100);
        if ($title === '') throw new InvalidArgumentException('请填写模板名称');
        $row = AppCaseService::save($owner, self::APP, [
            'id'=>$old['id'] ?? 0, 'title'=>$title, 'prompt'=>$body['prompt'], 'media_type'=>'video',
            'cover_uri'=>$body['images'][0]['uri'], 'media_uri'=>$preview['uri'], 'reference_images'=>array_column($body['images'],'uri'),
            'config_json'=>['generation_request'=>['advanced_params'=>['video_replication'=>$body, 'video_replication_media'=>$preview]]],
            'source_task_id'=>$job,'source_result_id'=>0,'status'=>(int)($params['status'] ?? $old['status'] ?? 1) === 1 ? 1 : 0,
            'sort'=>max(0,min(9999,(int)($params['sort'] ?? $old['sort'] ?? 0)))
        ]);
        return self::detail($owner, (int)$row['id']);
    }

    public static function save(int $scope, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        if ($id && Builtins::info(self::APP, $id)) return Builtins::change(self::APP, $scope, $id, $params, 'save');
        $old = $id ? self::row($scope, $id) : null;
        $owner = (int)($old['tenant_id'] ?? $scope);
        if ($scope > 0 && $scope !== $owner) throw new InvalidArgumentException('平台共享模板仅供查看和使用');
        return self::persist($owner, $old, $old ? Builtins::stored(self::APP,$old) : [], $old ? Builtins::media(self::APP,$old) : [], (int)($old['source_task_id'] ?? 0), $params);
    }

    public static function fromJob(int $scope, int $id, array $params): array
    {
        $job = ProductVideoJobs::owned($scope, 0, $id, true);
        if ($job['action'] !== 'video' || $job['status'] !== 'success') throw new InvalidArgumentException('仅已完成的复刻视频可以设为模板');
        $owner = (int)$job['tenant_id'];
        $body = ProductVideoPolicy::json($job['request_json'])['snapshot'];
        Media::verify($owner, (array)$body['reference_video'], 20);
        foreach ($body['images'] as $image) Media::verify($owner, $image, 10);
        $result = ProductVideoPolicy::json($job['result_json'])['videos'][0] ?? [];
        $where = array_intersect_key($result, array_flip(['storage_scope','storage_engine','storage_domain']));
        $where += ['tenant_id'=>$owner,'source_id'=>(int)$job['user_id'],'source'=>1,'type'=>20,'uri'=>$result['video_uri'] ?? $result['uri'] ?? ''];
        if (empty($where['uri']) || empty($where['storage_engine'])) throw new InvalidArgumentException('作品尚未保存到系统存储');
        $file = Db::name('tenant_file')->where($where)->whereRaw('(delete_time IS NULL OR delete_time=0)')->find();
        if (!$file) throw new InvalidArgumentException('作品素材已不可用');
        $preview = $file + ['file_id'=>(int)$file['id'],'user_id'=>(int)$job['user_id']];
        $lock = SubmitLockService::acquire('replication_case_'.$id, $owner);
        try {
            $old = AppCase::where(['app_code'=>self::APP,'tenant_id'=>$owner,'source_task_id'=>$id,'delete_time'=>0])->find();
            return self::persist($owner, $old ? $old->toArray() : null, $body, $preview, $id, $params);
        } finally { SubmitLockService::release($lock); }
    }

    public static function remix(int $tenant, int $user, int $id): array
    {
        ProductVideoService::available($tenant,$user);
        return Db::transaction(static function () use ($tenant,$user,$id) {
            $row = self::row($tenant,$id,true);
            $entry = Builtins::definition(self::APP,$id);
            $default = (string)ProductVideoService::settings($tenant)['default_video_model_id'];
            [$body,$notice] = $entry
                ? Builtins::videoDraft(self::APP, $tenant, $entry, $default)
                : StudioTemplateModel::video($tenant, Builtins::stored(self::APP, $row), $default, ProductVideoCatalog::class, [self::class,'validateModel']);
            $owner = (int)$row['tenant_id'];
            $body['reference_video'] = Media::grant($tenant,$user,$owner,$body['reference_video'],20);
            foreach ($body['images'] as &$image) $image = Media::grant($tenant,$user,$owner,$image,10);
            unset($image);
            $body['prompt_review_images'] = array_column($body['images'], 'uri');
            return ProductVideoService::save($tenant,$user,['title'=>mb_substr($row['title'],0,90).' · 同款','body'=>$body]) + ['template_notice'=>$notice];
        });
    }

    public static function change(int $scope, array $params, string $action): void
    {
        $id = (int)($params['id'] ?? 0);
        $row = self::row($scope,$id);
        if (Builtins::info(self::APP,$id)) { Builtins::change(self::APP,$scope,$id,$params,$action); return; }
        $owner = (int)$row['tenant_id'];
        if ($scope > 0 && $scope !== $owner) throw new InvalidArgumentException('平台共享模板仅供查看和使用');
        if ($action === 'status') AppCaseService::setStatus($owner,self::APP,$id,(int)($params['status'] ?? 0) === 1 ? 1 : 0);
        else AppCaseService::delete($owner,self::APP,$id);
    }
}
