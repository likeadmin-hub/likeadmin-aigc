<?php
namespace app\common\service\app\aigc_video;

use app\common\model\app\aigc_video\AigcVideoConfig;
use InvalidArgumentException;

/** Tenant-owned prompt presets. No provider calls or billing mutations. */
class VideoEffectService
{
    public static function defaults(): array
    {
        $seeds = [
            ['push_in', '镜头推进', '镜头平稳靠近主体，突出细节。', 'Camera slowly dollies forward toward the main subject in a single continuous shot. Keep the subject identity, geometry and scene consistent. Smooth restrained camera movement, no cuts, no morphing.'],
            ['pull_out', '镜头拉远', '镜头平稳远离主体，逐渐展示环境。', 'Camera slowly dollies backward away from the main subject, gradually revealing the surrounding environment in a single continuous shot. Keep subject identity and geometry consistent. Smooth restrained movement, no cuts, no morphing.'],
            ['truck_right', '镜头横移', '镜头向右平移，呈现自然视差。', 'Camera trucks slowly to the right, keeping the main subject in view with natural background parallax in a single continuous shot. Preserve subject identity, proportions and scene. No orbit, no cuts, no morphing.'],
        ];
        return array_map(static fn($s) => ['id'=>$s[0], 'name'=>$s[1], 'description'=>$s[2], 'instruction'=>$s[3], 'enabled'=>false, 'product_ids'=>[], 'methods'=>['text_to_video','image_reference'], 'require_image'=>false, 'poster'=>'', 'video'=>''], $seeds);
    }

    public static function normalize($rows): array
    {
        if (!is_array($rows) || count($rows) !== 3) throw new InvalidArgumentException('请保留三个内置运镜效果');
        $valid = array_column(self::defaults(), 'id'); $result=[];
        foreach ($rows as $row) {
            if (!is_array($row) || !in_array($row['id']??'', $valid, true) || isset($result[$row['id']])) throw new InvalidArgumentException('效果标识无效或重复');
            foreach (['name'=>40,'description'=>300,'instruction'=>2000] as $key=>$max) {
                if (!is_string($row[$key]??null) || trim($row[$key])==='' || mb_strlen($row[$key])>$max) throw new InvalidArgumentException('请检查效果名称、说明与指令长度');
                $row[$key]=trim($row[$key]);
            }
            $ids=$row['product_ids']??[]; $methods=$row['methods']??[];
            if (!is_array($ids) || count($ids)>100 || !is_array($methods) || !$methods || array_diff($methods,['text_to_video','image_reference'])) throw new InvalidArgumentException('效果模型或生成方式配置无效');
            foreach ($ids as &$id) {
                if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) throw new InvalidArgumentException('请选择有效的视频模型');
                $id=(int)$id;
            } unset($id);
            foreach (['poster','video'] as $field) {
                $value=$row[$field]??'';
                if (!is_string($value) || strlen($value)>2048 || ($value!=='' && !preg_match('~^(?:https?://[^\s]+|/(?!/)[^\s]*)$~i',$value))) throw new InvalidArgumentException('演示素材地址无效');
                $row[$field]=$value;
            }
            $enabled=(bool)($row['enabled']??false);
            if ($enabled && !$ids) throw new InvalidArgumentException('启用前请选择适配模型');
            $result[$row['id']]=array_intersect_key($row,array_flip(['id','name','description','instruction','poster','video']))+['enabled'=>$enabled,'product_ids'=>array_values(array_unique($ids)),'methods'=>array_values(array_unique($methods)),'require_image'=>(bool)($row['require_image']??false)];
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
        return array_values(array_map(static function($row){ unset($row['instruction']); return $row; },array_filter($rows,static fn($row)=>!empty($row['enabled']))));
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
        return $effect;
    }

    public static function compile(array $params, ?array $effect): array
    {
        // Drop client-supplied snapshots/instructions; use only the server catalog.
        unset($params['effect_snapshot']);
        if (!$effect) return $params;
        $prompt=trim((string)($params['prompt']??''));
        if ($prompt==='') throw new InvalidArgumentException('请输入主体与场景描述');
        $params['prompt']=$prompt."\n\nCamera direction:\n".$effect['instruction'];
        return $params;
    }

    public static function snapshot(array $effect, string $prompt): array
    {
        return ['id'=>$effect['id'],'name'=>$effect['name'],'instruction'=>$effect['instruction'],'user_prompt'=>$prompt,'version'=>hash('sha256',json_encode($effect,JSON_UNESCAPED_UNICODE))];
    }
}
