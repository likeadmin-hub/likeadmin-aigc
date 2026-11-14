<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_store_visit;
use app\common\service\ai\StudioMediaInputService;
use InvalidArgumentException;

final class StoreVisitVoiceReference
{
    public static function normalize(int $tenant,int $user,?array $audio): ?array
    {
        if(!$audio)return null;
        $input=(string)($audio['uri']??$audio['url']??'');
        if($input==='')throw new InvalidArgumentException('请上传有效参考音色');
        return StudioMediaInputService::validateAudio($tenant,$user,$input,60,20*1024*1024)
            +['reference_slot_id'=>'store_visit_voice','mention_name'=>'音频1','role'=>'reference_audio','type'=>'audio'];
    }
    public static function assets(array $body,array $selection): array
    {
        $assets=[];
        foreach($body['images'] as$image)$assets[]=array_replace($image,['type'=>'image','subject_role'=>$image['role'],'role'=>$selection['generation_method']==='image_to_video'?'first_frame_image':'reference_image']);
        if(!empty($body['reference_audio']))$assets[]=array_replace($body['reference_audio'],['type'=>'audio','role'=>'reference_audio','mention_name'=>'音频1']);
        return $assets;
    }
    public static function parameters(array $assets): array
    {
        return ['reference_images'=>array_column(array_filter($assets,static fn($a)=>$a['type']==='image'),'uri'),
            'reference_audios'=>array_column(array_filter($assets,static fn($a)=>$a['type']==='audio'),'uri'),
            'reference_assets'=>$assets,'selected_mentions'=>array_map(static fn($a)=>['name'=>$a['mention_name'],'type'=>$a['type'],'reference_slot_id'=>$a['reference_slot_id'],'uri'=>$a['uri']],$assets)];
    }
}
