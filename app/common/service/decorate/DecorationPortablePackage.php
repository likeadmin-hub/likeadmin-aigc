<?php
declare(strict_types=1);
namespace app\common\service\decorate;

use app\common\model\decorate\{DecoratePage,DecorateTemplate};
use think\facade\Db;
use RuntimeException;
use ZipArchive;

/** Package parsing and creation are separate from media transport and document traversal. */
final class DecorationPortablePackage
{
    public static function import(int $tenant,string $base64,array $defaults,callable $validate): array
    {
        $package=self::read($base64);$payload=$package['payload'];
        if(!is_array($payload['template']??null)||!is_array($payload['pages']??null)||count($payload['pages'])>256)throw new RuntimeException('模板包数据不完整或页面过多');
        $identities=[];
        foreach($payload['pages'] as $i=>&$page){
            if(!is_array($page)||!in_array($page['terminal']??'',['mobile','pc'],true))throw new RuntimeException('模板包终端无效');
            $page['page_code']=trim(strtolower(preg_replace('/[^a-zA-Z0-9_]/','_',(string)($page['page_code']??''))),'_');
            if($page['page_code']==='')throw new RuntimeException('模板包页面标识无效');
            $key=self::identity($page);if(isset($identities[$key]))throw new RuntimeException('模板包包含重复页面标识');$identities[$key]=true;
            foreach(['data','draft_data','published_data'] as $field){$meta=str_replace('data','meta',$field);$validate((string)($page[$field]??$page['data']??'[]'),(string)($page[$meta]??$page['meta']??''));}
        }unset($page);
        $assets=new DecorationPortableAssets($tenant,$package['embedded']);
        try{
            return Db::transaction(function()use($tenant,$payload,$defaults,$assets){
                $payload=$assets->localize($payload);
                $settings=(array)($payload['template']['settings']??$defaults);
                $draft=self::settings($payload['template']['draft_settings']??null,$settings,$defaults);
                $published=self::settings($payload['template']['published_settings']??null,$settings,$defaults);
                $template=DecorateTemplate::create(['tenant_id'=>$tenant,'name'=>mb_substr(trim((string)($payload['template']['name']??'导入模板'))?:'导入模板',0,100),'cover'=>(string)($payload['template']['cover']??''),'status'=>1,'is_active'=>0,'publish_status'=>'draft','draft_settings'=>$draft,'published_settings'=>$published]);
                $byPackage=[];$byIdentity=[];
                foreach($payload['pages'] as $position=>$page){
                    $data=['tenant_id'=>$tenant,'template_id'=>(int)$template['id'],'terminal'=>$page['terminal'],'channel'=>(string)($page['channel']??'common'),'page_code'=>$page['page_code'],'page_type'=>(string)($page['page_type']??'custom'),'route_path'=>(string)($page['route_path']??''),'is_home'=>(int)($page['is_home']??0),'is_system'=>(int)($page['is_system']??0),'status'=>(int)($page['status']??1),'sort'=>(int)($page['sort']??0),'type'=>(int)($page['type']??0),'name'=>(string)($page['name']??'装修页面'),'draft_deleted'=>(int)($page['draft_deleted']??0)===1?1:0];
                    foreach(['data','meta','draft_data','draft_meta','published_data','published_meta'] as $field){$fallback=str_contains($field,'meta')?'meta':'data';$data[$field]=(string)($page[$field]??$page[$fallback]??($fallback==='meta'?'':'[]'));}
                    $created=DecoratePage::create($data);$id=(int)$created['id'];
                    $package=(int)($page['package_page_id']??($position+1));if(isset($byPackage[$package]))throw new RuntimeException('模板包页面关联重复');
                    $byPackage[$package]=$id;$byIdentity[self::identity($page)]=$id;
                }
                if(isset($payload['template']['published_pages'])){
                    if(!is_array($payload['template']['published_pages']))throw new RuntimeException('模板发布结构无效');
                    $structure=[];
                    foreach($payload['template']['published_pages'] as $row){
                        if(!is_array($row))throw new RuntimeException('模板发布页面关联无效');
                        $id=$byPackage[(int)($row['package_page_id']??0)]??null;
                        if(!$id)throw new RuntimeException('模板发布页面关联缺失');
                        unset($row['package_page_id'],$row['tenant_id'],$row['template_id']);$row['id']=$id;$structure[]=$row;
                    }
                    $template->save(['published_pages'=>self::json($structure)]);
                }
                return $template->toArray()+['asset_import'=>$assets->summary()];
            });
        }catch(\Throwable $error){
            try{$assets->rollback();}catch(\Throwable $cleanup){throw new RuntimeException($cleanup->getMessage(),0,$error);}
            throw $error instanceof RuntimeException?$error:new RuntimeException('模板导入失败，已回滚本次新素材与模板',0,$error);
        }
    }

