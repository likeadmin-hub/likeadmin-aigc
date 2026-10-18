<?php
namespace app\common\service\app\aigc_canvas;

use app\common\model\ai\AiConsumptionLog;
use app\common\model\app\aigc_canvas\{AigcCanvasProject,AigcCanvasRun};
use app\common\model\power\PowerMarketProduct;
use app\common\service\FileService;
use InvalidArgumentException;

/** Read-only public generation facts, bound to an owned output rather than editable node controls. */
final class CanvasMediaPreviewService
{
    public static function detail(int $tenant, int $user, array $input): array
    {
        if ($tenant<1 || $user<1) throw new InvalidArgumentException('请先登录');
        $project=AigcCanvasProject::where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>(int)($input['project_id'] ?? 0),'delete_time'=>0])->findOrEmpty();
        if ($project->isEmpty()) throw new InvalidArgumentException('画布项目不存在');
        $graph=CanvasProjectCompatibility::adapt($project->toArray());
        foreach ($graph['nodes_json'] as $node) {
            if ($node['id']!==($input['node_id'] ?? '') || !in_array($node['type'],['image','video'],true)) continue;
            $metadata=$node['metadata'] ?? [];
            $items=array_key_exists('mediaItems',$metadata)?$metadata['mediaItems']:[$metadata];
            foreach ($items as $item) {
                $url=CanvasTemplateMedia::url($item,$node['type']);
                if (!self::sameMedia($url,(string)($input['url'] ?? ''))) continue;
                return self::forMedia($tenant,$user,(int)$project['id'],$node['id'],$node['type'],$url,$item + array_intersect_key($metadata,array_flip(['agentArtifactId'])));
            }
        }
        return [];
    }

    public static function forMedia(int $tenant,int $user,int $project,string $node,string $type,string $url,array $item=[]): array
    {
        // An item-level snapshot survives collection selection, node edits and template remapping.
        $embedded=self::sanitize((array)($item['generation'] ?? []));
        if ($embedded) return $embedded;
        $page=1;
        do {
            $runs=AigcCanvasRun::where(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$project,'node_id'=>$node,'run_type'=>$type,'delete_time'=>0])
                ->order('id','desc')->page($page++,100)->select()->toArray();
            foreach ($runs as $run) {
                foreach (AigcCanvasService::runResultItems($type,self::array($run['result_json'] ?? [])) as $result) {
                    if (!self::sameMedia((string)($result['url'] ?? ''),$url)) continue;
                    $consumption=AiConsumptionLog::where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>(int)$run['source_task_id'],'app_code'=>'aigc_canvas'])->findOrEmpty();
                    $price=$consumption->isEmpty()?[]:(array)$consumption['price_snapshot'];
                    $params=self::array($run['params_json'] ?? []);
                    $model=(string)($params['submitted_model_label'] ?? $price['model_name'] ?? $price['app_name'] ?? '');
                    if ($model==='') $model=(string)PowerMarketProduct::where('id',(int)($price['product_id'] ?? $params['market_product_id'] ?? 0))->value('name');
                    return self::fromRun($run,$price,$model);
                }
            }
        } while (count($runs)===100);
        if (!empty($item['agentArtifactId'])) {
            $artifact=\app\common\service\app\aigc_llm\agent\AgentStore::owner('artifact',$tenant,$user)->where(['id'=>(int)$item['agentArtifactId'],'kind'=>$type])->find();
            if ($artifact) {
                try {
                    $data=\app\common\service\app\aigc_llm\agent\AgentStore::json($artifact['data']);
                    $asset=\app\common\service\open_platform\OpenAssets::detail($tenant,$user,$data['file_id']);
                    if (self::sameMedia((string)($asset['url'] ?? ''),$url)) {
                        $generation=\app\common\service\app\aigc_llm\agent\AgentArtifactService::canvasGeneration($artifact);
                        if ($generation) return self::fromRun(['run_type'=>$type,'params_json'=>$generation + ['reference_assets'=>$generation['references'] ?? []], 'create_time'=>$generation['create_time'] ?? ''],[],$generation['model_name'] ?? '');
                    }
                } catch (\app\common\service\open_platform\OpenException $e) {
                    if ($e->errorType!=='not_found') throw $e;
                }
            }
        }
        // Old instantiated presets have new node IDs, but their catalog output is public and immutable.
        foreach (CanvasBuiltinPresetService::all() as $preset) foreach ($preset['graph']['nodes'] as $source) {
            if ($source['type']!==$type) continue;
            foreach ($source['metadata']['mediaItems'] ?? [] as $media) {
                if (self::sameMedia($url,(string)($media['url'] ?? ''))) return self::sanitize((array)($media['generation'] ?? []));
            }
        }
        return [];
    }

    public static function fromRun(array $run,array $price=[],string $model=''): array
    {
        $params=self::array($run['params_json'] ?? []);
        $result=self::array($run['result_json'] ?? []);
        $submitted=self::array($result['submitted_input'] ?? []);
        $params=array_replace($params,$submitted);
        $type=(string)($run['run_type'] ?? '');
        if (!in_array($type,['image','video'],true)) return [];
        $parameters=[];
        $add=static function(string $label,$value) use (&$parameters): void {
            if (is_scalar($value) && trim((string)$value)!=='') $parameters[]=['label'=>$label,'value'=>(string)$value];
        };
        $method=(string)($params['generation_method'] ?? '');
        $methods=['text_to_image'=>'文生图','image_reference'=>'参考图生图','text_to_video'=>'文生视频','image_to_video'=>'图生视频','first_last_frame'=>'首尾帧','omni_reference'=>'全能参考','video_to_video'=>'视频生视频'];
        $add('生成方式',$methods[$method] ?? '');
        $add('模型',$model ?: ($price['model_code'] ?? $price['app_code'] ?? $params['model_code'] ?? ''));
        $ratio=$params['ratio'] ?? '';
        $add('长宽比',in_array($ratio,['auto','adaptive'],true)?'自适应':$ratio);
        $add('分辨率',$price['locked_params']['resolution'] ?? $params['resolution'] ?? $params['quality'] ?? '');
        if ($type==='video') $add('时长',empty($params['duration'])?'':$params['duration'].'秒');
        else $add('数量',empty($params['quantity'])?'':$params['quantity'].'张');
        $references=[];
        foreach ($params['reference_assets'] ?? [] as $ref) if (is_array($ref)) $references[]=['url'=>$ref['url'] ?? $ref['uri'] ?? '', 'type'=>$ref['type'] ?? 'image','title'=>$ref['name'] ?? ''];
        foreach (['reference_images'=>'image','video_urls'=>'video','audio_urls'=>'audio'] as $field=>$kind) foreach ($params[$field] ?? [] as $ref) {
            $references[]=['url'=>is_array($ref)?($ref['url'] ?? $ref['uri'] ?? ''):$ref,'type'=>$kind];
        }
        return self::sanitize(['version'=>1,'kind'=>$type,'prompt'=>$params['compiled_prompt'] ?? $params['prompt'] ?? $run['prompt'] ?? '',
            'created'=>(string)($run['create_time'] ?? ''),'parameters'=>$parameters,'references'=>$references]);
    }

    /** Never serialize a task ID, tenant/user identity, provider payload, secret or pricing snapshot. */
    public static function sanitize(array $value): array
    {
        if (($value['version'] ?? 0)!==1 || !in_array($value['kind'] ?? '',['image','video'],true)) return [];
        $text=static fn($v,int $max)=>is_scalar($v)?mb_substr(trim((string)$v),0,$max):'';
        $result=['version'=>1,'kind'=>$value['kind'],'prompt'=>$text($value['prompt'] ?? '',30000),'created'=>$text($value['created'] ?? '',40),'parameters'=>[],'references'=>[]];
        $labels=['生成方式','模型','长宽比','分辨率','时长','数量','运镜效果'];$seen=[];
        foreach (array_slice((array)($value['parameters'] ?? []),0,20) as $p) {
            if (!is_array($p) || !in_array($p['label'] ?? '',$labels,true) || isset($seen[$p['label']])) continue;
            $v=$text($p['value'] ?? '',200);if ($v==='') continue;
            $seen[$p['label']]=true;$result['parameters'][]=['label'=>$p['label'],'value'=>$v];
        }
        $seen=[];
        foreach (array_slice((array)($value['references'] ?? []),0,64) as $ref) {
            if (!is_array($ref) || !in_array($ref['type'] ?? '',['image','video','audio'],true)) continue;
            $url=$text($ref['url'] ?? '',4096);
            if ($url!=='' && self::safeUrl($url) && !preg_match('~^https?://~i',$url)) $url=FileService::getFileUrl($url);
            if (!self::safeUrl($url)) continue;
            $key=$ref['type'].':'.$url;if (isset($seen[$key])) continue;$seen[$key]=true;
            $entry=['url'=>$url,'type'=>$ref['type'],'title'=>$text($ref['title'] ?? '',120)];
            // Only the existing storage descriptor is retained for the template media copy/hydration path.
            if (is_array($ref['template_file'] ?? null)) $entry['template_file']=array_intersect_key($ref['template_file'],array_flip(['uri','checksum','storage_scope','storage_engine','storage_domain','mime_type','file_size']));
            $result['references'][]=$entry;
        }
        return $result;
    }

    public static function sameMedia(string $left,string $right): bool
    {
        if ($left==='' || $right==='') return false;
        $identity=static function(string $url): string {
            if (!preg_match('~^https?://~i',$url)) $url=FileService::getFileUrl($url);
            $p=parse_url($url);
            return strtolower(($p['scheme'] ?? '').'://'.($p['host'] ?? '')).(isset($p['port'])?':'.$p['port']:'').($p['path'] ?? '');
        };
        return $identity($left)===$identity($right);
    }
    private static function safeUrl(string $url): bool { return $url!=='' && !preg_match('/[\x00-\x1f\x7f]/',$url) && (!preg_match('/^[a-z][a-z\d+.-]*:/i',$url) || preg_match('~^https?://~i',$url)); }
    private static function array($value): array { return is_array($value)?$value:(json_decode((string)$value,true) ?: []); }
}
