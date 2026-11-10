<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;
use InvalidArgumentException;
use think\facade\Db;

/** Admin files are authorized by the current terminal and tenant, never by a browser URL. */
final class ProductVideoAdminMedia
{
    private static function query(int $scope,int $type){
        $q=Db::name($scope>0?'tenant_file':'file')->where(['type'=>$type,'source'=>0])->whereRaw('(delete_time IS NULL OR delete_time=0)');
        if($scope>0)$q->where('tenant_id',$scope);return $q;
    }
    public static function asset(int $scope,array $input,int $type): array
    {
        $id=(int)($input['file_id']??0);$file=$id>0?self::query($scope,$type)->where('id',$id)->find():null;
        if(!$file)throw new InvalidArgumentException('请选择当前后台素材库中有效的'.($type===10?'商品图片':'预览视频'));
        return ['uri'=>$file['uri'],'name'=>$file['name'],'storage_scope'=>$file['storage_scope'],'storage_engine'=>$file['storage_engine'],'storage_domain'=>$file['storage_domain'],
            'role'=>ProductVideoPolicy::text($input['role']??'',100),'reference_slot_id'=>\app\common\service\ai\ReferenceMaterialSlot::id($input)?:'pv_'.bin2hex(random_bytes(12)),
            'mention_name'=>ProductVideoPolicy::text($input['mention_name']??'',100)];
    }
    public static function images(int $scope,array $images): array
    {
        if(!$images||count($images)>15)throw new InvalidArgumentException('请添加 1～15 张商品图片');
        $out=[];foreach($images as $i=>$image){$asset=self::asset($scope,(array)$image,10);$asset['mention_name']=$asset['mention_name']?:'图片'.($i+1);$out[]=$asset;}return $out;
    }
    public static function verifyStored(int $scope,array $asset,int $type): array
    {
        $file=self::query($scope,$type)->where('uri',(string)($asset['uri']??''))->order('id','desc')->find();
        if(!$file)throw new InvalidArgumentException('模板的后台素材已移除，请重新上传');
        foreach(['storage_scope','storage_engine','storage_domain'] as $key)if((string)($asset[$key]??'')!==(string)$file[$key])throw new InvalidArgumentException('模板素材存储归属已变化，请重新设置模板');
        return $asset;
    }
}
