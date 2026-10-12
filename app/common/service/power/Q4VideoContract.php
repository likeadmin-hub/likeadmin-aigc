<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** https://api.likeadmin.cn/user_center/docs?slug=model-q4-preview-video */
final class Q4VideoContract
{
    public const OPTIONS = ['is_rec','audio','seed','watermark','wm_position','wm_url','payload','meta_data','callback_url'];
    public static function matches(array $model): bool
    {
        return strtolower((string)($model['upstream_model_code'] ?? $model['model_code'] ?? '')) === 'q4-preview-video';
    }
    public static function metadata(array $model, array $meta): array
    {
        if (!self::matches($model)) return $meta;
        // The documented single first frame is not generic multi-image reference generation.
        $meta['generation_modes'] = ['image_to_video'];
        $meta['input_modes'] = ['image_to_video'];
        $meta['supported_asset_types'] = ['image'];
        $meta['max_reference_images'] = 1;
        $meta['max_reference_assets'] = 1;
        $meta['max_reference_videos'] = 0;
        $meta['max_reference_audios'] = 0;
        $meta['supports_first_last_frame'] = false;
        $meta['supported_durations'] = range(3,16);
        $meta['default_params'] = array_replace(['duration'=>5,'resolution'=>'720p','audio'=>true,'is_rec'=>true,'watermark'=>true,'wm_position'=>3,'seed'=>0], (array)($meta['default_params']??[]));
        $meta['params_schema'] = array_replace([
            'images'=>['type'=>'array','minItems'=>1,'maxItems'=>1,'required'=>true],
            'duration'=>['type'=>'integer','enum'=>range(3,16),'default'=>5],
            'resolution'=>['type'=>'string','enum'=>['540p','720p','1080p','2K','4K'],'default'=>'720p'],
        ], (array)($meta['params_schema']??[]));
        return $meta;
    }
    public static function options(array $request): array
    {
        $out=[];
        foreach (['is_rec'=>true,'audio'=>true,'watermark'=>true] as $key=>$default) {
            $v=$request[$key]??$default;
            if (!is_scalar($v)) throw new InvalidArgumentException('Q4 开关参数格式不正确：'.$key);
            $v=filter_var($v,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            if($v===null) throw new InvalidArgumentException('Q4 开关参数格式不正确：'.$key);
            $out[$key]=$v;
        }
        foreach(['seed'=>[0,PHP_INT_MAX,0],'wm_position'=>[1,4,3]] as $key=>[$min,$max,$default]) {
            $v=$request[$key]??$default;
            if(is_bool($v)||!is_numeric($v)||(float)$v!=(int)$v||$v<$min||$v>$max) throw new InvalidArgumentException('Q4 整数参数超出范围：'.$key);
            $out[$key]=(int)$v;
        }
        foreach(['wm_url','payload','meta_data','callback_url'] as $key) {
            $v=$request[$key]??'';
            if(!is_string($v)) throw new InvalidArgumentException('Q4 文本参数格式不正确：'.$key);
            if($v==='')continue;
            if(in_array($key,['wm_url','callback_url'],true)&&(!filter_var($v,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($v,PHP_URL_SCHEME)),['http','https'],true))) throw new InvalidArgumentException('Q4 地址必须为 HTTP/HTTPS：'.$key);
            if($key==='payload'&&mb_strlen($v)>1048576)throw new InvalidArgumentException('Q4 业务关联数据过长');
            if($key==='meta_data'){json_decode($v);if(json_last_error()!==JSON_ERROR_NONE)throw new InvalidArgumentException('Q4 元数据必须是有效 JSON 字符串');}
            $out[$key]=$v;
        }
        if(!$out['watermark'])unset($out['wm_position'],$out['wm_url']);
        return $out;
    }
    public static function validate(array $request,array $assets): void
    {
        if(count($assets['image']??[])!==1||!empty($assets['video'])||!empty($assets['audio']))throw new InvalidArgumentException('Q4 需要且仅支持一张首帧图片');
        $image = (string)$assets['image'][0];
        if (str_starts_with($image, 'data:')) {
            if (!preg_match('~^data:image/(?:png|jpe?g|webp);base64,(.+)$~s', $image, $match)) throw new InvalidArgumentException('Q4 图片格式不支持');
            $decoded = base64_decode($match[1], true);
            if ($decoded === false || strlen($decoded) > 50 * 1024 * 1024) throw new InvalidArgumentException('Q4 图片数据无效或超过 50 MB');
        } elseif (!filter_var($image, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($image, PHP_URL_SCHEME)), ['http','https'], true)) {
            throw new InvalidArgumentException('Q4 首帧必须为 HTTP/HTTPS 图片地址或完整图片 Data URI');
        }
        if(mb_strlen((string)($request['prompt']??''))>20000)throw new InvalidArgumentException('Q4 提示词最多 20000 个字符');
        $duration=$request['duration']??5;
        if(!is_numeric($duration)||(float)$duration!=(int)$duration||(int)$duration<3||(int)$duration>16)throw new InvalidArgumentException('Q4 时长必须为 3 至 16 秒的整数');
        self::options($request);
    }
    public static function payload(array $snapshot,array $request,array $assets,string $quality,int $duration,string $idempotency,array $context): array
    {
        $request['duration']=$duration?:5; self::validate($request,$assets);
        $resolution=['540p'=>'540p','720p'=>'720p','1080p'=>'1080p','2k'=>'2K','4k'=>'4K'][strtolower($quality?:'720p')]??'';
        if($resolution==='')throw new InvalidArgumentException('Q4 不支持所选分辨率');
        $out=array_merge($context,self::options($request),['model'=>'q4-preview-video','images'=>array_values($assets['image']),'prompt'=>(string)($request['prompt']??''),'duration'=>$request['duration'],'resolution'=>$resolution,'idempotency_key'=>$idempotency]);
        if(!empty($snapshot['channel_code']))$out['channel']=$snapshot['channel_code'];
        if(strlen(json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>=20*1024*1024)throw new InvalidArgumentException('Q4 请求体必须小于 20 MB，请使用图片 URL');
        return $out;
    }
}
