<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_canvas;

use app\common\enum\FileEnum;
use app\common\service\FileService;
use app\common\service\app\aigc_llm\agent\{AgentWorkspaceScope as Scope,AgentStore,AgentSubjectReferences};
use app\common\service\open_platform\{OpenAssets,OpenStore,OpenSafeHttp};
use think\facade\Db;

/** References are selected from an owned project, never from an account-wide library. */
final class CanvasProjectReferenceService
{
    private static function project(int $tenant,int $user): array
    {
        $id=(int)(Scope::current()['project_id']??0);
        if(Scope::app()!=='aigc_canvas'||$id<1)throw new \InvalidArgumentException('请先创建或打开画布项目');
        $row=Db::name('aigc_canvas_project')->where(['id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->find();
        if(!$row)throw new \InvalidArgumentException('项目不存在或不可访问');
        return $row;
    }
    public static function lists(int $tenant,int $user,array $params): array
    {
        if((int)(Scope::current()['project_id']??0)===0)return ['lists'=>[],'count'=>0];
        $project=self::project($tenant,$user);$rows=[];$scope=(string)($params['scope']??'project');
        if(!in_array($scope,['project','nodes','assets','subjects'],true))throw new \InvalidArgumentException('引用分类无效');
        if(in_array($scope,['project','nodes'],true))foreach(AgentStore::json($project['nodes_json']) as $node){
            if(!is_array($node)||empty($node['id']))continue;
            $meta=(array)($node['metadata']??$node['data']??[]);$text=self::text($node);
            $file=self::nodeFile($tenant,$user,$meta);
            if(!$file&&$text==='')$text=self::nodeContext($node);
            $rows[]=self::item('node',(string)$node['id'],self::nodeName($node),$text,$file);
        }
        if($scope!=='nodes')foreach(self::assets($tenant,$user,(int)$project['id'])->order('id','desc')->select()->toArray() as $asset){
            $isSubject=in_array($asset['asset_type'],['subject','subject_image','subject_three_view'],true);
            if(($scope==='subjects'&&!$isSubject)||($scope==='assets'&&$isSubject))continue;
            $file=self::ownedFile($tenant,$user,(string)$asset['uri']);if(!$file)continue;
            $meta=AgentStore::json($asset['meta_json']);
            $rows[]=self::item('asset',(string)$asset['id'],(string)($asset['title']?:'项目素材'),(string)($meta['description']??''),$file,$isSubject);
        }
        $keyword=mb_strtolower(mb_substr(trim((string)($params['keyword']??'')),0,100));
        if($keyword!=='')$rows=array_values(array_filter($rows,fn($row)=>mb_stripos($row['name'].' '.$row['description'],$keyword)!==false));
        $size=max(1,min(50,(int)($params['page_size']??30)));$page=max(1,(int)($params['page_no']??1));
        return ['lists'=>array_slice($rows,($page-1)*$size,$size),'count'=>count($rows)];
    }
    private static function assets(int $tenant,int $user,int $project)
    {
        return Db::name('aigc_canvas_asset')->where(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$project,'delete_time'=>0])->where('status','<>','deleted');
    }
    private static function item(string $kind,string $id,string $name,string $description,?array $file,bool $subject=false): array
    {
        $mime=$file['mime']??'text/plain';$url=$file?FileService::getFileUrlByStorage($file['uri'],$file['storage_scope'],$file['storage_engine'],$file['storage_domain']):'';
        return ['id'=>$kind.':'.$id,'reference'=>['kind'=>$kind,'id'=>$id],'name'=>mb_substr($name,0,120),
            'description'=>mb_substr($description,0,500),'image'=>str_starts_with($mime,'image/')?$url:'','mime'=>$mime,
            'category'=>$subject?'项目主体':($kind==='node'?'项目节点':'项目素材')];
    }
    private static function text(array $node): string
    {
        $meta=(array)($node['metadata']??$node['data']??[]);
        if(!in_array($node['type']??'',['text','textNode','note','noteNode'],true))return '';
        return mb_substr(trim((string)($meta['content']??$meta['text']??$meta['prompt']??'')),0,10000);
    }
    private static function nodeContext(array $node): string
    {
        $meta=(array)($node['metadata']??$node['data']??[]);
        // Empty nodes are useful Agent targets too; never turn an unverified URL
        // from their editable metadata into an attachment or model-readable link.
        $context=['node_id'=>(string)$node['id'],'type'=>(string)($node['type']??''),'title'=>self::nodeName($node)];
        foreach(['prompt','model','modelCode','ratio','aspectRatio','duration','resolution'] as $key)
            if(is_scalar($meta[$key]??null))$context[$key]=mb_substr((string)$meta[$key],0,2000);
        return '当前项目节点信息（暂无可引用的输出素材）：'.AgentStore::encode($context);
    }
    private static function nodeName(array $node): string
    {
        $meta=(array)($node['metadata']??$node['data']??[]);
        return trim((string)($meta['title']??$node['title']??$meta['name']??''))?:match($node['type']??''){'text','textNode'=>'文本节点','video','videoNode'=>'视频节点','audio','audioNode'=>'音频节点',default=>'图片节点'};
    }
    private static function nodeFile(int $tenant,int $user,array $meta): ?array
    {
        foreach(['assetUri','uri','image','image_url','video','video_url','audio','audio_uri','audio_url','url','file_url'] as $key)
            if(is_string($meta[$key]??null)&&$meta[$key]!==''&&($file=self::ownedFile($tenant,$user,$meta[$key])))return $file;
        return null;
    }
    /** Editable graph/asset metadata is not evidence of file ownership or storage. */
    private static function ownedFile(int $tenant,int $user,string $source): ?array
    {
        if($source===''||preg_match('#^(blob:|data:)#i',$source))return null;
        $remote=preg_match('#^https?://#i',$source)===1;
        $path=ltrim((string)($remote?parse_url($source,PHP_URL_PATH):$source),'/');
        if(!preg_match('#^(uploads|resource)/#',$path)||str_contains($path,'..')||str_contains($path,'\\')||str_contains($path,'%')||preg_match('/[\x00-\x20\x7f]/',$path))return null;
        $uris=array_unique([$source,$path,'/'.$path]);
        $row=Db::name('tenant_file')->where(['tenant_id'=>$tenant,'source_id'=>$user,'source'=>FileEnum::SOURCE_USER])->whereIn('uri',$uris)->whereRaw('(delete_time IS NULL OR delete_time=0)')->find();
        if(!$row){
            $row=OpenStore::owner('file',$tenant,$user)->whereIn('uri',$uris)->find();
            if($row&&!\app\common\service\WorkspaceAssetService::isFileVisible($tenant,$user,$row))$row=null;
        }
        // Historical generated nodes may predate registration in the upload library.
        if(!$row)foreach(['aigc_image_result'=>'image_uri','aigc_video_result'=>'video_uri','aigc_music_result'=>'audio_uri'] as $table=>$field){
            $candidate=Db::name($table)->where(['tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->whereIn($field,$uris)->find();
            if($candidate){$row=$candidate;$row['uri']=$candidate[$field];break;}
        }
        if(!$row)return null;
        $config=\app\common\service\storage\StorageConfigService::getStoredFileConfig($tenant,$row['storage_scope']??null,$row['storage_engine']??null);
        $row['storage_scope']=(string)(($row['storage_scope']??'')?:($config['scope']??'tenant'));
        $row['storage_engine']=(string)(($row['storage_engine']??'')?:($config['default']??'local'));
        $row['storage_domain']=(string)(($row['storage_domain']??'')?:\app\common\service\storage\StorageConfigService::getStorageDomain($config));
        if(!\app\common\service\WorkspaceAssetService::isFileVisible($tenant,$user,$row))return null;
        if($remote){
            $url=FileService::getFileUrlByStorage($row['uri'],$row['storage_scope'],$row['storage_engine'],$row['storage_domain']);
            if(strtolower((string)parse_url($source,PHP_URL_HOST))!==strtolower((string)parse_url($url,PHP_URL_HOST)))return null;
        }
        $row['mime']=(string)(($row['mime']??$row['mime_type']??'')?:self::mime($path));
        return $row;
    }
    private static function mime(string $uri): string
    {
        return match(strtolower(pathinfo($uri,PATHINFO_EXTENSION))){'jpg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','mp4','m4v'=>'video/mp4','mov'=>'video/quicktime','webm'=>'video/webm','mp3'=>'audio/mpeg','wav'=>'audio/wav','m4a'=>'audio/mp4','ogg'=>'audio/ogg','txt','md','csv'=>'text/plain','pdf'=>'application/pdf','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',default=>'application/octet-stream'};
    }
    public static function normalize(mixed $value): array
    {
        if(!is_array($value)||count($value)>8)throw new \InvalidArgumentException('项目引用格式不正确');
        $out=[];
        foreach($value as $ref){
            if(!is_array($ref)||array_diff(array_keys($ref),['kind','id'])||!in_array($ref['kind']??'', ['node','asset'],true)||!is_scalar($ref['id']??null)||!preg_match('/^[a-zA-Z0-9_-]{1,255}$/D',(string)$ref['id']))throw new \InvalidArgumentException('项目引用格式不正确');
            $key=$ref['kind'].':'.$ref['id'];$out[$key]=['kind'=>$ref['kind'],'id'=>(string)$ref['id']];
        }
        return array_values($out);
    }
    public static function resolve(int $tenant,int $user,array $reference): array
    {
        $reference=self::normalize([$reference])[0];$project=self::project($tenant,$user);$text='';$name='';$file=null;
        if($reference['kind']==='node'){
            foreach(AgentStore::json($project['nodes_json']) as $node)if((string)($node['id']??'')===$reference['id']){
                $name=self::nodeName($node);$text=self::text($node);$file=self::nodeFile($tenant,$user,(array)($node['metadata']??$node['data']??[]));if(!$file&&$text==='')$text=self::nodeContext($node);break;
            }
        }else{
            $row=self::assets($tenant,$user,(int)$project['id'])->where('id',$reference['id'])->find();
            if($row){$name=(string)$row['title'];$file=self::ownedFile($tenant,$user,(string)$row['uri']);$text=mb_substr((string)(AgentStore::json($row['meta_json'])['description']??''),0,2000);}
        }
        if(!$file&&$text!==''&&$reference['kind']==='node')$file=self::storeBytes($tenant,$user,(int)$project['id'],$text,'txt',FileEnum::FILE_TYPE)+['mime'=>'text/plain','size'=>strlen($text)];
        if(!$file)throw new \InvalidArgumentException('引用已删除、未保存或不属于当前项目，请重新选择');
        $id=OpenAssets::registerResult($tenant,$user,'aigc_llm',$file+['file_uri'=>$file['uri']]);
        if(!$id)throw new \InvalidArgumentException('项目素材暂不可引用');
        return OpenAssets::detail($tenant,$user,$id)+['project_reference'=>$reference,'reference_name'=>'@'.$name.(str_starts_with($file['mime'],'text/')?'.txt':''),'reference_description'=>mb_substr($text,0,2000)];
    }
    public static function sourceSubjects(int $tenant,int $user,array $params): array
    {
        self::project($tenant,$user);
        return AgentSubjectReferences::lists($tenant,$user,$params);
    }
    public static function importSubject(int $tenant,int $user,array $params): array
    {
        $project=self::project($tenant,$user);$request=(string)($params['request_id']??'');$id=filter_var($params['subject_id']??null,FILTER_VALIDATE_INT);
        if(!$id||$id<1||!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D',$request))throw new \InvalidArgumentException('主体或导入请求标识无效');
        return Db::transaction(function()use($tenant,$user,$project,$request,$id){
            $live=Db::name('aigc_canvas_project')->where(['id'=>$project['id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
            if(!$live)throw new \InvalidArgumentException('项目已删除');
            $existing=self::assets($tenant,$user,(int)$project['id'])->where('task_id','subject-import:'.$request)->find();
            if($existing){
                if((int)(AgentStore::json($existing['meta_json'])['source_subject_id']??0)!==$id)throw new \InvalidArgumentException('导入请求标识已用于其他主体');
                $file=self::ownedFile($tenant,$user,$existing['uri']);if(!$file)throw new \InvalidArgumentException('快照已删除，请重新导入');
                return self::item('asset',(string)$existing['id'],$existing['title'],(string)(AgentStore::json($existing['meta_json'])['description']??''),$file,true);
            }
            $source=AgentSubjectReferences::source($tenant,$user,$id);$uri=(string)$source['image_uri'];
            $url=FileService::getFileUrlByStorage($uri,$source['storage_scope'],$source['storage_engine'],$source['storage_domain']);
            $bytes=self::sourceBytes($uri,$source['storage_engine'],$url);
            $image=@getimagesizefromstring($bytes);$ext=match($image['mime']??''){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif',default=>throw new \InvalidArgumentException('主体图片内容无效或不受支持')};
            $stored=self::storeBytes($tenant,$user,(int)$project['id'],$bytes,$ext,FileEnum::IMAGE_TYPE);
            $asset=CanvasAssetService::registerAsset($tenant,$user,$stored+['project_id'=>$project['id'],'task_id'=>'subject-import:'.$request,'asset_type'=>'subject','title'=>$source['name'],'mime_type'=>$image['mime'],'file_size'=>strlen($bytes),'width'=>$image[0],'height'=>$image[1],
                'meta'=>['snapshot_version'=>1,'source_subject_id'=>$id,'description'=>mb_substr($source['description'],0,2000),'imported_at'=>time()]]);
            return self::item('asset',(string)$asset['id'],$source['name'],$source['description'],$stored+['mime'=>$image['mime']],true);
        });
    }
    private static function sourceBytes(string $uri,string $engine,string $url): string
    {
        if($engine==='local'&&!preg_match('#^https?://#i',$uri)){
            $root=realpath(public_path());$path=realpath(public_path().ltrim($uri,'/'));
            if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path)||filesize($path)>20971520)throw new \InvalidArgumentException('主体图片不可读取');
            $bytes=file_get_contents($path);if($bytes===false)throw new \InvalidArgumentException('主体图片读取失败');return $bytes;
        }
        return OpenSafeHttp::get($url,20971520,25)['body'];
    }
    private static function storeBytes(int $tenant,int $user,int $project,string $bytes,string $extension,int $type): array
    {
        $tmp=tempnam(sys_get_temp_dir(),'canvas-ref-');if($tmp===false)throw new \InvalidArgumentException('快照暂存失败');
        $path=$tmp.'.'.$extension;
        try{
            if(!rename($tmp,$path)||file_put_contents($path,$bytes)!==strlen($bytes))throw new \InvalidArgumentException('快照暂存失败');
            return AigcCanvasService::storeProjectReferenceFile($path,$tenant,$user,$project,$type);
        }finally{if(is_file($tmp))unlink($tmp);if(is_file($path))unlink($path);}
    }
}
