<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\service\open_platform\{OpenAssets,OpenStore};
use app\common\service\storage\{Driver,StorageConfigService};
use app\common\service\FileService;
use app\common\service\WorkspaceAssetService;
use think\facade\Db;
use app\common\model\file\TenantFile;
use app\common\enum\FileEnum;

final class AgentArtifactService
{
    public static function lists(int $tenant,int $user,int $session): array
    {
        if($session<1)return [];
        $rows=AgentStore::owner('artifact',$tenant,$user)->where('session_id',$session)->order('id','desc')->limit(100)->select()->toArray();
        return array_map(static function($row)use($tenant,$user){
            $data=AgentStore::json($row['data']);
            $asset=OpenAssets::detail($tenant,$user,$data['file_id']);
            $ref=self::libraryReference($row);
            if($ref){
                $state=Db::name('user_asset_state')->where(['tenant_id'=>$tenant,'user_id'=>$user,'asset_ref'=>$ref])->find();
                // Import only a legacy positive favorite; an explicit shared zero always wins.
                if(!$state && (int)$row['favorite']===1)$state=WorkspaceAssetService::update($tenant,$user,['refs'=>[$ref],'favorite'=>1])['states'][0];
                $row['favorite']=(int)($state['favorite']??0);
            }
            return array_intersect_key($row,array_flip(['id','run_id','step_id','title','kind','favorite','create_time']))+$asset+['content'=>$data['content']??''];
        },$rows);
    }
    public static function favorite(int $tenant,int $user,int $id,bool $favorite): void
    {
        $row=AgentStore::owner('artifact',$tenant,$user)->where('id',$id)->find();
        if(!$row)throw new \InvalidArgumentException('作品不存在');
        Db::transaction(function()use($tenant,$user,$id,$favorite,$row){
            $ref=self::libraryReference($row);
            if($ref)WorkspaceAssetService::update($tenant,$user,['refs'=>[$ref],'favorite'=>(int)$favorite]);
            AgentStore::owner('artifact',$tenant,$user)->where('id',$id)->update(['favorite'=>(int)$favorite]);
        });
    }
    /** Resolve the original owned generation record, including artifacts saved before library linking. */
    private static function libraryReference(array $artifact): ?string
    {
        if(!(int)$artifact['step_id'])return null; // Standalone documents keep their session favorite.
        $tenant=(int)$artifact['tenant_id'];$user=(int)$artifact['user_id'];
        $step=AgentStore::owner('step',$tenant,$user)->where(['id'=>$artifact['step_id'],'run_id'=>$artifact['run_id']])->find();
        $sources=['aigc_image'=>['image','image_uri'],'aigc_video'=>['video','video_uri']];
        if(!$step||!isset($sources[$step['tool_code']])||!(int)$step['task_id'])return null;
        $data=AgentStore::json($artifact['data']);
        $file=OpenStore::owner('file',$tenant,$user)->where('file_id',$data['file_id']??'')->find();
        if(!$file)return null;
        [$source,$uriField]=$sources[$step['tool_code']];
        $scope=['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$step['task_id'],'delete_time'=>0,$uriField=>$file['uri']];
        foreach(['storage_scope','storage_engine','storage_domain'] as $field)$scope[$field]=$file[$field]??'';
        if(!Db::name($step['tool_code'].'_task')->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>$step['task_id'],'delete_time'=>0])->find())return null;
        $id=Db::name($step['tool_code'].'_result')->where($scope)->value('id');
        return $id ? $source.':result:'.$id : null;
    }
    private static function save(array $run,int $step,string $title,string $kind,string $file,array $storage,array $extra=[]): void
    {
        $identity=hash('sha256',$run['session_id'].':'.$file);
        if(AgentStore::owner('artifact',(int)$run['tenant_id'],(int)$run['user_id'])->where('identity_hash',$identity)->find())return;
        AgentStore::table('artifact')->insert(['tenant_id'=>$run['tenant_id'],'user_id'=>$run['user_id'],'session_id'=>$run['session_id'],'run_id'=>$run['id'],'step_id'=>$step,'identity_hash'=>$identity,'title'=>mb_substr($title,0,200),'kind'=>$kind,'data'=>AgentStore::encode(['file_id'=>$file]+$extra),'storage_scope'=>$storage['storage_scope'],'storage_engine'=>$storage['storage_engine'],'storage_domain'=>$storage['storage_domain']??'','favorite'=>0,'create_time'=>time()]);
        AgentStore::event($run,'artifact.created',['file_id'=>$file,'kind'=>$kind]);
    }
    public static function collect(array $run,array $step,array $result): void
    {
        foreach((array)($result['results']??[]) as $row){
            if(!is_array($row))continue;
            $file=OpenAssets::registerResult((int)$run['tenant_id'],(int)$run['user_id'],$step['tool_code'],$row);
            if(!$file)continue;
            $kind=!empty($row['video_uri'])?'video':(!empty($row['audio_uri'])?'audio':(!empty($row['image_uri'])?'image':'document'));
            self::save($run,(int)$step['id'],$step['title'],$kind,$file,$row);
        }
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
