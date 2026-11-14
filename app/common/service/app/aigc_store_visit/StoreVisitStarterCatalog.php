<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_store_visit;
use app\common\service\FileService;

/** File-backed script inspiration, distinct from completed video examples. No lazy database writes. */
final class StoreVisitStarterCatalog
{
    public static function all(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__,4).'/apps/aigc_store_visit/data/starter-inspirations.json'),true,64,JSON_THROW_ON_ERROR);
    }
    public static function find(int $id): ?array
    {foreach(self::all()as$row)if($row['id']===$id)return $row;return null;}
    public static function dto(array $row): array
    {
        $genre=[];foreach(StoreVisitService::defaultGenres()as$item)if($item['code']===$row['story_type']){$genre=['code'=>$item['code'],'name'=>$item['name']];break;}
        return ['id'=>$row['id'],'title'=>$row['title'],'configuration'=>['genre_snapshot'=>$genre,'story_type'=>$row['story_type'],'requirements'=>$row['description'],'script'=>$row['script']],
            'description'=>$row['description'],'cover_url'=>FileService::getFileUrl($row['cover']),'media_url'=>'','media_type'=>'image','preview'=>[],
            'is_builtin'=>true,'is_script_template'=>true,'builtin_version'=>1,'readonly'=>true,'status'=>1,'sort'=>9000,'ratio'=>'3:4','tenant_id'=>0,'source_task_id'=>0];
    }
    public static function lists(string $keyword=''): array
    {
        $rows=self::all();if($keyword!=='')$rows=array_values(array_filter($rows,static fn($r)=>mb_stripos($r['title'].' '.$r['description'],$keyword)!==false));
        return array_map([self::class,'dto'],$rows);
    }
}
