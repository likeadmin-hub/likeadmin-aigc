<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\open_platform\OpenAssets;
final class AgentSubjectReferences
{
    public static function lists(int $tenant,int $user,array $params): array
    {
        $result=AigcShortDramaService::subjectLibraryLists($tenant,$user,$params);
        $result['lists']=array_map(static fn($row)=>array_intersect_key($row,array_flip(['id','name','image','description','category','scope'])),$result['lists']);
        return $result;
    }
    public static function resolve(int $tenant,int $user,int $id): array
    {
        $reference=AigcShortDramaService::agentSubjectReference($tenant,$user,$id);
        $ext=strtolower(pathinfo((string)(parse_url($reference['image_uri'],PHP_URL_PATH)?:$reference['image_uri']),PATHINFO_EXTENSION));
        $mime=match($ext){'jpg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif',default=>throw new \InvalidArgumentException('主体图片格式不支持引用')};
        $file=OpenAssets::registerResult($tenant,$user,'aigc_image',$reference+['mime'=>$mime]);
        if(!$file)throw new \InvalidArgumentException('主体图片暂不可引用');
        $asset=OpenAssets::detail($tenant,$user,$file);
        $asset['name']='@'.$reference['name'];
        $asset['subject_id']=$id;
        $asset['subject_description']=mb_substr($reference['description'],0,2000);
        return $asset;
    }
}
