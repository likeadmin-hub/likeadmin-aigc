<?php
namespace app\common\service;

use app\common\enum\FileEnum;
use app\common\service\app\AppAccessService;
use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\membership\MembershipService;
use app\common\service\open_platform\{OpenAssets, OpenException, OpenStore};
use InvalidArgumentException;
use think\facade\Db;

/** A read-only, chronological view of the current user's selectable media. */
final class WorkspaceMediaPickerService
{
    private const SIZE = 20;
    private const TYPES = ['image', 'video', 'audio', 'document'];
    private const VIDEO = ['video','digital_human','image_human','pic_lipsync','action_transfer','motion_sync','person_replacement','product_promo_video','watermark_removal','smart_clip'];

    /** Resolve the exact picker identity using the same source/ownership/visibility rules as listing.
     * Storage coordinates are returned only to server callers; no client URL is trusted. */
    public static function resolve(int $tenant, int $user, string $id): array
    {
        if ($tenant < 1 || $user < 1) throw new InvalidArgumentException('请先登录');
        if (!preg_match('/^([a-z_]+:(?:result|task)):([1-9][0-9]{0,10})(?::([1-9][0-9]{0,4}))?$/D', $id, $match)) throw new InvalidArgumentException('素材标识无效');
        $stream=$match[1];
        if (in_array($stream,['canvas:task','product_video:task'],true) !== isset($match[3])) throw new InvalidArgumentException('素材标识无效');
        $sources=$stream==='upload:result' ? [$stream=>self::uploads()] : self::assets($tenant,$user,self::TYPES);
        if (!isset($sources[$stream])) throw new InvalidArgumentException('素材不存在或无权引用');
        $hidden=Db::name('user_asset_state')->where(['tenant_id'=>$tenant,'user_id'=>$user,'asset_ref'=>$id])->whereRaw('(hidden_at > 0 OR trashed_at > 0)')->count();
        if (!$hidden) foreach (self::candidates($tenant,$user,[$stream=>$sources[$stream]],self::TYPES,[],'',null,(int)$match[2]) as $row) {
            foreach (self::outputs($tenant,$user,$row) as $item) if ($item['id']===$id) {
                if ($stream==='agent:result') {
                    $data=json_decode($row['extra'] ?? '',true) ?: [];
                    $file=OpenStore::owner('file',$tenant,$user)->where('file_id',(string)($data['file_id'] ?? ''))->find();
                    if (!$file) throw new InvalidArgumentException('素材不存在或无权引用');
                    return array_replace($item,array_intersect_key($file,array_flip(['uri','storage_scope','storage_engine','storage_domain','mime','size'])))+['file_id'=>(string)$data['file_id']];
                }
                return $item+['cover_uri'=>(string)($row['cover'] ?? '')]+array_intersect_key($row,array_flip(['storage_scope','storage_engine','storage_domain']));
            }
        }
        throw new InvalidArgumentException('素材不存在或无权引用');
    }

    /** Attach an owned library source to a destination asset without trusting client coordinates. */
    public static function referenceParams(int $tenant, int $user, array $params, string $targetType): array
    {
        $meta=is_array($params['meta'] ?? null) ? $params['meta'] : [];
        if (!array_key_exists('source_library_id',$meta)) return $params;
        if (!is_string($meta['source_library_id'])) throw new InvalidArgumentException('素材标识无效');
        $asset=self::resolve($tenant,$user,$meta['source_library_id']);
        $target=strtolower(trim($targetType));
        $kind=match ($target) {
            'video','reference_video','shot_video','final_video','canvas_video'=>'video',
            'audio','reference_audio','bgm_audio','canvas_audio'=>'audio',
            'document','script_document','canvas_document','text','canvas_text'=>'document',
            default=>'image', // Image business labels, including normalized unknown labels.
        };
        if ($asset['type']!==$kind) throw new InvalidArgumentException('素材类型与当前入口不一致');
        return array_replace($params,array_intersect_key($asset,array_flip(['uri','storage_scope','storage_engine','storage_domain','cover_uri'])),[
            'mime_type'=>(string)($asset['mime'] ?? ''),'file_size'=>max(0,(int)($asset['size'] ?? 0)),
            'width'=>max(0,(int)($asset['width'] ?? 0)),'height'=>max(0,(int)($asset['height'] ?? 0)),
            'duration'=>max(0,(float)($asset['duration'] ?? 0)),'checksum'=>'',
        ]);
    }

