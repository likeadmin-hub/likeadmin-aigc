<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;
use think\facade\Db;

/** A project owns bounded page documents; generation receipts remain page-scoped. */
final class ShortDramaCanvasPages
{
    public const TABLE='aigc_short_drama_canvas_page';
    private const DOCUMENT='aigc_short_drama_canvas';

    public static function root(int $tenant,int $user,int $canvas): int
    {
        $root=(int)Db::name(self::TABLE)->where(['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas])->value('project_id');
        $root=$root?:$canvas;
        if (!Db::name(self::DOCUMENT)->where(['id'=>$root,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->find()) throw new RuntimeException('CANVAS_NOT_FOUND');
        return $root;
    }

    public static function info(array $document): array
    {
        $tenant=(int)$document['tenant_id'];$user=(int)$document['user_id'];$id=(int)$document['id'];
        $root=self::root($tenant,$user,$id);
        $pages=Db::name(self::TABLE)->where(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$root])->order('id','asc')->field('canvas_id,title')->select()->toArray();
        if (!$pages) $pages=[['canvas_id'=>$root,'title'=>'第 1 页']];
        $pages=array_map(static fn($p)=>['id'=>(int)$p['canvas_id'],'title'=>(string)$p['title']],$pages);
        $current=array_values(array_filter($pages,static fn($p)=>$p['id']===$id));
        return ['canvas_project_id'=>$root,'page_title'=>$current[0]['title']??'第 1 页','pages'=>$pages];
    }

    public static function create(int $tenant,int $user,int $canvas,string $key,string $title=''): array
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D',$key)) throw new RuntimeException('INVALID_PAGE_REQUEST_KEY');
        return Db::transaction(static function()use($tenant,$user,$canvas,$key,$title):array {
            $root=self::root($tenant,$user,$canvas);
            $project=Db::name(self::DOCUMENT)->where(['id'=>$root,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
            if (!$project) throw new RuntimeException('CANVAS_NOT_FOUND');
            $scope=['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$root];
            $existing=Db::name(self::TABLE)->where($scope+['page_key'=>$key])->find();
            if ($existing) return ShortDramaCanvasService::current($tenant,$user,(int)$existing['canvas_id']);
            if (!Db::name(self::TABLE)->where($scope+['canvas_id'=>$root])->find()) {
                Db::name(self::TABLE)->insert($scope+['canvas_id'=>$root,'page_key'=>'root','title'=>str_starts_with($key,'episode:')?'第 1 集':'第 1 页','create_time'=>time()]);
            }
            $count=(int)Db::name(self::TABLE)->where($scope)->count();
            if ($count>=1000) throw new RuntimeException('CANVAS_PAGE_LIMIT');
            $title=mb_substr(trim($title)?:'第 '.($count+1).' 页',0,80);
            $id=(int)Db::name(self::DOCUMENT)->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'title'=>$project['title'],
                'nodes_json'=>'[]','edges_json'=>'[]','viewport_json'=>'{}','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
            Db::name(self::TABLE)->insert($scope+['canvas_id'=>$id,'page_key'=>$key,'title'=>$title,'create_time'=>time()]);
            return ShortDramaCanvasService::current($tenant,$user,$id);
        });
    }
}
