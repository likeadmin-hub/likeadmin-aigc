<?php
namespace app\common\service;

use app\common\enum\FileEnum;
use app\common\service\app\AppAccessService;
use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\membership\MembershipService;
use app\common\service\open_platform\{OpenAssets, OpenException};
use InvalidArgumentException;
use think\facade\Db;

/** A read-only, chronological view of the current user's selectable media. */
final class WorkspaceMediaPickerService
{
    private const SIZE = 20;
    private const TYPES = ['image', 'video', 'audio', 'document'];
    private const VIDEO = ['video','digital_human','image_human','pic_lipsync','action_transfer','person_replacement','product_promo_video','watermark_removal','smart_clip'];

    public static function page(int $tenant, int $user, array $params): array
    {
        if ($tenant < 1 || $user < 1) throw new InvalidArgumentException('请先登录');
        $tab = $params['tab'] ?? 'uploads';
        if (!in_array($tab, ['uploads','assets'], true)) throw new InvalidArgumentException('素材来源无效');
        $types = explode(',', (string)($params['types'] ?? implode(',', self::TYPES)));
        if (array_diff($types, self::TYPES)) throw new InvalidArgumentException('素材类型无效');
        $keyword = trim((string)($params['search'] ?? ''));
        $cursor = (string)($params['cursor'] ?? '');
        $after = null;
        if ($cursor !== '') {
            if (!preg_match('/^(\d{1,12}):([a-z_]+:(?:result|task)):(\d{1,12}):(\d{1,6})$/D', $cursor, $m)) throw new InvalidArgumentException('素材分页位置无效');
            $after = [(int)$m[1], $m[2], (int)$m[3], (int)$m[4]];
        }

        $sources = $tab === 'uploads' ? ['upload:result'=>self::uploads()] : self::assets($tenant, $user, $types);
        $items = [];
        $next = null;
        // A canvas run can contain several outputs. Resume inside the run when a page ends there.
        do {
            $rows = self::candidates($tenant, $user, $sources, $types, $keyword, $after);
            $expanded = [];
            foreach ($rows as $row) {
                $outputs = self::outputs($tenant, $user, $row);
                $offset = $after && $row['stream'] === $after[1] && (int)$row['id'] === $after[2] ? $after[3] : 0;
                foreach (array_slice($outputs, $offset, null, true) as $i => $item) {
                    $position = [$row['stamp'], $row['stream'], $row['id'], $i + 1 < count($outputs) ? $i + 1 : 0];
                    $expanded[$item['id']] = [$item, implode(':', $position)];
                }
                $after = [(int)$row['stamp'], $row['stream'], (int)$row['id'], 0];
            }
            if ($expanded) {
                $hidden = Db::name('user_asset_state')->where(['tenant_id'=>$tenant, 'user_id'=>$user])
                    ->whereIn('asset_ref', array_keys($expanded))->whereRaw('(hidden_at > 0 OR trashed_at > 0)')->column('asset_ref');
                foreach (array_diff_key($expanded, array_flip($hidden)) as [$item, $position]) {
                    if (count($items) === self::SIZE) return ['lists'=>$items, 'next_cursor'=>$next];
                    $items[] = $item;
                    $next = $position;
                }
            }
        } while (count($rows) === self::SIZE + 1);
        return ['lists'=>$items, 'next_cursor'=>null];
    }

    private static function uploads(): array
    {
        return ['table'=>'tenant_file', 'owner'=>'source_id', 'type'=>"CASE WHEN r.type=10 THEN 'image' ELSE 'video' END",
            'name'=>'r.name', 'uri'=>'r.uri', 'where'=>"r.source=".FileEnum::SOURCE_USER." AND r.upload_origin='local' AND (r.type IN (10,20) OR (r.type=30 AND LOWER(r.uri) LIKE '%.webm'))"];
    }

