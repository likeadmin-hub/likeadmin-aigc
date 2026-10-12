<?php
namespace app\common\service\open_platform;
use app\common\model\app\aigc_music\AigcMusicAsset;
/** Native numeric resource aliases need the same owner checks as file_id inputs. */
final class OpenToolResourceOwnership
{
    public static function check(int $tenant,int $user,array $operation,array $params): void
    {
        $app=$operation['app_code'];$controller=$operation['controller']??'';
        if($app==='aigc_music_cover'&&$controller==='Generate')$fields=['source_asset_id','source_id','asset_id','reference_asset_id','ref_asset_id','voice_asset_id'];
        elseif($app==='aigc_music'&&in_array($controller,['Generate','Persona'],true))$fields=['reference_asset_id'];
        else return;
        foreach($fields as $field){
            if(!array_key_exists($field,$params))continue;$value=$params[$field];
            if(!is_int($value)&&!(is_string($value)&&preg_match('/^[0-9]+$/D',$value)))throw new OpenException('invalid_request','素材 ID 必须为非负整数');
            $id=(int)$value;if($id<0)throw new OpenException('invalid_request','素材 ID 必须为非负整数');if($id===0)continue;
            if(!AigcMusicAsset::where(['id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user,'status'=>1,'delete_time'=>0])->find())throw new OpenException('not_found','素材不存在或无权访问',404);
        }
    }
}
