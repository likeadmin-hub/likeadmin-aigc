<?php
namespace app\common\service;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\CanvasAssetService;
use think\facade\Db;

/** Read stored canvas outputs without polling providers or changing the user's graph. */
final class WorkspaceCanvasAssetService
{
    public static function lists(int $tenant, int $user, int $page, string $kind = 'task'): array
    {
        if ($tenant <= 0 || $user <= 0 || !in_array($kind, ['task', 'result'], true)) throw new \InvalidArgumentException('资产来源无效');
        $query = Db::name($kind === 'task' ? 'aigc_canvas_run' : 'aigc_canvas_asset')
            ->where(['tenant_id'=>$tenant, 'user_id'=>$user, 'delete_time'=>0]);
        if ($kind === 'task') $query->whereIn('run_type', ['image', 'video'])->where('status', 'success');
        else $query->where('status', '<>', 'deleted');
        $page = max(1, $page);
        $count = (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, 100)->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            if ($kind === 'task') array_push($items, ...self::outputs($row));
            else {
                $raw = CanvasAssetService::formatAsset($row);
                $raw['meta'] = array_intersect_key($raw['meta'], array_flip(['title','message','visible_prompt','model','ratio','source','source_app_code','media_source','node_type','source_subject_id']));
                $items[] = ['source'=>'canvas', 'ref'=>'canvas:result:'.$row['id'], 'raw'=>$raw];
            }
        }
        // Pagination counts source records; one run may have multiple output assets.
        return ['lists'=>$items, 'has_more'=>$page * 100 < $count, 'page_size'=>100];
    }

    public static function owned(int $tenant, int $user, string $ref): ?array
    {
        $parts = explode(':', $ref);
        $row = Db::name('aigc_canvas_run')->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>(int)($parts[2] ?? 0),'delete_time'=>0,'status'=>'success'])->find();
        if (!$row || !in_array($row['run_type'], ['image','video'], true)) return null;
        foreach (self::outputs($row) as $item) if ($item['ref'] === $ref) return $item;
        return null;
    }

    private static function outputs(array $row): array
    {
        $payload = json_decode($row['result_json'] ?? '{}', true) ?: [];
        $params = json_decode($row['params_json'] ?? '{}', true) ?: [];
        $result = [];
        foreach (AigcCanvasService::runResultItems($row['run_type'], array_intersect_key($payload, array_flip(['results','images','videos','image_url','image_uri','video_url','video_uri','url','file_url']))) as $index => $output) {
            $result[] = ['source'=>'canvas', 'ref'=>'canvas:task:'.$row['id'].':'.($index + 1), 'raw'=>[
                'id'=>(int)$row['id'], 'task_id'=>(int)$row['id'], 'project_id'=>(int)$row['project_id'],
                'asset_type'=>'canvas_'.$row['run_type'], 'url'=>$output['url'],
                'title'=>(string)($row['prompt'] ?? $params['prompt'] ?? ''),
                'status'=>'ready', 'create_time'=>(int)$row['create_time'],
                'meta'=>array_intersect_key($params, array_flip(['prompt','model','model_id','channel','ratio','resolution','quality','duration'])),
            ]];
        }
        return $result;
    }
}
