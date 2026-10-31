<?php
namespace app\common\service\app\aigc_image;

use think\Model;
use think\facade\Db;

/** Parent image apps may replay child facts when opening history. */
final class ImageAppTaskSync
{
    private static int $localReadDepth=0;
    private const APPS=['product_image','product_suite','style_transfer','photo_restore','model_wear','background_removal','image_translate','one_click_cleanup','product_multi_angle','fashion_lookbook','outpaint','local_redraw','fitting'];

    public static function providerRefreshAllowed(): bool { return self::$localReadDepth===0; }

    public static function localReads(callable $operation): mixed
    {
        self::$localReadDepth++;
        try{return $operation();}finally{self::$localReadDepth--;}
    }

    /** Complete the original parent projections from owned terminal child facts, with no provider polling. */
    public static function projectParents(array $consumption,int $imageId): void
    {
        if(!in_array($consumption['billing_status']??'',['settled','refunded'],true))return;
        $owner=['tenant_id'=>(int)$consumption['tenant_id'],'user_id'=>(int)$consumption['user_id']];
        $child=Db::name('aigc_image_task')->where($owner+['id'=>$imageId,'app_task_id'=>$consumption['app_task_id'],'delete_time'=>0])->find();
        if(!$child)return;
        $codes=array_map(static fn($suffix)=>'aigc_'.$suffix,self::APPS);
        $installed=Db::name('app')->whereIn('code',$codes)->where('status','installed')->column('code');
        foreach($installed as$code){
            $table=$code.'_task';$fields=Db::name($table)->getFields();
            if(!isset($fields['image_task_id']))continue;
            $query=Db::name($table)->where($owner+['delete_time'=>0])->where(function($q)use($imageId,$fields){
                $q->where('image_task_id',$imageId);
                if(isset($fields['image_task_ids']))$q->whereOrRaw('CASE WHEN JSON_VALID(image_task_ids) THEN JSON_CONTAINS(image_task_ids, ?) ELSE 0 END',[(string)$imageId]);
            });
            $suffix=substr($code,5);$name='Aigc'.implode('',array_map('ucfirst',explode('_',$suffix))).'Service';
            $service='app\\common\\service\\app\\'.$code.'\\'.$name;
            foreach($query->column('id')as$id)Db::transaction(function()use($table,$owner,$id,$service){
                $parent=Db::name($table)->where($owner+['id'=>$id,'delete_time'=>0])->lock(true)->find();
                if($parent)self::localReads(static fn()=>$service::taskDetail($owner['tenant_id'],(int)$id,$owner['user_id']));
            });
        }
    }

    public static function finishTime(Model $task, array $childTimes, array $terminalStatuses): int
    {
        if (!in_array((string)$task['status'], $terminalStatuses, true)) return 0;
        // Late child facts must not redate a parent whose terminal status is unchanged.
        if ((string)$task->getOrigin('status') === (string)$task['status'] && (int)$task['finish_time'] > 0) {
            return (int)$task['finish_time'];
        }
        if ($childTimes) return max($childTimes);
        return time();
    }

    public static function saveChangedFacts(Model $task): void
    {
        if (!$task->getChangedData()) return;
        $task->update_time = time();
        $task->save();
    }
}
