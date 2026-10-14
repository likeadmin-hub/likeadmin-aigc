<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

use app\common\enum\FileEnum;
use app\common\service\FileService;
use think\facade\Db;

/** Durable image identities. Delivery URLs are resolved only on a wire-read copy. */
final class DecorationAgentAssetReferences
{
    private const REFS = '_decoration_asset_refs';

    public static function attachGenerated(array $page,array $slot,array $asset): array
    {
        $tenant=(int)($slot['tenant_id']??0);
        $owned=self::slot((int)($slot['id']??0),$tenant);
        if(!$owned||(int)($slot['admin_id']??0)!==(int)$owned['slot']['admin_id'])throw new \InvalidArgumentException('装修图片资源归属无效');
        $selected=self::matchingAsset($owned['assets'],$asset);
        if(!$selected)throw new \InvalidArgumentException('装修图片资源未确认');
        $request=self::decode($owned['slot']['request_json']);$found=false;
        self::walk($page,function(array &$widget,array $definition)use($request,$selected,$slot,$asset,&$found): void {
            if((string)($widget['id']??'')!==(string)($request['widget_id']??''))return;
            $field=(string)($request['field']??'');
            if(!in_array($field,self::imageFields($widget,$definition),true))throw new \InvalidArgumentException('装修图片位置无效');
            $current=DecorationAgentOperations::get($widget,$field);
            if(!in_array($current,[$selected['stable'],$selected['uri'],(string)($asset['url']??'')],true))throw new \InvalidArgumentException('装修图片位置已改变');
            self::attach($widget,$field,'slot',(int)$slot['id'],$selected['stable']);$found=true;
        });
        if(!$found)throw new \InvalidArgumentException('装修图片组件不存在');
        return $page;
    }

    /** Only exact URLs supplied by trusted server search are converted, never arbitrary URIs. */
    public static function attachMaterials(array $page,array $trustedMaterials,int $tenant): array
    {
        if($tenant<=0)throw new \InvalidArgumentException('装修素材租户无效');
        $materials=[];
        foreach($trustedMaterials as $material){
            if(!is_array($material)||!is_string($material['url']??null)||$material['url']==='')continue;
            $kind='file';$id=(int)($material['file_id']??0);$asset=$id>0?self::file($id,$tenant):null;
            if(!$asset&&(int)($material['slot_id']??0)>0){
                $kind='slot';$id=(int)$material['slot_id'];$owned=self::slot($id,$tenant);
                $hint=(array)($material['asset']??$material);
                $asset=$owned?self::matchingAsset($owned['assets'],$hint):null;
            }
            if($asset)$materials[$material['url']]=['kind'=>$kind,'id'=>$id,'asset'=>$asset];
        }
        self::walk($page,function(array &$widget,array $definition)use($materials): void {
            foreach(self::imageFields($widget,$definition) as $field){
                $value=DecorationAgentOperations::get($widget,$field);
                if(!is_string($value)||!isset($materials[$value]))continue;
                $match=$materials[$value];self::attach($widget,$field,$match['kind'],$match['id'],$match['asset']['stable']);
            }
        });
        return $page;
    }

