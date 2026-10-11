<?php
namespace app\common\service\open_platform;
use app\common\model\app\aigc_image\AigcImageTask;
use think\facade\Db;
/** Persist the business parent when the native image is created, before any upstream submit. */
final class OpenImageHandoff
{
    public static function callback(int $tenant,int $user,string $app,callable $attach,bool $multiple=false): ?callable
    {
        $id=OpenCallContext::id();if($id==='')return null;
        return static function(AigcImageTask $image)use($tenant,$user,$app,$attach,$id,$multiple): void {
            if(OpenCallContext::id()!==$id||(int)$image['tenant_id']!==$tenant||(int)$image['user_id']!==$user)throw new OpenException('task_ownership_invalid','图片任务归属不一致',409);
            Db::transaction(static function()use($tenant,$user,$app,$attach,$id,$image,$multiple){
                $row=OpenStore::owner('request',$tenant,$user)->where(['request_id'=>$id,'kind'=>'tool','app_code'=>$app,'method'=>'POST','path'=>'/api/v1/tools/'.$app.'/tasks'])->lock(true)->find();
                if(!$row)throw new OpenException('task_context_invalid','图片任务提交上下文不一致',409);
                OpenCallContext::assertLease($row);
                $parent=$attach($image);
                $expected=OpenToolRegistry::get($app)['task_model']??'';
                if(!is_object($parent)||get_class($parent)!==$expected||(int)$parent['tenant_id']!==$tenant||(int)$parent['user_id']!==$user||
                    (!$multiple&&(int)$parent['image_task_id']!==(int)$image['id'])||
                    ($multiple&&!in_array((int)$image['id'],(array)$parent['image_task_ids'],true)))throw new OpenException('task_mapping_invalid','图片业务任务关联无效',409);
                // Existing parent inserts bind through AppBaseModel. A second child
                // must not silently replace an immutable single-image mapping.
                $mapped=OpenStore::owner('request',$tenant,$user)->where('request_id',$id)->value('tool_task_id');
                if((string)$mapped!==(string)$parent['id'])throw new OpenException('task_mapping_conflict','图片业务任务关联冲突',409);
            });
        };
    }
}
