<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;
use RuntimeException;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasPages;
use think\facade\Db;

/** Reuse is tied to a server-owned entity key, prompt version and original asset receipt. */
final class ConversationAssetReuse
{
    public static function signatures(array $proposals,array $state,array $settings): array
    {
        $keys=[];$result=[];
        foreach ($proposals as $index=>$p) {
            $parents=[];foreach((array)($p['depends_on']??[]) as $key) $parents[]=$keys[$key]??'';
            $signature=hash('sha256',json_encode([$p['artifact']??'', $p['key']??'',trim((string)$p['prompt']),
                $state['creative_settings']??[], $settings['image_model']??[], $parents],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $result[$index]=$signature;$keys[$p['key']??'']=$signature;
        }
        return $result;
    }
    public static function find(int $tenant,int $user,array $document,array $state,array $proposals,array $settings): array
    {
        if (!ConversationSeries::enabled($state)) return [];
        $signatures=self::signatures($proposals,$state,$settings);
        // A user may move on before the old page's images finish or are even
        // planned. Discover those later receipts at quote time, without
        // mutating either page or pretending pending files are reusable.
        $candidates=[];
        $cache=[(int)$document['id']=>json_decode((string)$document['nodes_json'],true)?:[]];
        $pageIds=array_values(array_filter((array)($state['series']['page_ids']??[]),static fn($id)=>is_int($id) && $id>0 && $id!==(int)$document['id']));
        if ($pageIds && $signatures) {
            $root=ShortDramaCanvasPages::root($tenant,$user,(int)$document['id']);
            $owned=Db::name(ShortDramaCanvasPages::TABLE)->where(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$root])->whereIn('canvas_id',$pageIds)->column('canvas_id');
            $wanted=[];
            foreach($proposals as $index=>$proposal) if(in_array($proposal['artifact']??'',['subject','three_view','scene'],true)) $wanted[$signatures[$index]]=$proposal;
            $found=[];$before=PHP_INT_MAX;
            while($owned && count($found)<count($wanted)) {
                // At most twenty bounded page documents in memory. Retain only
                // matching nodes, never every episode's full screenplay graph.
                $pages=Db::name(GraphService::TABLE)->where(['tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->whereIn('id',$owned)->where('id','<',$before)
                    ->where(static function($query)use($wanted):void { foreach(array_keys($wanted) as $signature) $query->whereOr('nodes_json','like','%'.$signature.'%'); })
                    ->field('id,nodes_json')->order('id','desc')->limit(20)->select()->toArray();
                if(!$pages) break;
                foreach($pages as $page) {
                    $before=(int)$page['id'];$matching=[];
                    foreach(json_decode($page['nodes_json'],true)?:[] as $node) {
                        $signature=$node['metadata']['workflow_asset_signature']??'';
                        if(!isset($wanted[$signature])) continue;
                        if(isset($found[$signature]) || !self::matches($node,$signature,$wanted[$signature])) continue;
                        $asset=Db::name('aigc_short_drama_asset')->where(['id'=>(int)$node['metadata']['asset_id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0,'status'=>'ready'])->find();
                        if(!$asset || !in_array((int)$asset['canvas_id'],[0,(int)$page['id']],true)) continue;
                        $matching[]=$node;
                        $found[$signature]=true;
                        $candidates[]=['canvas_id'=>(int)$page['id'],'node_id'=>(string)$node['id'],'signature'=>$signature];
                    }
                    $cache[(int)$page['id']]=$matching;
                }
            }
        }
        $candidates=array_merge($candidates,(array)($state['series']['asset_library']??[]));
        foreach ((array)($state['artifact_memory']??[]) as $item) $candidates[]=['canvas_id'=>(int)$document['id'],'node_id'=>(string)($item['node_id']??'')];
        $reuse=[];
        foreach (array_reverse($candidates) as $candidate) {
            if (isset($candidate['signature']) && !in_array($candidate['signature'],$signatures,true)) continue;
            $canvas=(int)($candidate['canvas_id']??0);$id=(string)($candidate['node_id']??'');
            if (!$canvas || $id==='') continue;
            if (!isset($cache[$canvas])) {
                $source=Db::name(GraphService::TABLE)->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->find();
                $cache[$canvas]=$source?(json_decode((string)$source['nodes_json'],true)?:[]):[];
            }
            foreach ($cache[$canvas] as $node) {
                if ((string)$node['id']!==$id) continue;
                foreach ($proposals as $index=>$p) {
                    if (isset($reuse[$index])) continue;
                    if (!in_array($p['artifact']??'',['subject','three_view','scene'],true)) continue;
                    if (!self::matches($node,$signatures[$index],$p)) continue;
                    $asset=Db::name('aigc_short_drama_asset')->where(['id'=>(int)$node['metadata']['asset_id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0,'status'=>'ready'])->find();
                    if (!$asset || !in_array((int)$asset['canvas_id'],[0,$canvas],true)) continue;
                    $reuse[$index]=['canvas_id'=>$canvas,'node_id'=>$id,'signature'=>$signatures[$index],
                        'content_revision'=>(int)$node['metadata']['content_revision'],'asset_id'=>(int)$asset['id']];
                }
            }
        }
        return $reuse;
    }
    public static function matches(array $node,string $signature,array $proposal): bool
    {
        $meta=(array)($node['metadata']??[]);
        return trim((string)($meta['prompt']??''))===trim((string)($proposal['prompt']??'')) && ($node['type']??'')==='image' && ($meta['status']??'')==='success' && !empty($meta['image'])
            && (int)($meta['asset_id']??0)>0 && hash_equals($signature,(string)($meta['workflow_asset_signature']??''))
            && ($meta['workflow_artifact']??'')===($proposal['artifact']??'')
            && ($meta['workflow_key']??'')===($proposal['artifact']==='scene'?'storyboard:':'assets:').($proposal['key']??'');
    }
    public static function source(int $tenant,int $user,array $reuse,array $proposal): array
    {
        $document=Db::name(GraphService::TABLE)->where(['id'=>$reuse['canvas_id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->find();
        foreach (json_decode((string)($document['nodes_json']??'[]'),true)?:[] as $node) {
            if ((string)$node['id']!==(string)$reuse['node_id']) continue;
            if (self::matches($node,$reuse['signature'],$proposal)
                && (int)$node['metadata']['content_revision']===(int)$reuse['content_revision']
                && (int)$node['metadata']['asset_id']===(int)$reuse['asset_id']) return $node;
        }
        throw new RuntimeException('WORKFLOW_REUSABLE_ASSET_CHANGED');
    }
    /** Copy an owned asset reference; never download, regenerate or charge for the file. */
    public static function copyAsset(int $tenant,int $user,int $canvas,array $reuse): int
    {
        $asset=Db::name('aigc_short_drama_asset')->where(['id'=>$reuse['asset_id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0,'status'=>'ready'])->lock(true)->find();
        if (!$asset || !in_array((int)$asset['canvas_id'],[0,(int)$reuse['canvas_id']],true)) throw new RuntimeException('WORKFLOW_REUSABLE_ASSET_CHANGED');
        if ((int)$reuse['canvas_id']===$canvas) return (int)$asset['id'];
        unset($asset['id']);$asset['canvas_id']=$canvas;
        $meta=json_decode((string)($asset['meta_json']??'{}'),true)?:[];
        $meta['reused_from_asset_id']=(int)$reuse['asset_id'];$meta['canvas_id']=$canvas;
        $asset['meta_json']=json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $asset['create_time']=time();$asset['update_time']=time();
        return (int)Db::name('aigc_short_drama_asset')->insertGetId($asset);
    }
}