    /** No persisted data is modified; forged metadata never supplies a signing key or domain. */
    public static function resolveUrls(array $page,int $tenant): array
    {
        if($tenant<=0)return [];
        $urls=[];$identities=[];$conflicts=[];$cache=[];
        $request=request();$previous=$request->tenantId??null;$request->tenantId=$tenant;
        try {
            self::walk($page,function(array &$widget,array $definition)use($tenant,&$urls,&$identities,&$conflicts,&$cache): void {
                $fields=self::imageFields($widget,$definition);
                foreach((array)($widget[self::REFS]??[]) as $field=>$ref){
                    if(!is_array($ref)||!in_array($field,$fields,true))continue;
                    $expected=$ref['expected_value']??null;
                    if(!is_string($expected)||DecorationAgentOperations::get($widget,$field)!==$expected)continue;
                    $kind=$ref['kind']??'';$id=filter_var($ref['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                    if($id===false||!in_array($kind,['file','slot'],true))continue;
                    $key=$kind.':'.$id;
                    if(!array_key_exists($key,$cache))$cache[$key]=$kind==='file'?self::file($id,$tenant):self::slot($id,$tenant);
                    $record=$cache[$key];if(!$record)continue;
                    $assets=$kind==='file'?[$record]:$record['assets'];
                    foreach($assets as $asset){
                        if($asset['stable']!==$expected)continue;
                        $identity=json_encode([$asset['uri'],$asset['scope'],$asset['engine'],$asset['domain']]);
                        // A stable path cannot represent different objects from different buckets.
                        if(isset($identities[$expected])&&$identities[$expected]!==$identity){unset($urls[$expected]);$conflicts[$expected]=true;break;}
                        $identities[$expected]=$identity;if(isset($conflicts[$expected]))break;
                        try{$urls[$expected]=$asset['engine']==='local'?$asset['stable']:FileService::getFileUrlByStorage($asset['uri'],$asset['scope'],$asset['engine'],$asset['domain']);}catch(\Throwable $e){/* Private delivery fails closed. */}
                        break;
                    }
                }
            });
        } finally {$request->tenantId=$previous;}
        return $urls;
    }

    private static function attach(array &$widget,string $field,string $kind,int $id,string $stable): void
    {
        DecorationAgentOperations::set($widget,$field,$stable);
        if(!is_array($widget[self::REFS]??null))$widget[self::REFS]=[];
        $widget[self::REFS][$field]=['kind'=>$kind,'id'=>$id,'expected_value'=>$stable];
    }

    private static function file(int $id,int $tenant): ?array
    {
        $row=Db::name('tenant_file')->where(['id'=>$id,'tenant_id'=>$tenant,'source'=>FileEnum::SOURCE_ADMIN,'type'=>FileEnum::IMAGE_TYPE])->find();
        if(!$row||!empty($row['delete_time']))return null;
        return self::storageAsset(['image_uri'=>$row['uri'],'storage_scope'=>$row['storage_scope']??'','storage_engine'=>$row['storage_engine']??'','storage_domain'=>$row['storage_domain']??'']);
    }

    /** System decoration generations are shareable authoring assets; ordinary USER files are not. */
    /** Dedicated design reads require the same admin; they are never reusable DIY assets. */
    public static function designAsset(int $id,int $tenant,int $admin): ?array
    {
        $owned=self::slot($id,$tenant,true);
        if(!$owned||(int)$owned['slot']['admin_id']!==$admin||(self::decode($owned['slot']['request_json'])['purpose']??'')!=='design')return null;
        return $owned['assets'][0]['asset']??null;
    }

    /** Server-selected material identities reuse the existing file/settled-slot authority. */
    public static function materialAsset(array $material,int $tenant): ?array
    {
        if((int)($material['file_id']??0)>0)return self::file((int)$material['file_id'],$tenant)['asset']??null;
        $owned=self::slot((int)($material['slot_id']??0),$tenant);
        return $owned?self::matchingAsset($owned['assets'],(array)($material['asset']??$material))['asset']??null:null;
    }

    private static function slot(int $id,int $tenant,bool $allowDesign=false): ?array
    {
        $slot=Db::name('decorate_agent_image_slot')->where(['id'=>$id,'tenant_id'=>$tenant])->find();
        if(!$slot||(int)$slot['admin_id']<=0||!in_array($slot['status'],['success','regenerated'],true))return null;
        if(!$allowDesign&&(self::decode($slot['request_json'])['purpose']??'')==='design')return null;
        $result=self::decode($slot['result_json']);
        if(($result['status']??'')!=='success'||($result['billing_status']??'')!=='settled')return null;
        $consumption=Db::name('ai_consumption_log')->where(['id'=>(int)$slot['consumption_id'],'tenant_id'=>$tenant,'user_id'=>0,'app_code'=>'system_default','protocol'=>'image_generate','run_status'=>'success','billing_status'=>'settled'])->find();
        if(!$consumption)return null;
        $task=Db::name('ai_app_task')->where(['id'=>(int)$consumption['app_task_id'],'tenant_id'=>$tenant,'user_id'=>0,'app_code'=>'system_default','status'=>'success','business_table'=>'decorate_agent_image_slot','business_id'=>$id])->find();
        if(!$task)return null;
        $summary=self::decode($consumption['response_summary']);$assets=[];
        foreach((array)($result['images']??[]) as $image){
            if(!is_array($image))continue;$asset=self::storageAsset($image);if(!$asset)continue;
            foreach((array)($summary['images']??[]) as $confirmed){
                if(is_array($confirmed)&&self::matchingAsset([$asset],$confirmed)){$assets[]=$asset;break;}
            }
        }
        return $assets?['slot'=>$slot,'assets'=>$assets]:null;
    }

    private static function matchingAsset(array $assets,array $hint): ?array
    {
        $value=self::storageAsset($hint);if(!$value)return null;
        foreach($assets as $asset)if([$asset['uri'],$asset['scope'],$asset['engine'],$asset['domain']]===[$value['uri'],$value['scope'],$value['engine'],$value['domain']])return $asset;
        return null;
    }

    private static function storageAsset(array $image): ?array
    {
        $uri=$image['image_uri']??null;$scope=$image['storage_scope']??null;$engine=$image['storage_engine']??null;$domain=$image['storage_domain']??'';
        if(!is_string($uri)||!is_string($domain)||!in_array($scope,['tenant','platform'],true)||!in_array($engine,['local','qiniu','aliyun','qcloud'],true))return null;
        // Stored keys only. Provider URLs, signatures, traversal and alternate separators are not identities.
        if($uri===''||preg_match('~[\\\\\x00-\x20?#]|^[a-z][a-z0-9+.-]*:|^//~i',$uri))return null;
        $key=ltrim($uri,'/');
        if(!str_starts_with($key,'uploads/')||preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|%~',$key)||!preg_match('/\.(?:png|jpe?g|webp|gif)$/i',$key))return null;
        return ['uri'=>$key,'stable'=>'/'.$key,'scope'=>$scope,'engine'=>$engine,'domain'=>$domain,'asset'=>$image];
    }

    private static function decode(mixed $value): array
    {return is_array($value)?$value:(json_decode((string)$value,true)?:[]);}

    /** Resolve only schema-declared image leaves; text/icon/URL-looking values are never treated as images. */
    private static function imageFields(array $widget,array $definition): array
    {
        $fields=[];
        foreach(['content'=>'content_fields','styles'=>'style_fields'] as $section=>$contracts){
            foreach($definition[$contracts]??[] as $key=>$schema){
                if(($schema['type']??'')==='image'&&is_string($widget[$section][$key]??null))$fields[]=$section.'.'.$key;
            }
        }
        foreach($definition['lists']??[] as $key=>$list){
            foreach((array)($widget['content'][$key]??[]) as $index=>$row){
                if(!is_int($index)||!is_array($row))continue;
                foreach($list['fields']??[] as $field=>$schema)if(($schema['type']??'')==='image'&&is_string($row[$field]??null))$fields[]='content.'.$key.'.'.$index.'.'.$field;
            }
        }
        return $fields;
    }

    private static function walk(array &$page,\Closure $visit): void
    {
        $walk=function(array &$rows,int $depth)use(&$walk,$visit): void {
            if($depth>4)return;
            foreach($rows as &$widget){
                if(!is_array($widget))continue;
                try{$definition=($widget['name']??'')==='page-meta'?DecorationAgentCatalog::pageMeta():DecorationAgentCatalog::definition((string)($widget['name']??''));}catch(\InvalidArgumentException $e){continue;}
                $visit($widget,$definition);
                if(!empty($definition['children']['supported'])&&isset($widget['children'])&&is_array($widget['children']))$walk($widget['children'],$depth+1);
            }
            unset($widget);
        };
        foreach(['data','meta'] as $key)if(isset($page[$key])&&is_array($page[$key]))$walk($page[$key],1);
    }
}
