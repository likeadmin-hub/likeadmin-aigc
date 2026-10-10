<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use think\facade\Db;
use app\common\service\FileService;

/** Read-only display projection. Never authorizes a provider input. */
final class ConversationMessageMaterials
{
    public static function project(array $scope,array $attachments,array $context): array
    {
        $items=[];
        foreach ($attachments as $index=>$item) {
            $items[]=['key'=>'attachment-'.$index,'type'=>$item['type'],'name'=>$item['name'],
                'asset_id'=>(int)($item['asset_id']??0)];
        }
        foreach ((array)($context['selected_nodes']??[]) as $node) {
            $items[]=['key'=>'node-'.$node['id'],'type'=>$node['type'],
                'name'=>(string)($node['title']??'引用素材'),
                'asset_id'=>(int)($node['image_asset']['id']??0),'frozen'=>$node['image_asset']??null];
        }
        $ids=array_values(array_filter(array_column($items,'asset_id')));
        $assets=$ids ? Db::name('aigc_short_drama_asset')->where([
            'tenant_id'=>$scope['tenant_id'],'user_id'=>$scope['user_id'],'delete_time'=>0,'status'=>'ready',
        ])->whereIn('canvas_id',[0,$scope['canvas_id']])->whereIn('id',$ids)->select()->toArray() : [];
        $byId=[];foreach ($assets as $asset) $byId[(int)$asset['id']]=$asset;
        return array_map(static function(array $item) use ($byId): array {
            $url='';$asset=$byId[$item['asset_id']]??null;
            if ($asset && is_array($item['frozen']??null)) {
                foreach (['uri','storage_scope','storage_engine','storage_domain'] as $field) {
                    if (($asset[$field]??'')!==($item['frozen'][$field]??'')) {$asset=null;break;}
                }
            }
            if ($asset && $item['type']==='image') {
                $candidate=preg_match('#^https?://#i',$asset['uri']) ? $asset['uri']
                    : FileService::getFileUrlByStorage($asset['uri'],$asset['storage_scope'],$asset['storage_engine'],$asset['storage_domain']);
                if (preg_match('#^(https?://|/(?!/))#i',$candidate)) $url=$candidate;
            }
            return ['key'=>$item['key'],'type'=>$item['type'],'name'=>$item['name'],'url'=>$url,'status'=>'ready'];
        },$items);
    }
}