    public static function read(string $base64): array
    {
        if(!class_exists(ZipArchive::class))throw new RuntimeException('请开启 PHP zip 扩展后再导入模板');
        $base64=preg_replace('/^data:.*?;base64,/','',$base64);
        if($base64===''||strlen($base64)>28*1024*1024)throw new RuntimeException('模板包无效或超过20MB');
        $raw=base64_decode($base64,true);if($raw===false||strlen($raw)>20*1024*1024)throw new RuntimeException('模板包无效或超过20MB');
        $temp=tempnam(sys_get_temp_dir(),'decorate_package_');if($temp===false)throw new RuntimeException('模板包暂存失败');$zip=new ZipArchive();$opened=false;
        try{
            if(file_put_contents($temp,$raw)!==strlen($raw)||$zip->open($temp)!==true)throw new RuntimeException('模板包读取失败');$opened=true;
            if($zip->numFiles>512)throw new RuntimeException('模板包文件数量过多');
            $size=0;$names=[];
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);$name=(string)($stat['name']??'');$size+=(int)($stat['size']??0);
                if($name===''||str_starts_with($name,'/')||preg_match('~[\\\\\x00-\x20]|(^|/)\.\.(/|$)|^[a-zA-Z]:~',$name)||isset($names[$name])||(!in_array($name,['manifest.json','template.json'],true)&&!str_starts_with($name,'assets/')))throw new RuntimeException('模板包包含非法或重复文件');
                if($size>20*1024*1024)throw new RuntimeException('模板包解压内容超过20MB');$names[$name]=true;
            }
            if(!isset($names['manifest.json'],$names['template.json']))throw new RuntimeException('模板包内容不完整');
            $manifest=json_decode((string)$zip->getFromName('manifest.json'),true,64,JSON_THROW_ON_ERROR);
            if(($manifest['package_type']??'')!=='likeadmin.decorate.template'||($manifest['schema_version']??'')!=='1.0')throw new RuntimeException('模板包版本不支持');
            $embedded=[];
            if(isset($names['assets/resources.json'])){
                $resources=json_decode((string)$zip->getFromName('assets/resources.json'),true,64,JSON_THROW_ON_ERROR);
                if(!is_array($resources)||($resources['version']??null)!==1||!is_array($resources['items']??null)||count($resources['items'])>DecorationPortableAssets::MAX_FILES)throw new RuntimeException('素材清单无效或素材数量超限');
                foreach($resources['items'] as $item){
                    if(!is_array($item)||!is_string($item['url']??null))throw new RuntimeException('素材清单条目无效');
                    if(!isset($item['entry']))continue;
                    $entry=$item['entry'];$hash=$item['sha256']??'';
                    if(!is_string($entry)||!preg_match('~^assets/([a-f0-9]{64})\.[a-z0-9]{2,5}$~D',$entry,$match)||$hash!==$match[1]||!isset($names[$entry]))throw new RuntimeException('包内私有素材路径或校验值无效');
                    $body=$zip->getFromName($entry);if(!is_string($body)||strlen($body)>DecorationPortableAssets::MAX_FILE_BYTES||!hash_equals($hash,hash('sha256',$body))||($item['size']??null)!==strlen($body))throw new RuntimeException('包内私有素材内容校验失败');
                    [$clean,$mime,$ext]=DecorationPortableMedia::validate($body);
                    if(($item['mime']??null)!==$mime||pathinfo($entry,PATHINFO_EXTENSION)!==$ext)throw new RuntimeException('包内私有素材类型不匹配');
                    if(isset($embedded[$item['url']])&&$embedded[$item['url']]!==$clean)throw new RuntimeException('包内素材地址冲突');
                    $embedded[$item['url']]=$clean;
                }
            }
            $payload=json_decode((string)$zip->getFromName('template.json'),true,64,JSON_THROW_ON_ERROR);
            if(!is_array($payload))throw new RuntimeException('模板包数据无效');return ['payload'=>$payload,'embedded'=>$embedded];
        }catch(\JsonException $error){throw new RuntimeException('模板包 JSON 无效',0,$error);}
        finally{if($opened)$zip->close();@unlink($temp);}
    }

    private static function identity(array $page): string{return ($page['terminal']??'').'|'.($page['channel']??'common').'|'.($page['page_code']??'');}
    private static function settings(mixed $raw,array $legacy,array $defaults): string
    {
        if(is_string($raw)&&$raw!==''){
            try{$decoded=json_decode($raw,false,64,JSON_THROW_ON_ERROR);}catch(\Throwable $error){throw new RuntimeException('模板设置 JSON 无效',0,$error);}
            if(!is_object($decoded)&&!is_array($decoded))throw new RuntimeException('模板设置格式无效');return self::json($decoded);
        }
        return self::json(array_replace_recursive($defaults,$legacy));
    }
    private static function json(mixed $value): string{return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
}
