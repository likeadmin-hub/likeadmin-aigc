<?php
namespace app\common\service\app\aigc_micro_video;
use think\facade\Db;
use RuntimeException;

final class MicroVideoStore
{
    public const APP='aigc_micro_video';
    public static function table(string $suffix){return Db::name(self::APP.'_'.$suffix);}
    public static function json(mixed $value): array {if(is_array($value))return $value;return json_decode((string)$value,true)?:[];}
    public static function encode(mixed $value): string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function owned(int $tenant,int $user,int $id,bool $lock=false): array
    {
        $q=self::table('task')->where(['id'=>$id,'tenant_id'=>$tenant,'delete_time'=>0]);if($user>0)$q->where('user_id',$user);if($lock)$q->lock(true);
        $row=$q->find();if(!$row)throw new RuntimeException('作品不存在或无权访问');return $row;
    }
    public static function update(int $id,array $data): void {self::table('task')->where('id',$id)->update($data+['update_time'=>time()]);}
    public static function segments(int $id): array{return self::table('segment')->where('task_id',$id)->order('sort')->select()->toArray();}
    public static function segmentData(array $row): array
    {
        return ['first_frame_id'=>(int)($row['first_frame_id']??0),'last_frame_id'=>(int)($row['last_frame_id']??0),'id'=>(int)$row['id'],'sort'=>(int)$row['sort'],'title'=>$row['title'],'duration'=>(int)$row['duration'],'prompt'=>$row['prompt'],'image_indices'=>self::json($row['image_indices']),'start_state'=>$row['start_state'],'end_state'=>$row['end_state'],'continuity'=>$row['continuity']];
    }
}
