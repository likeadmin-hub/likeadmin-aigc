<?php
namespace app\common\service\app\aigc_micro_video;
use think\facade\Db;
use InvalidArgumentException;

/** Immutable business versions. Legacy directions remain their own execution authority. */
final class MicroVideoWorkbenchRecords
{
    public static function table(string $suffix='record'){return MicroVideoStore::table('workbench_'.$suffix);}
    public static function query(int $tenant,int $user,string $kind,bool $admin=false,bool $write=false)
    {
        MicroVideoWorkbenchPolicy::kind($kind);$q=self::table()->where(['tenant_id'=>$tenant,'kind'=>$kind,'delete_time'=>0]);
        if(!$admin){if($write||$kind==='project')$q->where('user_id',$user);else $q->where(function($x)use($user){$x->where('user_id',$user)->whereOr(function($y){$y->where(['visibility'=>'tenant','status'=>1]);});});}
        return $q;
    }
    public static function owned(int $tenant,int $user,string $kind,int $id,bool $admin=false,bool $write=false,bool $lock=false): array
    {
        $q=self::query($tenant,$user,$kind,$admin,$write)->where('id',$id);if($lock)$q->lock(true);$r=$q->find();if(!$r)throw new InvalidArgumentException('资料不存在或无权访问');return $r;
    }
    public static function dto(array $r): array
    {return ['id'=>(int)$r['id'],'kind'=>$r['kind'],'title'=>$r['title'],'user_id'=>(int)$r['user_id'],'revision'=>(int)$r['revision'],'status'=>(int)$r['status'],'visibility'=>$r['visibility'],'body'=>self::publicBody(self::generatedProjectBody($r)),'create_time'=>(int)$r['create_time'],'update_time'=>(int)$r['update_time']];}
    /** Read-only legacy repair requires an owned generated result and an exactly unchanged script. */
    private static function generatedProjectBody(array $record): array
    {
        $body=MicroVideoWorkbenchPolicy::normalizeValues(MicroVideoStore::json($record['body_json']));
        if($record['kind']!=='project'||empty($body['last_script_job_id'])||!is_array($body['script']??null))return $body;
        $job=MicroVideoWorkbenchJobs::table()->where(['id'=>(int)$body['last_script_job_id'],'tenant_id'=>$record['tenant_id'],'user_id'=>$record['user_id'],'project_id'=>$record['id'],'action'=>'rewrite','status'=>'success'])->find();
        if(!$job)return $body;
        $original=MicroVideoWorkbenchPolicy::normalizeValues(MicroVideoStore::json($job['result_json']));
        if(MicroVideoWorkbenchPolicy::hash($body['script'])!==MicroVideoWorkbenchPolicy::hash($original['script']??[]))return $body;
        $body['script']=MicroVideoWorkbenchJobs::result($job,(array)($body['images']??[]))['script'];
        return $body;
    }
    /** Public images preserve usable storage references but never expose private receipt/owner identities. */
    public static function publicAsset(array $image): array
    {return array_intersect_key($image,array_flip(['id','uri','url','name','role','storage_scope','storage_engine','storage_domain']));}
    public static function publicBody(array $body,array $privateContext=[]): array
    {return self::redactBody(MicroVideoWorkbenchPolicy::normalizeValues($body),self::privatePrompts([$body,$privateContext]));}
    private static function redactBody(array $body,array $secrets): array
    {
        unset($body['internal_prompt']);
        foreach($body as $key=>$value){
            if($key==='images'&&is_array($value))$body[$key]=self::redactBody(array_map([self::class,'publicAsset'],$value),$secrets);
            elseif(is_array($value))$body[$key]=self::redactBody($value,$secrets);
            elseif(is_string($value)&&$secrets)$body[$key]=str_replace($secrets,'[内部创作规则]',$value);
        }return $body;
    }
    /** Exact private paragraphs and meaningful sentences, excluding text already public in this template. */
    private static function privatePrompts(array $body): array
    {
        $prompts=[];$source=$body['internal_prompt']??null;
        if(is_string($source)&&trim($source)!==''){
            $prompts[]=$source;
            $public=array_intersect_key($body,array_flip(['title','description','script','segments','reference_rules','direction_rules','beats','roles','variables','style']));$publicStrings=[];
            array_walk_recursive($public,static function($value)use(&$publicStrings){if(is_string($value))$publicStrings[]=$value;});$publicText=implode("\n",$publicStrings);
            foreach(preg_split('/\R/u',$source,-1,PREG_SPLIT_NO_EMPTY) as $line){
                // Headings are private metadata; also match a copied paragraph without its heading.
                $plain=preg_replace('/^\h*(?:模板创作指导|镜头指导|负面约束|叙事时间骨架)\h*[：:]\h*/u','',trim($line));
                $parts=array_merge([$line,$plain],preg_split('/[。！？!?；;]+/u',$plain,-1,PREG_SPLIT_NO_EMPTY));
                foreach($parts as $part){$part=trim($part);if(mb_strlen($part)>=12&&!str_contains($publicText,$part))$prompts[]=$part;}
            }
        }
        foreach($body as $key=>$value)if($key!=='internal_prompt'&&is_array($value))$prompts=array_merge($prompts,self::privatePrompts($value));
        $prompts=array_values(array_unique($prompts));usort($prompts,static fn($a,$b)=>strlen($b)<=>strlen($a));return $prompts;
    }
    /** Treat reserved fields as server data even when an administrator echoes a public DTO. */
    private static function withoutInternalFields(array $body): array
    {
        unset($body['internal_prompt'],$body['protected_prompt']);
        foreach($body as $key=>$value)if(is_array($value))$body[$key]=self::withoutInternalFields($value);
        return $body;
    }
    /** A provider may use hidden rules, but must never publish private source paragraphs or sentences. */
    public static function assertNoPrivateEcho(array $result,array $context): void
    {
        $secrets=self::privatePrompts($context);
        array_walk_recursive($result,static function($value)use($secrets){if(is_string($value))foreach($secrets as $prompt)if(str_contains($value,$prompt))throw new InvalidArgumentException('模型结果包含内部创作规则，请核对保留结果');});
    }
    public static function lists(int $tenant,int $user,array $p,bool $admin=false): array
    {
        $kind=MicroVideoWorkbenchPolicy::kind((string)($p['kind']??'project'));$q=self::query($tenant,$user,$kind,$admin);
        if(trim((string)($p['keyword']??''))!=='')$q->whereLike('title','%'.mb_substr((string)$p['keyword'],0,100).'%');if(isset($p['status'])&&$p['status']!=='')$q->where('status',(int)$p['status']);
        if(!empty($p['category'])){$category=mb_substr((string)$p['category'],0,80);$q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(body_json, '$.category')) = ?",[$category]);}
        if($kind==='project'&&!empty($p['stage'])){if(!in_array($p['stage'],['draft','script_ready','assets_ready','ready'],true))throw new InvalidArgumentException('项目阶段无效');$q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(body_json, '$.stage')) = ?",[(string)$p['stage']]);}
        if($admin&&!empty($p['user_id']))$q->where('user_id',(int)$p['user_id']);
        $page=max(1,(int)($p['page_no']??1));$size=max(1,min(100,(int)($p['page_size']??20)));$count=(clone $q)->count();$rows=$q->order('update_time','desc')->page($page,$size)->select()->toArray();
        return ['lists'=>array_map([self::class,'dto'],$rows),'count'=>(int)$count,'page_no'=>$page,'page_size'=>$size];
    }
    public static function save(int $tenant,int $user,array $p,bool $admin=false): array
    {return self::saveRecord($tenant,$user,$p,$admin);}
    public static function confirmScript(int $tenant,int $user,array $p): array
    {
        $r=self::owned($tenant,$user,'project',(int)($p['project_id']??0),false,true);
        MicroVideoWorkbenchPolicy::revision($r,(int)($p['project_revision']??0));
        $body=MicroVideoStore::json($r['body_json']);
        if(trim((string)($body['script']['text']??''))===''||empty($body['script']['segments']))throw new InvalidArgumentException('请先填写剧本和分镜段落');
        return self::saveRecord($tenant,$user,['kind'=>'project','id'=>$r['id'],'revision'=>$r['revision'],'title'=>$r['title'],'body'=>$body,'confirm_script'=>true]);
    }
    /** Hold partial private-rule prefixes until they can be removed in full. */
    public static function streamProse(string $text,array $context): string
    {
        foreach(self::privatePrompts($context) as $secret){
            $text=str_replace($secret,'',$text);
            for($n=min(mb_strlen($text),mb_strlen($secret)-1);$n>0;$n--){
                if(mb_substr($text,-$n)===mb_substr($secret,0,$n)){$text=mb_substr($text,0,mb_strlen($text)-$n);break;}
            }
        }
        return $text;
    }
    /** Server catalog loader only. No controller accepts the trusted body or this entry point. */
    public static function saveTrustedTemplate(int $tenant,array $p): array
    {
        $body=$p['body']??null;
        if(($p['kind']??'template')!=='template'||!is_array($body)||!is_string($body['internal_prompt']??null)||trim($body['internal_prompt'])===''||($body['protected_prompt']??null)!==true)throw new InvalidArgumentException('内置模板规则格式无效');
        $p['kind']='template';return self::saveRecord($tenant,0,$p,true,$body);
    }
    /** Historical snapshots enter only from an owned immutable version, never from public request data. */
    private static function saveRecord(int $tenant,int $user,array $p,bool $admin=false,?array $restoreBody=null): array
    {
        if($restoreBody!==null)$restoreBody=MicroVideoWorkbenchPolicy::normalizeValues($restoreBody);
        $kind=MicroVideoWorkbenchPolicy::kind((string)($p['kind']??'project'));$id=(int)($p['id']??0);$title=trim((string)($p['title']??''));if($title===''||mb_strlen($title)>120)throw new InvalidArgumentException('请填写120字以内的名称');
        if(!is_array($p['body']??null))throw new InvalidArgumentException('创作资料格式无效');
        $initial=$id?self::owned($tenant,$user,$kind,$id,$admin,true):null;$owner=$initial?(int)$initial['user_id']:$user;if($initial)MicroVideoWorkbenchPolicy::revision($initial,(int)($p['revision']??0));
        $preparedBody=self::prepareBody($tenant,$owner,$kind,$p['body'],$initial,$restoreBody);
        return Db::transaction(function()use($tenant,$user,$p,$admin,$kind,$id,$title,$preparedBody,$restoreBody){
            $old=$id?self::owned($tenant,$user,$kind,$id,$admin,true,true):null;if($old)MicroVideoWorkbenchPolicy::revision($old,(int)($p['revision']??0));
            $owner=$old?(int)$old['user_id']:$user;if($kind==='project'&&$owner<=0)throw new InvalidArgumentException('项目必须属于用户，请使用用户创作入口');
            $body=$preparedBody;if($kind==='project')$body=MicroVideoWorkbenchPolicy::dependencies($body,$old?MicroVideoStore::json($old['body_json']):[]);
            if($kind==='project'&&!empty($p['confirm_script'])&&$old){
                $prior=MicroVideoStore::json($old['body_json']);
                foreach(['script','product_snapshot','template_snapshot'] as $field)if(MicroVideoWorkbenchPolicy::hash($body[$field]??[])!==MicroVideoWorkbenchPolicy::hash($prior[$field]??[]))throw new InvalidArgumentException('资料已变化，请保存后重新核对剧本');
                $body['script_stale']=false;$body['stage']='script_ready';
            }
            if($kind==='project'&&$restoreBody!==null){
                // A whole historical body is internally coherent; keep its script/product agreement while requiring asset review.
                $body['script_stale']=(bool)($restoreBody['script_stale']??false);
                $body['stage']=$body['script_stale']?'draft':(trim((string)($body['script']['text']??''))!==''?'script_ready':'draft');
                foreach($body['scenes']??[] as $index=>$scene){$body['scenes'][$index]['confirmed']=false;$body['scenes'][$index]['status']='stale';}
            }
            $revision=$old?(int)$old['revision']+1:1;$visibility=$kind==='project'?'private':(string)($p['visibility']??($old['visibility']??($admin?'tenant':'private')));
            if(!in_array($visibility,['private','tenant'],true)||(!$admin&&$visibility!=='private'))throw new InvalidArgumentException('共享资料仅租户管理员可发布');
            $data=['title'=>$title,'revision'=>$revision,'visibility'=>$visibility,'status'=>(int)(bool)($p['status']??($old['status']??1)),'body_json'=>MicroVideoStore::encode($body),'update_time'=>time()];
            if($old)self::table()->where('id',$id)->update($data);else $id=self::table()->insertGetId($data+['tenant_id'=>$tenant,'user_id'=>$owner,'kind'=>$kind,'create_time'=>time(),'delete_time'=>0]);
            self::table('version')->insert(['tenant_id'=>$tenant,'user_id'=>$owner,'record_id'=>$id,'revision'=>$revision,'title'=>$title,'body_json'=>$data['body_json'],'create_time'=>time()]);
            return self::dto(self::owned($tenant,$user,$kind,$id,$admin));
        });
    }
    private static function prepareBody(int $tenant,int $owner,string $kind,array $body,?array $initial=null,?array $restoreBody=null): array
    {
        // Public callers can edit prose, never introduce private instructions or trust markers.
        $body=self::withoutInternalFields($body);
        if($kind==='template'){
            $trusted=$restoreBody??($initial?MicroVideoStore::json($initial['body_json']):[]);
            if(($trusted['protected_prompt']??false)===true&&is_string($trusted['internal_prompt']??null)&&trim($trusted['internal_prompt'])!==''){$body['internal_prompt']=$trusted['internal_prompt'];$body['protected_prompt']=true;}
        }
        // Snapshots are authoritative. Strip stale or malicious client copies before image validation.
        if($kind==='project'){unset($body['template_snapshot'],$body['product_snapshot']);if(!empty($body['scenes']))unset($body['scene_reset']);}
        $body=MicroVideoWorkbenchPolicy::body($kind,$body);$body=self::images($tenant,$owner,$body);
        foreach(['cover_image','example_video'] as $field)if(isset($body[$field]))$body[$field]=MicroVideoWorkbenchAssets::decorative($tenant,$owner,(string)$body[$field],$field==='example_video');
        if($kind==='project')foreach(['template','product'] as $ref){if(empty($body[$ref]['id']))continue;$prior=MicroVideoWorkbenchPolicy::normalizeValues($restoreBody??($initial?MicroVideoStore::json($initial['body_json']):[]));if(isset($prior[$ref.'_snapshot'])&&MicroVideoWorkbenchPolicy::hash($body[$ref])===MicroVideoWorkbenchPolicy::hash($prior[$ref]??[])){$body[$ref.'_snapshot']=self::images($tenant,$owner,$prior[$ref.'_snapshot']);continue;}$selected=self::owned($tenant,$owner,$ref,(int)$body[$ref]['id']);$revision=(int)($body[$ref]['revision']??$selected['revision']);$v=self::versionRow($selected,$revision);$snap=MicroVideoStore::json($v['body_json']);if((int)$selected['user_id']!==$owner)$snap=MicroVideoWorkbenchAssets::cloneBody($tenant,$owner,(int)$selected['id'],$snap,(int)$selected['user_id']);$body[$ref]=['id'=>(int)$selected['id'],'revision'=>$revision];$body[$ref.'_snapshot']=['title'=>$v['title'],'body'=>$snap];}
        return MicroVideoWorkbenchPolicy::normalizeValues($body);
    }
    /** Recursively validate any images field, including nested scenes and roles. Browser metadata never grants access. */
    public static function images(int $tenant,int $user,array $body): array
    {
        foreach($body as $k=>$v){if($k==='images'){$body[$k]=$v?($user>0?MicroVideoMedia::validateImages($tenant,$user,(array)$v,15):MicroVideoWorkbenchAssets::catalogImages($tenant,(array)$v)):[];}elseif(is_array($v))$body[$k]=self::images($tenant,$user,$v);}
        return $body;
    }
    public static function versionRow(array $record,int $revision): array
    {$r=self::table('version')->where(['record_id'=>$record['id'],'tenant_id'=>$record['tenant_id'],'user_id'=>$record['user_id'],'revision'=>$revision])->find();if(!$r)throw new InvalidArgumentException('资料版本不存在');return $r;}
    public static function versions(int $tenant,int $user,array $p,bool $admin=false): array
    {$r=self::owned($tenant,$user,(string)$p['kind'],(int)$p['id'],$admin);$rows=self::table('version')->where(['record_id'=>$r['id'],'tenant_id'=>$tenant])->order('revision','desc')->select()->toArray();return ['lists'=>array_map(fn($v)=>['revision'=>(int)$v['revision'],'title'=>$v['title'],'body'=>self::publicBody(MicroVideoStore::json($v['body_json'])),'create_time'=>(int)$v['create_time']],$rows)];}
    public static function restore(int $tenant,int $user,array $p,bool $admin=false): array
    {$r=self::owned($tenant,$user,(string)$p['kind'],(int)$p['id'],$admin,true);$v=self::versionRow($r,(int)$p['version']);$historical=MicroVideoStore::json($v['body_json']);return self::saveRecord($tenant,$user,['kind'=>$r['kind'],'id'=>$r['id'],'revision'=>(int)$p['revision'],'title'=>$v['title'],'body'=>$historical,'visibility'=>$r['visibility'],'status'=>$r['status']],$admin,$historical);}
    public static function copy(int $tenant,int $user,array $p,bool $admin=false): array
    {$r=self::owned($tenant,$user,(string)$p['kind'],(int)$p['id'],$admin);$body=MicroVideoStore::json($r['body_json']);if(!$admin&&(int)$r['user_id']!==$user)$body=MicroVideoWorkbenchAssets::cloneBody($tenant,$user,(int)$r['id'],$body,(int)$r['user_id']);unset($body['task_id']);return self::saveRecord($tenant,$user,['kind'=>$r['kind'],'title'=>mb_substr((string)($p['title']??$r['title'].' 副本'),0,120),'body'=>$body,'visibility'=>$admin?'tenant':'private'],$admin,$body);}
    public static function delete(int $tenant,int $user,array $p,bool $admin=false): array
    {return Db::transaction(function()use($tenant,$user,$p,$admin){$r=self::owned($tenant,$user,(string)$p['kind'],(int)$p['id'],$admin,true,true);MicroVideoWorkbenchPolicy::revision($r,(int)($p['revision']??0));self::table()->where('id',$r['id'])->update(['delete_time'=>time(),'update_time'=>time()]);return ['id'=>(int)$r['id'],'deleted'=>true];});}
    public static function categories(): array
    {return ['lists'=>array_merge(array_map(fn($p)=>['code'=>$p[0],'name'=>$p[1]],[['story','自由故事'],['campus','校园'],['office','职场'],['palace','宫廷'],['kungfu','功夫'],['twist','反转'],['commerce','商品展示'],['property','房产']]),MicroVideoCommerceTemplates::catalog()['categories'])];}
}
