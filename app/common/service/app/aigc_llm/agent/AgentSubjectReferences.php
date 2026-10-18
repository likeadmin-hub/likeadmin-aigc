<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\open_platform\OpenAssets;
final class AgentSubjectReferences
{
    private static function access(int $tenant,int $user): void
    {
        // Match the source library's AppAccessMiddleware, including membership.
        if($tenant<1||$user<1||\app\common\service\app\AppAccessService::assertTenantCanUse($tenant,'aigc_short_drama',$user,true,false))throw new \InvalidArgumentException('当前账号暂不可使用主体库');
    }
    public static function lists(int $tenant,int $user,array $params): array
    {
        self::access($tenant,$user);
        $result=AigcShortDramaService::subjectLibraryLists($tenant,$user,$params,false);
        $result['lists']=array_map(static fn($row)=>array_intersect_key($row,array_flip(['id','name','image','description','category','scope'])),$result['lists']);
        return $result;
    }
    public static function source(int $tenant,int $user,int $id): array
    {
        self::access($tenant,$user);
        return AigcShortDramaService::agentSubjectReference($tenant,$user,$id);
    }
    public static function resolve(int $tenant,int $user,int $id): array
    {
        $reference=self::source($tenant,$user,$id);
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