    public static function page(int $tenant, int $user, array $params): array
    {
        if ($tenant < 1 || $user < 1) throw new InvalidArgumentException('请先登录');
        $tab = $params['tab'] ?? 'uploads';
        if (!in_array($tab, ['uploads','assets'], true)) throw new InvalidArgumentException('素材来源无效');
        $types = array_values(array_unique(explode(',', (string)($params['types'] ?? implode(',', self::TYPES)))));
        $formats = self::formats((string)($params['formats'] ?? ''));
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
            $rows = self::candidates($tenant, $user, $sources, $types, $formats, $keyword, $after);
            $expanded = [];
            foreach ($rows as $row) {
                $outputs = self::outputs($tenant, $user, $row);
                $offset = $after && $row['stream'] === $after[1] && (int)$row['id'] === $after[2] ? $after[3] : 0;
                foreach (array_slice($outputs, $offset, null, true) as $i => $item) {
                    if ($formats && !in_array($item['type'],$formats['wildcards'],true)) {
                        $path = parse_url($item['url'],PHP_URL_PATH) ?: '';
                        if (!in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)), $formats['extensions'],true)) continue;
                    }
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

    private static function formats(string $value): array
    {
        if ($value === '') return [];
        $extensions = $wildcards = [];
        foreach (explode(',', $value) as $rule) {
            if (preg_match('~^(image|video|audio)/\*$~D', $rule, $match)) $wildcards[] = $match[1];
            elseif (preg_match('/^[a-z0-9]{1,16}$/D', $rule)) $extensions[] = $rule;
            else throw new InvalidArgumentException('素材格式无效');
        }
        return ['extensions'=>array_unique($extensions), 'wildcards'=>array_unique($wildcards)];
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
            $stream = $source==='product_video' ? 'product_video:task' : $source.':result';
            $available = $source === 'canvas' || (in_array($app,$enabled,true) && MembershipService::userCanUseApp($tenant,$user,$app));
            if (!$available && !in_array($stream,$saved,true) && !($source==='canvas' && in_array('canvas:task',$saved,true))) continue;
            if ($source==='product_video') {
                if (in_array('video',$types,true)) $sources[$stream]=WorkspaceProductVideoAssetService::pickerSource(!$available);
                continue;
            }
            if ($source === 'canvas' || $source === 'short_drama') {
                $type = "CASE WHEN r.asset_type LIKE '%video%' THEN 'video' WHEN r.asset_type LIKE '%audio%' THEN 'audio' WHEN r.asset_type LIKE '%text%' OR r.asset_type LIKE '%document%' THEN 'document' ELSE 'image' END";
                $where = "r.status NOT IN ('deleted','failed','fail','error')";
                if ($source === 'short_drama') $where .= " AND r.asset_type IN ('subject_image','three_view','scene_image','shot_image','shot_video','bgm_audio','final_video','reference_image','canvas_image','canvas_video','canvas_audio')";
                $sources[$stream] = ['table'=>$app.'_asset','type'=>$type,'name'=>'r.title','uri'=>'r.uri','cover'=>'r.cover_uri','duration'=>'r.duration','mime'=>'r.mime_type','size'=>'r.file_size','width'=>'r.width','height'=>'r.height','extra'=>'r.meta_json','task'=>'r.task_id','where'=>$where,'saved'=>!$available];
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
            if (in_array($source,['music','music_cover'],true)) { $spec['mime']='r.mime_type'; $spec['size']='r.file_size'; }
            if (in_array($source,['image','video','motion_sync'],true)) { $spec['width']='r.width'; $spec['height']='r.height'; }
            if (in_array($source,['digital_human','image_human','action_transfer','motion_sync','person_replacement','product_promo_video','smart_clip'],true)) $spec['cover']='r.cover_uri';
            if (in_array($source,['digital_human','image_human','action_transfer','motion_sync','person_replacement','smart_clip','music','music_cover'],true)) $spec['duration']='r.duration';
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
    private static function candidates(int $tenant, int $user, array $sources, array $types, array $formats, string $keyword, ?array $after, ?int $exactId = null): array
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
            if ($exactId !== null) { $conditions[]='r.id=?'; $bindings[]=$exactId; }
            if ($s['deleted'] ?? true) $conditions[] = 'COALESCE(r.delete_time,0)=0';
            if (isset($s['uri'])) $conditions[] = $s['uri']."<>''";
            $conditions[] = $s['type'].' IN ('.implode(',',array_fill(0,count($types),'?')).')';
            array_push($bindings,...$types);
            // Exclude incompatible stored files before the page limit. Canvas runs are expanded below.
            if ($formats && isset($s['uri'])) {
                $matches = [];
                foreach (['wildcards'=>$s['type'], 'extensions'=>"LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(".$s['uri'].", '?', 1), '#', 1), '.', -1))"] as $key=>$field) {
                    if (!$formats[$key]) continue;
                    $matches[] = $field.' IN ('.implode(',',array_fill(0,count($formats[$key]),'?')).')';
                    array_push($bindings,...$formats[$key]);
                }
                $conditions[] = '('.implode(' OR ',$matches).')';
            }
            $name = $s['name'];
            if ($keyword !== '') {
                $conditions[] = "LOCATE(?, $name)>0";
                $bindings[] = $keyword;
            }
            if ($s['saved'] ?? false) {
                $state = Db::name('user_asset_state')->getTable();
                $ref = in_array($stream,['canvas:task','product_video:task'],true) ? "a.asset_ref LIKE CONCAT('$stream:',r.id,':%')" : "a.asset_ref=CONCAT('$stream:',r.id)";
                $conditions[] = "EXISTS (SELECT 1 FROM `$state` a WHERE a.tenant_id=r.tenant_id AND a.user_id=r.$owner AND $ref AND a.hidden_at=0 AND a.trashed_at=0 AND (a.favorite=1 OR a.label<>''))";
            }
            $stamp = 'COALESCE(r.create_time,0)';
            if ($stream === 'short_drama:result') {
                // Reusing a subject in another episode keeps the original work's creation time.
                $original = "CAST(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(r.meta_json),r.meta_json,'{}'),'$.reused_from_asset_id')) AS UNSIGNED)";
                $from .= " LEFT JOIN `$table` orig ON orig.id=$original AND orig.tenant_id=r.tenant_id AND orig.user_id=r.user_id AND orig.uri=r.uri";
                $stamp = 'COALESCE(orig.create_time,r.create_time,0)';
                $s['alias'] = "CONCAT('$stream:',COALESCE(orig.id,r.id))";
            }
            if ($after) {
                [$time,$source,$id,$offset] = $after;
                $operator = strcmp($stream,$source)>0 || ($stream===$source && $offset>0) ? '<=' : '<';
                $conditions[] = "($stamp < ? OR ($stamp = ? AND r.id $operator ?))";
                array_push($bindings,$time,$time,$id);
            }
            $stored = $s['stored'] ?? true;
            $fields = ['r.id',"$stamp AS stamp","'$stream' AS stream", $s['type'].' AS type',"$name AS name"];
            foreach (['uri','cover','duration','extra','task','alias','mime','size','width','height'] as $key) $fields[] = ($s[$key] ?? "''")." AS `$key`";
            foreach (['storage_scope','storage_engine','storage_domain'] as $key) $fields[] = ($stored ? "r.$key" : "''")." AS $key";
            $parts[] = '(SELECT '.implode(',',$fields).' FROM '.$from.' WHERE '.implode(' AND ',$conditions)." ORDER BY stamp DESC,r.id DESC LIMIT $size)";
        }
        if (!$parts) return [];
        $sql = 'SELECT * FROM ('.implode(' UNION ALL ',$parts).") media ORDER BY stamp DESC,id DESC,stream ASC LIMIT $size";
        return Db::query($sql,$bindings);
    }

    private static function outputs(int $tenant, int $user, array $row): array
    {
        if ($row['stream']==='product_video:task') return WorkspaceProductVideoAssetService::pickerOutputs($tenant,$user,$row);
        $type = $row['type'];
        $ref = $row['stream'].':'.$row['id'];
        $name = trim((string)$row['name']);
        $base = ['type'=>$type,'name'=>$name,'created_at'=>(int)$row['stamp'],'mime'=>(string)($row['mime'] ?? ''),'size'=>max(0,(int)($row['size'] ?? 0)),'width'=>max(0,(int)($row['width'] ?? 0)),'height'=>max(0,(int)($row['height'] ?? 0))];
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
            if ($row['stream']==='canvas:result' && !empty($meta['reused_from_asset_id'])) $alias=$row['stream'].':'.(int)$meta['reused_from_asset_id'];
        }
        $url = static fn($uri)=>FileService::getFileUrlByStorage((string)$uri,$row['storage_scope'],$row['storage_engine'],$row['storage_domain']);
        return [$base+['id'=>$ref,'canonical_id'=>$alias,'uri'=>$row['uri'],'url'=>$url($row['uri']),'cover'=>$url($row['cover']),'duration'=>(float)$row['duration']]];
    }
}
