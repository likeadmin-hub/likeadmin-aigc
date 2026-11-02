<?php
namespace app\common\service;

use think\facade\Db;
use app\common\service\ai\UserTaskTraceService;

/** Read-only library provenance. A project reuse is not another generation. */
final class WorkspaceShortDramaAssetService
{
    private const MEDIA_TASKS = ['subject_image','three_view','scene_image','shot_image','shot_video','bgm_audio','export_video'];
    private const DISPLAY_FIELDS = ['model','model_id','image_model_id','model_name','submitted_model_label','channel','ratio','aspect_ratio','resolution','quality','duration','quantity','generate_audio','background','generation_method','seed','output_format'];

    public static function appendRows(int $tenant, int $user, array $rows): array
    {
        if (!$rows || $tenant <= 0 || $user <= 0) return $rows;
        $tasks = array_values(array_filter(array_unique(array_column($rows, 'task_id'))));
        $consumptions = array_values(array_unique(array_filter(array_map(static fn($row)=>(int)($row['meta']['consumption_id'] ?? 0), $rows))));
        $generations = [];
        if ($tasks || $consumptions) {
            $generations = Db::name('aigc_short_drama_generation_task')->where(['tenant_id'=>$tenant,'user_id'=>$user])
                ->whereIn('task_type', self::MEDIA_TASKS)->where(function($q) use ($tasks,$consumptions) {
                    if ($tasks) $q->whereIn('task_id',$tasks);
                    if ($consumptions) $q->whereOrIn('consumption_id',$consumptions);
                })->select()->toArray();
        }
        $outputs = [];
        if ($generations) {
            // Include historical source records for provenance only; never add them to the visible list.
            $originals = Db::name('aigc_short_drama_asset')->where(['tenant_id'=>$tenant,'user_id'=>$user])
                ->whereIn('task_id',array_column($generations,'task_id'))->order('id')->select()->toArray();
            foreach ($originals as $original) $outputs[$original['task_id']][$original['uri']] ??= $original;
        }
        $generations = UserTaskTraceService::appendRows($generations);
        foreach ($rows as &$row) {
            unset($row['library_origin_ref'],$row['library_origin']);
            $meta = (array)($row['meta'] ?? []);
            $uri = (string)($row['uri'] ?? '');
            $generation = null; $original = null;
            foreach ($generations as $candidate) {
                $matches = (string)$candidate['task_id'] === (string)($row['task_id'] ?? '')
                    || ((int)($meta['consumption_id'] ?? 0) > 0 && (int)$candidate['consumption_id'] === (int)$meta['consumption_id']);
                $output = $outputs[$candidate['task_id']][$uri] ?? null;
                if ($matches && $output && $output['asset_type'] === $row['asset_type']) { $generation=$candidate; $original=$output; break; }
            }
            // Explicit absence prevents clients from mistaking a planning task for this image's task.
            $row['generation_snapshot'] = [];
            if ($generation) {
                $request = self::object($generation['request_json'] ?? '');
                $params = self::object($request['params'] ?? []);
                $image = self::object($request['image_params'] ?? []);
                $display = array_replace(self::display($meta),self::display(self::object($params['params'] ?? [])),self::display($params),self::display($image));
                $provider = trim((string)($generation['provider_task_id'] ?? ''));
                $result = self::object($generation['result_json'] ?? '');
                if ((int)($result['image_task_id'] ?? 0) > 0 && $provider === (string)$result['image_task_id']) $provider='';
                $row['generation_snapshot'] = $display + [
                    'task_id'=>(string)$generation['task_id'], 'provider_task_id'=>$provider,
                    'upstream_task_ids'=>(array)($generation['upstream_task_ids'] ?? []),
                    'provider_request_id'=>(string)($generation['provider_request_id'] ?? ''),
                    'prompt'=>self::text($params['prompt'] ?? $meta['visible_prompt'] ?? $meta['prompt'] ?? ''),
                    'create_time'=>(int)$original['create_time'],
                ];
                $row['library_origin_ref']='short_drama:result:'.$original['id'];
                $row['library_origin']='generated';
            } elseif (self::isUpload($row)) {
                $row['library_origin']='upload';
            }
        }
        unset($row);
        return $rows;
    }

    public static function isUpload(array $row): bool
    {
        $meta = (array)($row['meta'] ?? []);
        if (in_array($meta['media_source'] ?? '', ['upload','uploaded','local_upload'],true)) return true;
        if (in_array($meta['source'] ?? '', ['pc_subject_create_manual','canvas_project_upload','subject_library'],true)) return true;
        // Historical canvas upload registrations have no generation/run identity.
        return ($meta['source'] ?? '') === 'short_drama_canvas' && empty($meta['canvas_run_id']) && empty($row['task_id']);
    }

    private static function object($value): array
    {
        if (is_array($value)) return $value;
        $decoded = is_string($value) ? json_decode($value,true) : null;
        return is_array($decoded) ? $decoded : [];
    }
    private static function text($value): string { return is_scalar($value) ? trim((string)$value) : ''; }
    private static function display(array $value): array
    {
        return array_filter(array_intersect_key($value,array_flip(self::DISPLAY_FIELDS)),static fn($v)=>is_scalar($v) && $v !== '');
    }
}