    private static function assets(int $tenant, int $user, array $types): array
    {
        $enabled = AppAccessService::enabledTenantAppCodes($tenant);
        $saved = Db::name('user_asset_state')->where(['tenant_id'=>$tenant, 'user_id'=>$user,'hidden_at'=>0,'trashed_at'=>0])
            ->whereRaw("(favorite=1 OR label<>'')")->distinct(true)->fieldRaw("SUBSTRING_INDEX(asset_ref, ':', 2) AS stream")->select()->column('stream');
        $sources = [];
        foreach (WorkspaceAssetService::SOURCES as $source => $app) {
            // Fitting results are owned image results; saved stand-alone Agent artifacts are handled below.
            if (in_array($source, ['fitting','agent'], true)) continue;
            $stream = $source.':result';
            $available = in_array($app,$enabled,true) && MembershipService::userCanUseApp($tenant,$user,$app);
            if (!$available && !in_array($stream,$saved,true) && !($source==='canvas' && in_array('canvas:task',$saved,true))) continue;
            if ($source === 'canvas' || $source === 'short_drama') {
                $type = "CASE WHEN r.asset_type LIKE '%video%' THEN 'video' WHEN r.asset_type LIKE '%audio%' THEN 'audio' WHEN r.asset_type LIKE '%text%' OR r.asset_type LIKE '%document%' THEN 'document' ELSE 'image' END";
                $where = "r.status NOT IN ('deleted','failed','fail','error')";
                if ($source === 'short_drama') $where .= " AND r.asset_type IN ('subject_image','three_view','scene_image','shot_image','shot_video','bgm_audio','final_video','reference_image','canvas_image','canvas_video','canvas_audio')";
                $sources[$stream] = ['table'=>$app.'_asset','type'=>$type,'name'=>'r.title','uri'=>'r.uri','cover'=>'r.cover_uri','duration'=>'r.duration','extra'=>'r.meta_json','task'=>'r.task_id','where'=>$where,'saved'=>!$available];
                if ($source === 'canvas') $sources['canvas:task'] = ['table'=>'aigc_canvas_run','type'=>'r.run_type','name'=>'r.prompt','extra'=>'r.result_json',
                    'where'=>"r.status='success' AND r.run_type IN ('image','video')",'stored'=>false,'saved'=>!$available];
                continue;
            }
            $type = in_array($source,self::VIDEO,true) ? 'video' : (in_array($source,['music','music_cover'],true) ? 'audio' : 'image');
            if (!in_array($type,$types,true)) continue;
            $title = match ($source) {
                'music','digital_human','image_human','smart_clip' => "COALESCE(NULLIF(r.title,''),t.title)",
                'music_cover','pic_lipsync' => 't.title', 'watermark_removal' => "''", default => 't.prompt',
            };
            $spec = ['table'=>$app.'_result','join'=>$app.'_task','name'=>$title,'type'=>"'$type'",'uri'=>'r.'.$type.'_uri','saved'=>!$available];
            if ($source === 'music_cover') $spec['uri'] = "COALESCE(NULLIF(r.audio_uri,''),r.audio_url)";
            if (in_array($source,['digital_human','image_human','action_transfer','person_replacement','product_promo_video','smart_clip'],true)) $spec['cover']='r.cover_uri';
            if (in_array($source,['digital_human','image_human','action_transfer','person_replacement','smart_clip','music','music_cover'],true)) $spec['duration']='r.duration';
            if ($source==='product_promo_video') $spec['duration']='t.duration';
            if ($type==='image' && $source!=='image') $spec['alias']="CONCAT('image:result:',r.image_result_id)";
            if ($source==='product_promo_video') $spec['alias']="CONCAT('video:result:',r.video_result_id)";
            $sources[$stream]=$spec;
        }
        if (in_array('video',$types,true) && (in_array('aigc_micro_video',$enabled,true) || in_array('micro_video:task',$saved,true))) {
            $sources['micro_video:task']=['table'=>'aigc_micro_video_task','type'=>"'video'",'name'=>'r.title','uri'=>'r.result_uri','cover'=>'r.cover_uri',
                'where'=>"r.status='success'",'saved'=>!in_array('aigc_micro_video',$enabled,true)];
        }
        if (in_array('agent:result',$saved,true)) $sources['agent:result']=['table'=>'aigc_llm_agent_artifact','type'=>'r.kind','name'=>'r.title','extra'=>'r.data','deleted'=>false,'stored'=>false,'saved'=>true];
        return $sources;
    }

