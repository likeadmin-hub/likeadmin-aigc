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

    /** Manual source-graphic claims are identifiers, never caller-authored storage authority. */
    public static function normalizeSourceGraphics(array $page,array $before,int $tenant): array
    {
        if($tenant<=0)throw new \InvalidArgumentException('装修素材租户无效');
        $prefix='content.source_graphics.';$previous=[];$duplicates=[];
        self::walk($before,function(array &$widget,array $definition)use(&$previous,&$duplicates,$prefix):void{
            $id=$widget['id']??null;if(($widget['name']??'')!=='app-collection'||!is_string($id)||$id==='')return;
            // Ambiguous widget identities must not transfer an old field's proof.
            if(isset($previous[$id])){$duplicates[$id]=true;return;}$previous[$id]=[];
            foreach((array)($widget['content']['source_graphics']??[]) as $index=>$row){
                if(!is_int($index)||!is_array($row)||!is_string($row['image']??null))continue;
                $ref=$widget[self::REFS][$prefix.$index.'.image']??null;
                if(!is_array($ref)||($ref['expected_value']??null)!==$row['image'])continue;
                $key=json_encode([$row['app_code']??null,$row['entry_key']??null,$row['image']]);
                if(array_key_exists($key,$previous[$id])){$previous[$id][$key]=null;continue;}$previous[$id][$key]=$ref;
            }
        });
        foreach($duplicates as $id=>$_)unset($previous[$id]);
        $blocked=null;$builtins=null;$cache=[];$identities=[];
        self::walk($page,function(array &$widget,array $definition)use($tenant,$prefix,$previous,&$blocked,&$builtins,&$cache,&$identities):void{
            if(($widget['name']??'')!=='app-collection'||!array_key_exists('source_graphics',(array)($widget['content']??[])))return;
            DecorationAgentOperations::validateSourceGraphics($widget);
            if($blocked===null){
                $blocked=array_map(fn($uri)=>DecorationAgentDesignService::imagePath((string)$uri),DecorationAgentDesignService::blockedUris($tenant));
                $builtins=array_column(DecorationAgentMediaService::builtinMaterials(),'url');
            }
            $refs=is_array($widget[self::REFS]??null)?$widget[self::REFS]:[];$kept=$refs;
            foreach($kept as $field=>$_)if(is_string($field)&&str_starts_with($field,$prefix))unset($kept[$field]);
            foreach($widget['content']['source_graphics'] as $index=>$row){
                $value=$row['image']??'';
                if(!is_string($value))throw new \InvalidArgumentException('应用入口图片地址无效');
                if($value==='')continue;
                if(in_array(DecorationAgentDesignService::imagePath($value),$blocked,true))throw new \InvalidArgumentException('设计稿和验收截图不能作为应用入口图片');
                $field=$prefix.$index.'.image';$ref=$refs[$field]??null;
                if(!is_array($ref)||($ref['expected_value']??null)!==$value){
                    $key=json_encode([$row['app_code'],$row['entry_key'],$value]);
                    $ref=$previous[(string)($widget['id']??'')][$key]??null;
                }
                if($ref===null&&in_array($value,$builtins,true))continue;
                $kind=is_array($ref)?($ref['kind']??null):null;$rawId=is_array($ref)?($ref['id']??null):null;
                $id=(is_int($rawId)||(is_string($rawId)&&preg_match('/^[1-9][0-9]*$/D',$rawId)))?filter_var($rawId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;
                if($id===false||!in_array($kind,['file','slot'],true)||($ref['expected_value']??null)!==$value)throw new \InvalidArgumentException('应用入口图片缺少有效素材身份');
                $key=$kind.':'.$id;if(!array_key_exists($key,$cache))$cache[$key]=$kind==='file'?self::file($id,$tenant):self::slot($id,$tenant);
                $record=$cache[$key];$match=null;
                foreach($record?($kind==='file'?[$record]:$record['assets']):[] as $asset)if($asset['stable']===$value){$match=$asset;break;}
                if(!$match)throw new \InvalidArgumentException('应用入口图片已失效或无权使用');
                $identity=json_encode([$match['uri'],$match['scope'],$match['engine'],$match['domain']]);
                if(isset($identities[$value])&&$identities[$value]!==$identity)throw new \InvalidArgumentException('应用入口图片存储身份冲突');
                $identities[$value]=$identity;
                // Rebuild the small reserved claim; client domain/engine/URI fields never survive.
                $kept[$field]=['kind'=>$kind,'id'=>$id,'expected_value'=>$match['stable']];
                $widget['content']['source_graphics'][$index]['image']=$match['stable'];
            }
            if($kept)$widget[self::REFS]=$kept;else unset($widget[self::REFS]);
        });
        return $page;
    }

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

    /** Capture only declared current image refs; return authoritative storage identity, never client URLs. */
    public static function captureIdentities(array $page,int $tenant): array
    {
        if($tenant<=0)throw new \InvalidArgumentException('截图素材租户无效');
        $result=[];$identities=[];$cache=[];
        self::walk($page,function(array &$widget,array $definition)use($tenant,&$result,&$identities,&$cache): void {
            $fields=self::imageFields($widget,$definition);
            foreach((array)($widget[self::REFS]??[]) as $field=>$ref){
                if(!is_array($ref)||!in_array($field,$fields,true))continue;
                $expected=$ref['expected_value']??null;
                if(!is_string($expected)||DecorationAgentOperations::get($widget,$field)!==$expected)continue;
                $kind=$ref['kind']??'';$id=filter_var($ref['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if($id===false||!in_array($kind,['file','slot'],true))throw new \InvalidArgumentException('截图素材身份无效');
                $key=$kind.':'.$id;
                if(!array_key_exists($key,$cache))$cache[$key]=$kind==='file'?self::file($id,$tenant):self::slot($id,$tenant);
                $record=$cache[$key];$match=null;
                foreach($record?($kind==='file'?[$record]:$record['assets']):[] as $asset)if($asset['stable']===$expected){$match=$asset;break;}
                if(!$match)throw new \InvalidArgumentException('截图素材未确认、来源无权读取或图片已变化');
                $identity=json_encode([$match['uri'],$match['scope'],$match['engine'],$match['domain']]);
                if(isset($identities[$expected])&&$identities[$expected]!==$identity)throw new \InvalidArgumentException('截图素材存在存储身份冲突');
                $identities[$expected]=$identity;$result[$expected]=$match['asset'];
            }
        });
        return $result;
    }

    /** Freeze only identities already present in a server-owned page snapshot. */
    public static function freezeMaterials(array $page,int $tenant,array $sources,array $attachments=[],array $historical=[]): array
    {
        $manifest=[];
        self::walk($page,function(array &$widget,array $definition)use($tenant,&$manifest):void{
            foreach(self::imageFields($widget,$definition) as $field){
                $ref=$widget[self::REFS][$field]??null;$value=DecorationAgentOperations::get($widget,$field);
                if(!is_array($ref)||!is_string($value)||($ref['expected_value']??null)!==$value)continue;
                $kind=$ref['kind']??'';$id=(int)($ref['id']??0);if(!in_array($kind,['file','slot'],true)||$id<=0)continue;
                // Keep revoked identities as deny entries; never downgrade a managed image to a plain URL.
                $key=$kind.':'.$id.':'.$value;$manifest[$key]=['kind'=>$kind,'id'=>$id,'stable_uri'=>$value,'asset'=>[],'aliases'=>[$value]];
                $record=$kind==='file'?self::file($id,$tenant):($kind==='slot'?self::slot($id,$tenant):null);
                foreach($record?($kind==='file'?[$record]:$record['assets']):[] as $asset)if($asset['stable']===$value){
                    $key=$kind.':'.$id.':'.$value;$manifest[$key]=['kind'=>$kind,'id'=>$id,'stable_uri'=>$value,'asset'=>array_intersect_key($asset['asset'],array_flip(['image_uri','storage_scope','storage_engine','storage_domain'])),'aliases'=>[$value]];break;
                }
            }
        });
        foreach(self::refreshMaterials(array_values($manifest),$tenant,$sources,$attachments,$historical) as $entry)$manifest[$entry['kind'].':'.$entry['id'].':'.$entry['stable_uri']]=$entry;
        return array_values($manifest);
    }

    /** Revocations, source policy and stored identity are rechecked on every reuse. */
    public static function refreshMaterials(array $manifest,int $tenant,array $sources,array $attachments=[],array $historical=[]): array
    {
        $result=[];$identities=[];$blocked=array_map(fn($uri)=>'/'.ltrim($uri,'/'),DecorationAgentDesignService::blockedUris($tenant));
        $uploads=[];if(in_array('upload',$sources,true))foreach($attachments as $attachment)if(in_array('asset',$attachment['uses']??[],true))$uploads[]=(int)$attachment['file_id'];
        foreach($manifest as $entry){
            $kind=$entry['kind']??'';$id=(int)($entry['id']??0);$source=$kind==='file'&&in_array($id,$uploads,true)?'upload':'tenant';
            if(!in_array($source,$sources,true)||!in_array($kind,['file','slot'],true))continue;
            $record=$kind==='file'?self::file($id,$tenant):self::slot($id,$tenant);$match=null;
            foreach($record?($kind==='file'?[$record]:$record['assets']):[] as $asset)if($asset['stable']===($entry['stable_uri']??null)&&self::matchingAsset([$asset],$entry['asset']??[])){$match=$asset;break;}
            if(!$match||in_array($match['stable'],$blocked,true))continue;
            $identity=json_encode([$match['uri'],$match['scope'],$match['engine'],$match['domain']]);
            if(isset($identities[$match['stable']])&&$identities[$match['stable']]!==$identity)throw new \InvalidArgumentException('复用素材存储身份冲突');
            $identities[$match['stable']]=$identity;$url=DecorationAgentDesignService::assetUrl($tenant,$match['asset']);$aliases=[$match['stable'],$url];
            foreach(array_merge($entry['aliases']??[],array_column($historical,'url')) as $alias){
                if(!is_string($alias))continue;
                // Historical aliases must be server-authored metadata for the same object/origin.
                $a=parse_url($alias);$b=parse_url($url);
                if($alias===$match['stable']||(is_array($a)&&is_array($b)&&array_intersect_key($a,array_flip(['scheme','host','port','path']))===array_intersect_key($b,array_flip(['scheme','host','port','path']))))$aliases[]=$alias;
            }
            unset($entry['visual_geometry']);if(is_array($match['asset']['visual_geometry']??null))$entry['visual_geometry']=$match['asset']['visual_geometry'];
            $entry=array_replace($entry,['source'=>$source,'url'=>$url,'aliases'=>array_values(array_unique($aliases)),'file_id'=>$kind==='file'?$id:0,'slot_id'=>$kind==='slot'?$id:0,'asset'=>array_intersect_key($match['asset'],array_flip(['image_uri','storage_scope','storage_engine','storage_domain'])),'title'=>'当前基准页面已使用的素材','role'=>'owned_page_asset']);
            $result[]=$entry;
        }
        return $result;
    }

    public static function managedValue(string $value,array $manifest): bool
    {
        foreach($manifest as $entry)if(in_array($value,$entry['aliases']??[],true)||DecorationAgentDesignService::imagePath($value)===DecorationAgentDesignService::imagePath((string)($entry['stable_uri']??'')))return true;
        return false;
    }

    public static function assertManagedReuse(array $page,array $manifest,array $valid,array $before=[]): void
    {
        $aliases=[];foreach($valid as $entry)$aliases=array_merge($aliases,$entry['aliases']);
        $unchanged=[];
        self::walk($before,function(array &$widget,array $definition)use(&$unchanged):void{
            foreach(self::imageFields($widget,$definition) as $field)$unchanged[(string)($widget['id']??'page-meta').':'.$field]=[DecorationAgentOperations::get($widget,$field),$widget[self::REFS][$field]??null];
        });
        self::walk($page,function(array &$widget,array $definition)use($manifest,$aliases,$unchanged):void{
            foreach(self::imageFields($widget,$definition) as $field){
                $value=DecorationAgentOperations::get($widget,$field);
                if(!is_string($value)||!self::managedValue($value,$manifest)||in_array($value,$aliases,true))continue;
                // An unchanged revoked image may remain, but cannot be copied or rebound elsewhere.
                $old=$unchanged[(string)($widget['id']??'page-meta').':'.$field]??null;
                if($old!==null&&$old===[$value,$widget[self::REFS][$field]??null]&&$old[1]!==null)continue;
                throw new \InvalidArgumentException('复用素材来源已失效或地址不是本轮授权别名');
            }
        });
    }

    /** A newly reused value must remain managed even if authority changed during attachment. */
    public static function assertManagedBindings(array $page,array $manifest,array $valid,array $before=[]): void
    {
        $unchanged=[];
        self::walk($before,function(array &$widget,array $definition)use(&$unchanged):void{
            foreach(self::imageFields($widget,$definition) as $field)$unchanged[(string)($widget['id']??'page-meta').':'.$field]=[DecorationAgentOperations::get($widget,$field),$widget[self::REFS][$field]??null];
        });
        self::walk($page,function(array &$widget,array $definition)use($manifest,$valid,$unchanged):void{
            foreach(self::imageFields($widget,$definition) as $field){
                $value=DecorationAgentOperations::get($widget,$field);$ref=$widget[self::REFS][$field]??null;
                if(!is_string($value)||!self::managedValue($value,$manifest))continue;
                if(($unchanged[(string)($widget['id']??'page-meta').':'.$field]??null)===[$value,$ref]&&$ref!==null)continue;
                foreach($valid as $entry)if($value===$entry['stable_uri']&&$ref===['kind'=>$entry['kind'],'id'=>$entry['id'],'expected_value'=>$value])continue 2;
                throw new \InvalidArgumentException('复用素材未形成当前授权的持久引用');
            }
        });
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
        return self::storageAsset(['image_uri'=>$row['uri'],'storage_scope'=>$row['storage_scope']??'','storage_engine'=>$row['storage_engine']??'','storage_domain'=>$row['storage_domain']??''],$tenant);
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

    private static function storageAsset(array $image,int $portableTenant=0): ?array
    {
        $uri=$image['image_uri']??null;$scope=$image['storage_scope']??null;$engine=$image['storage_engine']??null;$domain=$image['storage_domain']??'';
        if(!is_string($uri)||!is_string($domain)||!in_array($scope,['tenant','platform'],true)||!in_array($engine,['local','qiniu','aliyun','qcloud'],true))return null;
        // Stored keys only. Provider URLs, signatures, traversal and alternate separators are not identities.
        if($uri===''||preg_match('~[\\\\\x00-\x20?#]|^[a-z][a-z0-9+.-]*:|^//~i',$uri))return null;
        $key=ltrim($uri,'/');
        // Only file() can supply a verified tenant image row. These exact keys are
        // created after DecorationPortableMedia validates/sanitizes imported bytes.
        $portable=$portableTenant>0&&preg_match('~^uploads/decoration/'.$portableTenant.'/[a-f0-9]{24}/[a-f0-9]{64}\.(?:svg|avif|ico)$~D',$key);
        if(!str_starts_with($key,'uploads/')||preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|%~',$key)||(!preg_match('/\.(?:png|jpe?g|webp|gif)$/i',$key)&&!$portable))return null;
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
            if($depth>5)return;
            foreach($rows as &$widget){
                if(!is_array($widget))continue;
                // Manual template saves permit one deeper level than the original
                // Agent asset walk. Extend only this new list, never legacy assets.
                if($depth===5&&($widget['name']??'')!=='app-collection')continue;
                try{$definition=($widget['name']??'')==='page-meta'?DecorationAgentCatalog::pageMeta():DecorationAgentCatalog::definition((string)($widget['name']??''));}catch(\InvalidArgumentException $e){continue;}
                if($depth===5){
                    $definition['content_fields']=array_intersect_key($definition['content_fields']??[],['source_graphics'=>true]);
                    $definition['style_fields']=[];
                    $definition['lists']=array_intersect_key($definition['lists']??[],['source_graphics'=>true]);
                    $definition['children']=['supported'=>false];
                }
                $visit($widget,$definition);
                if(!empty($definition['children']['supported'])&&isset($widget['children'])&&is_array($widget['children']))$walk($widget['children'],$depth+1);
            }
            unset($widget);
        };
        foreach(['data','meta'] as $key)if(isset($page[$key])&&is_array($page[$key]))$walk($page[$key],1);
    }
}
