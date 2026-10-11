<?php
namespace app\common\service\app\aigc_video;

use app\common\model\app\aigc_video\AigcVideoConfig;
use InvalidArgumentException;

/** Tenant-owned prompt presets. No provider calls or billing mutations. */
class VideoEffectService
{
    public static function defaults(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 4).'/apps/aigc_video/data/video_effects.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function normalize($rows): array
    {
        if (!is_array($rows) || count($rows)>300) throw new InvalidArgumentException('效果数量不能超过300条');
        $result=[];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id']??null) || !preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $row['id']) || isset($result[$row['id']])) throw new InvalidArgumentException('效果标识无效或重复');
            foreach (['name'=>40,'description'=>300,'instruction'=>2000] as $key=>$max) {
                if (!is_string($row[$key]??null) || trim($row[$key])==='' || mb_strlen($row[$key])>$max) throw new InvalidArgumentException('请检查效果名称、说明与指令长度');
                $row[$key]=trim($row[$key]);
            }
            $categories=$row['categories']??['Camera Control'];
            if (!is_array($categories) || !$categories || array_filter($categories, static fn($value)=>!is_string($value)) || array_diff($categories, ['Camera Control','Action Movement','UGC','Commercial','First/Last Frame','Viral','Commercial/Ads'])) throw new InvalidArgumentException('请选择有效的效果分类');
            $row['categories']=array_values(array_unique($categories));
            $ids=$row['product_ids']??[]; $methods=$row['methods']??[];
            if (!is_array($ids) || count($ids)>100 || !is_array($methods) || !$methods || array_diff($methods,['text_to_video','image_to_video','image_reference','start_end'])) throw new InvalidArgumentException('效果模型或生成方式配置无效');
            foreach ($ids as &$id) {
                if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) throw new InvalidArgumentException('请选择有效的视频模型');
                $id=(int)$id;
            } unset($id);
            foreach (['poster','video'] as $field) {
                $value=$row[$field]??'';
                if (!is_string($value) || strlen($value)>2048 || ($value!=='' && !preg_match('~^(?:https?://[^\s]+|/(?!/)[^\s]*)$~i',$value))) throw new InvalidArgumentException('演示素材地址无效');
                $row[$field]=$value;
            }
            $enabled=filter_var($row['enabled']??false,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            $requireImage=filter_var($row['require_image']??false,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            if ($enabled===null || $requireImage===null) throw new InvalidArgumentException('效果开关配置无效');
            if ($enabled && !$ids) throw new InvalidArgumentException('启用前请选择适配模型');
            $result[$row['id']]=array_intersect_key($row,array_flip(['id','name','description','instruction','poster','video','categories']))+['enabled'=>$enabled,'product_ids'=>array_values(array_unique($ids)),'methods'=>array_values(array_unique($methods)),'require_image'=>$requireImage];
        }
        return array_values($result);
    }

    public static function catalog(int $tenantId): array
    {
        $row=AigcVideoConfig::where('tenant_id',$tenantId)->findOrEmpty();
        $config=$row->isEmpty()?[]:(array)$row['config_json'];
        // Defaults are local to the tenant; never inherit another tenant's validation.
        return isset($config['video_effects']) ? self::normalize($config['video_effects']) : self::defaults();
    }

    public static function publicCatalog(array $rows): array
    {
        return array_values(array_map(static fn($row)=>$row,array_filter($rows,static fn($row)=>!empty($row['enabled']))));
    }

    public static function resolve(array $rows, array $params, int $productId): ?array
    {
        $id=$params['effect_id']??'';
        if ($id==='' || $id===null) return null;
        if (!is_string($id)) throw new InvalidArgumentException('效果标识无效');
        $effect=null;
        foreach ($rows as $row) if ($row['id']===$id) $effect=$row;
        if (!$effect || !$effect['enabled']) throw new InvalidArgumentException('该效果暂未开放，请移除效果后重试');
        if (!in_array($productId,$effect['product_ids'],true)) throw new InvalidArgumentException('当前模型未配置此效果，请更换模型或移除效果');
        $assets=AigcVideoReferenceAssetService::normalize($params);
        $method=(string)($params['generation_method']??($assets ? 'image_reference':'text_to_video'));
        if (!in_array($method,$effect['methods'],true)) throw new InvalidArgumentException('此效果不支持当前生成方式');
        foreach ($assets as $asset) if (($asset['type']??'image')!=='image') throw new InvalidArgumentException('运镜效果暂仅支持文字或图片素材');
        if ($effect['require_image'] && !$assets) throw new InvalidArgumentException('此效果需要上传参考图片');
        if ($method==='start_end') {
            $roles=array_column($assets,'role');
            if(count($assets)!==2 || !in_array('first_frame_image',$roles,true) || !in_array('last_frame_image',$roles,true)) throw new InvalidArgumentException('此效果需要各上传一张首帧和尾帧图片');
        }
        if (array_key_exists('effect_prompt',$params)) {
            if(!is_string($params['effect_prompt']) || trim($params['effect_prompt'])==='' || mb_strlen($params['effect_prompt'])>2000) throw new InvalidArgumentException('效果提示词需为1至2000字');
            $effect['instruction']=trim($params['effect_prompt']);
        }
        return $effect;
    }

    public static function compile(array $params, ?array $effect): array
    {
        // Drop client-supplied snapshots/instructions; use only the server catalog.
        unset($params['effect_snapshot']);
        if (!$effect) return $params;
        $prompt=trim((string)($params['prompt']??''));
        if ($prompt==='') throw new InvalidArgumentException('请输入主体与场景描述');
        $params['prompt']=$prompt."\n\nEffect direction:\n".$effect['instruction'];
        return $params;
    }

    public static function snapshot(array $effect, string $prompt): array
    {
        return ['id'=>$effect['id'],'name'=>$effect['name'],'instruction'=>$effect['instruction'],'user_prompt'=>$prompt,'version'=>hash('sha256',json_encode($effect,JSON_UNESCAPED_UNICODE))];
    }
}
