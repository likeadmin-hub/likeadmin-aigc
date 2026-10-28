<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\service\open_platform\{OpenAssets,OpenStore};
use app\common\service\storage\{Driver,StorageConfigService};
use app\common\service\FileService;
use app\common\service\WorkspaceAssetService;
use app\common\service\ai\AiTaskRecordService;
use think\facade\Db;
use app\common\model\file\TenantFile;
use app\common\enum\FileEnum;

final class AgentArtifactService
{
    public static function lists(int $tenant,int $user,int $session,int $before=0,bool $all=false,string $kind=''): array
    {
        if(!$all && $session<1)return [];
        $query=AgentWorkspaceScope::artifacts(AgentStore::owner('artifact',$tenant,$user),$tenant,$user);
        if(!$all)$query->where('session_id',$session);
        if($before>0)$query->where('id','<',$before);
        if($kind!=='' && $kind!=='all'){
            if(!in_array($kind,['image','video','audio','document'],true))throw new \InvalidArgumentException('作品类型无效');
            $query->where('kind',$kind);
        }
        $output=[];$cursor=$before;
        do {
            $batch=clone $query;
            if($cursor>0)$batch->where('id','<',$cursor);
            $rows=$batch->order('id','desc')->limit(100)->select()->toArray();
            foreach($rows as $row){
                $data=AgentStore::json($row['data']);
                $ref=self::libraryReference($row,true);
                if($ref && !WorkspaceAssetService::isVisible($tenant,$user,$ref))continue;
                try{$asset=OpenAssets::detail($tenant,$user,$data['file_id']);}
                catch(\app\common\service\open_platform\OpenException $e){if($e->errorType==='not_found')continue;throw $e;}
                if($ref){
                    $state=Db::name('user_asset_state')->where(['tenant_id'=>$tenant,'user_id'=>$user,'asset_ref'=>$ref])->find();
                    if(!$state && (int)$row['favorite']===1)$state=WorkspaceAssetService::update($tenant,$user,['refs'=>[$ref],'favorite'=>1])['states'][0];
                    $row['favorite']=(int)($state['favorite']??0);
                }
                $output[]=array_intersect_key($row,array_flip(['id','run_id','step_id','title','kind','favorite','create_time']))+$asset+['content'=>$data['content']??'','generation'=>self::generationMetadata($row)];
                if(count($output)===100)return $output;
            }
            $cursor=$rows?(int)end($rows)['id']:0;
        } while(count($rows)===100);
        return $output;
    }
    /** Read the submitted native task, never the proposal, artifact title or current preferences. */
    public static function canvasGeneration(array $artifact): ?array
    {
        if (!in_array($artifact['kind'] ?? '', ['image','video','audio'], true)) return null;
        return self::generationMetadata($artifact, true);
    }
    private static function generationMetadata(array $artifact, bool $canvas = false): ?array
    {
        $tenant=(int)$artifact['tenant_id'];$user=(int)$artifact['user_id'];
        $step=AgentStore::owner('step',$tenant,$user)->where(['id'=>$artifact['step_id'],'run_id'=>$artifact['run_id']])->find();
        if($step&&$step['tool_code']===AgentSpeechTool::CODE){
            if ($canvas) return null; // Speech must never be replayed as music.
            $task=\app\common\model\ai\AiAppTask::where(['id'=>$step['task_id'],'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_llm','action_code'=>'agent_speech'])->find();
            return $task?array_intersect_key((array)$task['request_summary'],array_flip(['prompt','model_name','characters','format'])):null;
        }
        if(!$step||!in_array($step['tool_code'],['aigc_image','aigc_video','aigc_music','aigc_fitting'],true)||!(int)$step['task_id'])return null;
        $task=Db::name($step['tool_code'].'_task')->where(['id'=>$step['task_id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->find();
        if(!$task)return null;
        $canvasTools = ['video'=>['aigc_video'], 'image'=>['aigc_image','aigc_fitting'], 'audio'=>['aigc_music']];
        if ($canvas && !in_array($step['tool_code'], $canvasTools[$artifact['kind'] ?? ''] ?? [], true)) return null;
        if($step['tool_code']==='aigc_fitting'){
            $reference=self::libraryReference($artifact);
            if(!$reference)return null;
            $taskId=Db::name('aigc_image_result')->where(['id'=>(int)substr($reference,13),'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->value('task_id');
            $task=Db::name('aigc_image_task')->where(['id'=>$taskId,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->find();
            if(!$task)return null;
        }
        // Whitelist public creation settings; model snapshots can contain provider credentials.
        $meta=array_intersect_key($task,array_flip(['prompt','negative_prompt','quality','ratio','duration','quantity','style','lyrics','genre','mood','instruments','language','create_time']));
        $meta['model_name']=AiTaskRecordService::modelDisplayName($task,$step['tool_code']);
        if ($canvas && $step['tool_code'] === 'aigc_music') {
            $submitted = AgentStore::json($task['provider_payload'] ?? [])['generation'] ?? [];
            $meta += array_intersect_key((array)$submitted, array_flip(['custom','instrumental','vocal_gender','language','style']));
            $meta['title'] = (string)($task['title'] ?? '');
            // Old tasks did not persist mode flags. Lyrics still prove custom mode.
            if (!array_key_exists('custom', $meta) && !empty($task['lyrics'])) $meta['custom'] = true;
            $meta['style'] ??= (string)($task['genre'] ?? '');
        }
        $snapshot=AgentStore::json($task['model_json']??[]);
        if ($canvas) {
            $provider = AgentStore::json($task['provider_params_json'] ?? []);
            $meta += array_intersect_key($task, array_flip(['channel','model','market_product_id','market_sku_id']));
            $meta['task_id'] = (int)$task['id'];
            $meta['tool_code'] = $step['tool_code'] === 'aigc_fitting' ? 'aigc_image' : $step['tool_code'];
            $meta['model_id'] = (string)($task['channel'] ?? $snapshot['model_id'] ?? '');
            $meta['market_product_id'] = (int)($task['market_product_id'] ?? $snapshot['market_product_id'] ?? 0);
            $meta['market_sku_id'] = (int)($task['market_sku_id'] ?? $snapshot['market_sku_id'] ?? $provider['market_sku_id'] ?? 0);
            if ((!$meta['market_product_id'] || !$meta['market_sku_id']) && !empty($task['app_task_id'])) {
                $consumption = Db::name('ai_consumption_log')->where(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$task['app_task_id']])->order('id','asc')->find();
                if ($consumption) {
                    $price = AgentStore::json($consumption['price_snapshot'] ?? []);
                    $meta['market_product_id'] = $meta['market_product_id'] ?: (int)($consumption['product_id'] ?? 0);
                    $meta['market_sku_id'] = $meta['market_sku_id'] ?: (int)($price['sku_id'] ?? 0);
                }
            }
            if (!$meta['market_product_id'] && preg_match('/^market_(?:image_model|video_model|video_app|nano_banana):(\d+)(?::|$)/', $meta['model_id'], $match)) $meta['market_product_id'] = (int)$match[1];
            $meta['generation_method'] = (string)($snapshot['generation_method'] ?? $task['generation_method'] ?? '');
            $meta['mode'] = (string)($snapshot['mode'] ?? '');
        }
        $meta['effect_name']=(string)($snapshot['effect_snapshot']['name']??'');
        $meta['options']=array_intersect_key((array)($snapshot['q4_options']??[]),array_flip(['audio','is_rec','watermark','seed']));
        if ($canvas) $meta['options'] = array_intersect_key(array_merge($provider, (array)($snapshot['q4_options'] ?? [])), array_flip(['audio','generate_audio','is_rec','watermark','seed','wm_position','camera_fixed','prompt_extend','negative_prompt']));
        $meta['references']=[];
        $references=AgentStore::json($task['reference_assets']??[]);
        foreach(AgentStore::json($task['reference_images']??[]) as $url)if(is_string($url) && !array_filter($references, static fn($item) => is_array($item) && ($item['type'] ?? 'image') === 'image' && in_array($url, [$item['url'] ?? '', $item['uri'] ?? ''], true)))$references[]=['url'=>$url,'type'=>'image'];
        foreach($references as $reference){
            if(!is_array($reference))continue;
            $url=trim((string)($reference['url']??$reference['uri']??''));
            if($url==='')continue;
            if(!preg_match('~^https?://~i',$url)){
                if(preg_match('~^[a-z][a-z0-9+.-]*:~i',$url))continue;
                $url=FileService::getFileUrl($url);
            }
            $type=$reference['type']??'image';
            $role = $canvas ? (string)($reference['role'] ?? '') : '';
            // A URL may intentionally occupy both first/last frame slots.
            if (array_filter($meta['references'], static fn($item) => $item['url'] === $url && ($item['role'] ?? '') === $role)) continue;
            if (in_array($type,['image','video','audio'],true)) {
                $item=['url'=>$url,'type'=>$type];
                if ($canvas) $item += array_intersect_key($reference, array_flip(['name','role','generation_method','duration','start','end']));
                $meta['references'][]=$item;
            }
        }
        return $meta;
    }
    public static function favorite(int $tenant,int $user,int $id,bool $favorite): void
    {
        $row=AgentStore::owner('artifact',$tenant,$user)->where('id',$id)->find();
        if(!$row)throw new \InvalidArgumentException('作品不存在');
        Db::transaction(function()use($tenant,$user,$id,$favorite,$row){
            $ref=self::libraryReference($row,true);
            if(!$ref)throw new \InvalidArgumentException('作品缺少可验证的资产关联');
            if(!WorkspaceAssetService::isVisible($tenant,$user,$ref))throw new \InvalidArgumentException('作品已删除');
            if($ref)WorkspaceAssetService::update($tenant,$user,['refs'=>[$ref],'favorite'=>(int)$favorite]);
            AgentStore::owner('artifact',$tenant,$user)->where('id',$id)->update(['favorite'=>(int)$favorite]);
        });
    }
    /** Upgrade persisted Agent favorites without recreating any outputs or reviving cancellations. */
    public static function syncLibraryFavorites(int $tenant, int $user): void
    {
        $rows=AgentStore::owner('artifact',$tenant,$user)->where('favorite',1)->select()->toArray();
        foreach($rows as $row){
            $ref=self::libraryReference($row,true);
            if(!$ref || Db::name('user_asset_state')->where(['tenant_id'=>$tenant,'user_id'=>$user,'asset_ref'=>$ref])->count())continue;
            if(WorkspaceAssetService::isVisible($tenant,$user,$ref))WorkspaceAssetService::update($tenant,$user,['refs'=>[$ref],'favorite'=>1]);
        }
    }
    /** Resolve the original owned generation record, including artifacts saved before library linking. */
    private static function libraryReference(array $artifact,bool $includeDeleted=false): ?string
    {
        if(!(int)$artifact['step_id'])return 'agent:result:'.$artifact['id'];
        $tenant=(int)$artifact['tenant_id'];$user=(int)$artifact['user_id'];
        $step=AgentStore::owner('step',$tenant,$user)->where(['id'=>$artifact['step_id'],'run_id'=>$artifact['run_id']])->find();
        $source=$step?WorkspaceAssetService::sourceForApp($step['tool_code']):null;
        $sources=['aigc_fitting'=>['image','image_uri']];
        $uriField=['image'=>'image_uri','video'=>'video_uri','audio'=>'audio_uri'][$artifact['kind']]??null;
        if($source && $uriField && $source!=='short_drama')$sources[$step['tool_code']]=[$source,$uriField];
        if($step && $step['tool_code']==='aigc_fitting')$sources['aigc_fitting']=['image','image_uri'];
        if($step && $step['tool_code']===AgentSpeechTool::CODE)return 'agent:result:'.$artifact['id'];
        if(!$step||!isset($sources[$step['tool_code']])||!(int)$step['task_id'])return null;
        $data=AgentStore::json($artifact['data']);
        $file=OpenStore::owner('file',$tenant,$user)->where('file_id',$data['file_id']??'')->find();
        if(!$file)return null;
        [$source,$uriField]=$sources[$step['tool_code']];
        $scope=['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$step['task_id'],$uriField=>$file['uri']];
        foreach(['storage_scope','storage_engine','storage_domain'] as $field)$scope[$field]=$file[$field]??'';
        if(!$includeDeleted)$scope['delete_time']=0;
        $taskScope=['tenant_id'=>$tenant,'user_id'=>$user,'id'=>$step['task_id']];if(!$includeDeleted)$taskScope['delete_time']=0;
        $task=Db::name($step['tool_code'].'_task')->where($taskScope)->find();
        if(!$task)return null;
        if($step['tool_code']==='aigc_fitting'){
            unset($scope['task_id']);
            $ids=array_filter(array_map('intval',array_merge(AgentStore::json($task['image_task_ids']??[]),[$task['image_task_id']??0])));
            if(!$ids)return null;
            $id=Db::name('aigc_image_result')->where($scope)->whereIn('task_id',$ids)->value('id');
        }else $id=Db::name($step['tool_code'].'_result')->where($scope)->value('id');
        return $id ? $source.':result:'.$id : null;
    }
    private static function save(array $run,int $step,string $title,string $kind,string $file,array $storage,array $extra=[]): int
    {
        $identity=hash('sha256',$run['session_id'].':'.$file);
        $existing=AgentStore::owner('artifact',(int)$run['tenant_id'],(int)$run['user_id'])->where('identity_hash',$identity)->find();
        if($existing){self::writeCanvas($run,(int)$existing['id'],$file,$kind,$title);return (int)$existing['id'];}
        $id=(int)AgentStore::table('artifact')->insertGetId(['tenant_id'=>$run['tenant_id'],'user_id'=>$run['user_id'],'session_id'=>$run['session_id'],'run_id'=>$run['id'],'step_id'=>$step,'identity_hash'=>$identity,'title'=>mb_substr($title,0,200),'kind'=>$kind,'data'=>AgentStore::encode(['file_id'=>$file]+$extra),'storage_scope'=>$storage['storage_scope'],'storage_engine'=>$storage['storage_engine'],'storage_domain'=>$storage['storage_domain']??'','favorite'=>0,'create_time'=>time()]);
        AgentStore::event($run,'artifact.created',['file_id'=>$file,'kind'=>$kind]);
        AgentContextService::created($run,$file);
        self::writeCanvas($run,$id,$file,$kind,$title);
        return $id;
    }
    private static function writeCanvas(array $run,int $id,string $file,string $kind,string $title): void
    {
        try { \app\common\service\app\aigc_canvas\CanvasConversationTools::artifact($run,$id,$file,$kind,$title); }
        catch(\InvalidArgumentException $e) { AgentStore::event($run,'canvas.write_skipped',['artifact_id'=>$id,'message'=>$e->getMessage()]); }
    }
    public static function collect(array $run,array $step,array $result): array
    {
        $ids=[];
        foreach((array)($result['results']??[]) as $row){
            if(!is_array($row))continue;
            $file=OpenAssets::registerResult((int)$run['tenant_id'],(int)$run['user_id'],$step['tool_code']===AgentSpeechTool::CODE?'aigc_llm':$step['tool_code'],$row);
            if(!$file)continue;
            $kind=!empty($row['video_uri'])?'video':(!empty($row['audio_uri'])?'audio':(!empty($row['image_uri'])?'image':'document'));
            $ids[]=self::save($run,(int)$step['id'],$step['title'],$kind,$file,$row);
        }
        return array_values(array_unique($ids));
    }
    /** Step-to-output links are separate from the session gallery's file deduplication. */
    public static function forSteps(array $run,array $steps): array
    {
        $ids=[];foreach($steps as $step)foreach(AgentStore::json($step['result'])['_agent_artifact_ids']??[] as $id)$ids[]=(int)$id;
        $query=AgentStore::owner('artifact',(int)$run['tenant_id'],(int)$run['user_id'])->where('session_id',$run['session_id']);
        $rows=$query->where(function($q)use($ids,$run){$q->where('run_id',$run['id']);if($ids)$q->whereOr('id','in',$ids);})->select()->toArray();
        $out=[];
        foreach($steps as $step){
            $refs=array_map('intval',AgentStore::json($step['result'])['_agent_artifact_ids']??[]);
            foreach($rows as $row)if(in_array((int)$row['id'],$refs,true)||((int)$row['run_id']===(int)$run['id']&&(int)$row['step_id']===(int)$step['id']))
                $out[]=array_replace($row,['step_id'=>(int)$step['id']]);
        }
        return $out;
    }
    /** Minimal OOXML, with escaped plain paragraphs and no executable or external content. */
    public static function writeDocx(string $path,string $title,string $content): void
    {
        $escape=static fn($s)=>htmlspecialchars($s,ENT_XML1|ENT_QUOTES,'UTF-8');
        $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
        foreach(array_merge([$title],preg_split('/\r\n|\r|\n/',$content)) as $line)$xml.='<w:p><w:r><w:t xml:space="preserve">'.$escape($line).'</w:t></w:r></w:p>';
        $xml.='<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body></w:document>';
        $zip=new \ZipArchive();if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('无法创建文档');
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml',$xml);if(!$zip->close())throw new \RuntimeException('文档写入失败');
    }
    public static function document(array $run,string $title,string $content): array
    {
        if(trim($content)===''||mb_strlen($content)>100000)throw new \InvalidArgumentException('文档正文须为1至100000字');
        $current=AgentStore::run((int)$run['tenant_id'],(int)$run['user_id'],(int)$run['id']);
        if($current['lease_token']!==$run['lease_token']||(int)$current['cancel_requested'])throw new \InvalidArgumentException('执行已停止');
        $digest=hash('sha256',$title."\n".$content);
        foreach(AgentStore::table('artifact')->where('run_id',$run['id'])->select()->toArray() as $row)if((AgentStore::json($row['data'])['digest']??'')===$digest)return ['created'=>true,'title'=>$row['title']];
        $temp=tempnam(sys_get_temp_dir(),'agent_doc_');if($temp===false)throw new \RuntimeException('无法创建临时文件');$path=$temp.'.docx';
        try{
            self::writeDocx($path,$title,$content);
            $config=StorageConfigService::getEffectiveConfig((int)$run['tenant_id']);$driver=new Driver($config);$driver->setUploadFileByReal($path);
            $dir='uploads/aigc_llm/documents/'.$run['tenant_id'].'/'.date('Ymd');
            if(!$driver->upload($dir))throw new \RuntimeException('文档保存失败');
            $uri=$dir.'/'.str_replace('\\','/',$driver->getFileName());$storage=['storage_scope'=>$config['scope']??'tenant','storage_engine'=>$config['default']??'local','storage_domain'=>StorageConfigService::getStorageDomain($config)];
            TenantFile::create(['tenant_id'=>$run['tenant_id'],'cid'=>0,'type'=>FileEnum::FILE_TYPE,'name'=>mb_substr($title,0,100).'.docx','uri'=>$uri,'source'=>FileEnum::SOURCE_USER,'source_id'=>$run['user_id'],'create_time'=>time()]+$storage);
            $file=OpenAssets::registerResult((int)$run['tenant_id'],(int)$run['user_id'],'aigc_llm',['file_uri'=>$uri,'mime'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','size'=>filesize($path)]+$storage);
            if(!$file)throw new \RuntimeException('文档归属保存失败');
            self::save($run,0,$title,'document',$file,$storage,['content'=>$content,'digest'=>$digest]);
            return ['created'=>true,'title'=>$title,'file_id'=>$file];
        }finally{@unlink($temp);@unlink($path);}
    }
}