    /** Each owned source contributes at most one page plus a lookahead to the global merge. */
    private static function candidates(int $tenant, int $user, array $sources, array $types, string $keyword, ?array $after): array
    {
        $parts = $bindings = [];
        $size = self::SIZE + 1;
        foreach ($sources as $stream=>$s) {
            $table = Db::name($s['table'])->getTable();
            $from = "`$table` r";
            if (isset($s['join'])) {
                $task = Db::name($s['join'])->getTable();
                $from .= " JOIN `$task` t ON t.id=r.task_id AND t.tenant_id=r.tenant_id AND t.user_id=r.user_id AND t.delete_time=0";
            }
            $owner = $s['owner'] ?? 'user_id';
            $conditions = ["r.tenant_id=?", "r.$owner=?", $s['where'] ?? '1=1'];
            array_push($bindings,$tenant,$user);
            if ($s['deleted'] ?? true) $conditions[] = 'COALESCE(r.delete_time,0)=0';
            if (isset($s['uri'])) $conditions[] = $s['uri']."<>''";
            $conditions[] = $s['type'].' IN ('.implode(',',array_fill(0,count($types),'?')).')';
            array_push($bindings,...$types);
            $name = $s['name'];
            if ($keyword !== '') {
                $conditions[] = "LOCATE(?, $name)>0";
                $bindings[] = $keyword;
            }
            if ($s['saved'] ?? false) {
                $state = Db::name('user_asset_state')->getTable();
                $ref = $stream==='canvas:task' ? "a.asset_ref LIKE CONCAT('$stream:',r.id,':%')" : "a.asset_ref=CONCAT('$stream:',r.id)";
                $conditions[] = "EXISTS (SELECT 1 FROM `$state` a WHERE a.tenant_id=r.tenant_id AND a.user_id=r.$owner AND $ref AND a.hidden_at=0 AND a.trashed_at=0 AND (a.favorite=1 OR a.label<>''))";
            }
            $stamp = 'COALESCE(r.create_time,0)';
            if ($after) {
                [$time,$source,$id,$offset] = $after;
                $operator = strcmp($stream,$source)>0 || ($stream===$source && $offset>0) ? '<=' : '<';
                $conditions[] = "($stamp < ? OR ($stamp = ? AND r.id $operator ?))";
                array_push($bindings,$time,$time,$id);
            }
            $stored = $s['stored'] ?? true;
            $fields = ['r.id',"$stamp AS stamp","'$stream' AS stream", $s['type'].' AS type',"$name AS name"];
            foreach (['uri','cover','duration','extra','task','alias'] as $key) $fields[] = ($s[$key] ?? "''")." AS `$key`";
            foreach (['storage_scope','storage_engine','storage_domain'] as $key) $fields[] = ($stored ? "r.$key" : "''")." AS $key";
            $parts[] = '(SELECT '.implode(',',$fields).' FROM '.$from.' WHERE '.implode(' AND ',$conditions)." ORDER BY stamp DESC,r.id DESC LIMIT $size)";
        }
        if (!$parts) return [];
        $sql = 'SELECT * FROM ('.implode(' UNION ALL ',$parts).") media ORDER BY stamp DESC,id DESC,stream ASC LIMIT $size";
        return Db::query($sql,$bindings);
    }

    private static function outputs(int $tenant, int $user, array $row): array
    {
        $type = $row['type'];
        $ref = $row['stream'].':'.$row['id'];
        $name = trim((string)$row['name']);
        $base = ['type'=>$type,'name'=>$name,'created_at'=>(int)$row['stamp']];
        if ($row['stream'] === 'canvas:task') {
            $payload = json_decode($row['extra'] ?? '',true) ?: [];
            $keys = ['results','images','videos','image_url','image_uri','video_url','video_uri','url','file_url'];
            $outputs = AigcCanvasService::runResultItems($type,array_intersect_key($payload,array_flip($keys)));
            return array_map(static fn($item)=>$base+['id'=>$ref.':'.$item['id'],'url'=>$item['url'],'uri'=>$item['url']],$outputs);
        }
        if ($row['stream'] === 'agent:result') {
            $data = json_decode($row['extra'] ?? '',true) ?: [];
            try { $url = OpenAssets::detail($tenant,$user,(string)($data['file_id'] ?? ''))['url']; }
            catch (OpenException $e) { if ($e->errorType==='not_found') return []; throw $e; }
            return [$base+['id'=>$ref,'url'=>$url,'uri'=>$url]];
        }
        $alias = $row['alias'] ?: $ref;
        if (str_ends_with($alias,':0')) $alias=$ref;
        if ($row['stream']==='short_drama:result' || $row['stream']==='canvas:result') {
            $meta = json_decode($row['extra'] ?? '',true) ?: [];
            if (WorkspaceShortDramaAssetService::isUpload(['task_id'=>$row['task'],'meta'=>$meta]) || !empty($meta['source_subject_id'])) return [];
            if (!empty($meta['reused_from_asset_id'])) $alias=$row['stream'].':'.(int)$meta['reused_from_asset_id'];
        }
        $url = static fn($uri)=>FileService::getFileUrlByStorage((string)$uri,$row['storage_scope'],$row['storage_engine'],$row['storage_domain']);
        return [$base+['id'=>$ref,'canonical_id'=>$alias,'uri'=>$row['uri'],'url'=>$url($row['uri']),'cover'=>$url($row['cover']),'duration'=>(float)$row['duration']]];
    }
}
