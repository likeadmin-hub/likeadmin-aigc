<?php
namespace app\common\service\power;

/** Public generation controls. Routing, billing and callback settings are never editable here. */
final class MarketGenerationParameters
{
    private const OWNED = ['model','channel','provider','api_key','token','authorization','base_url','endpoint','callback_url','webhook_url','idempotency_key','metadata','client_id','user_id','tenant_id','market_sku_id','sku_id','pricing_sku_key','prompt','content','aspect_ratio','ratio','resolution','duration','seconds','video_duration','n','num_images','generation_type'];
    private const LABELS = [
        'quality'=>'渲染质量','image_size'=>'输出尺寸','size'=>'输出尺寸','output_format'=>'图片格式','output_quality'=>'输出质量','seed'=>'随机种子',
        'negative_prompt'=>'负面提示词','style'=>'画面风格','style_type'=>'风格类型','style_preset'=>'风格预设','style_codes'=>'风格编码','style_reference'=>'风格参考',
        'rendering_speed'=>'渲染速度','strength'=>'变化强度','guidance_scale'=>'提示词引导强度','cfg_scale'=>'提示词引导强度','num_inference_steps'=>'采样步数','steps'=>'采样步数',
        'watermark'=>'水印','aigc_watermark'=>'生成标识','enable_safety_checker'=>'安全检查','nsfw_checker'=>'内容过滤','safety_tolerance'=>'安全容忍度',
        'generate_audio'=>'生成音频','generate_audio_switch'=>'生成音频','sound'=>'生成声音','audio'=>'生成音频','audio_setting'=>'音频处理','audio_weight'=>'音频权重',
        'multi_shots'=>'多镜头','multi_prompt'=>'分镜提示词','shot_type'=>'镜头类型','shots'=>'分镜','generate_multi_clip_switch'=>'生成多片段','shot_prompt'=>'分镜描述',
        'prompt_optimizer'=>'优化提示词','prompt_extend'=>'扩展提示词','enhance_prompt'=>'增强提示词','camera_fixed'=>'固定镜头','camera_control'=>'镜头运动',
        'mode'=>'生成档位','service_tier'=>'服务档位','draft'=>'草稿模式','frames'=>'帧数','fps'=>'帧率','motion_bucket_id'=>'运动强度','character_orientation'=>'角色朝向',
        'elements'=>'主体素材','kling_elements'=>'主体素材','voices'=>'说话人音色','character_ids'=>'角色编号','audio_ids'=>'音频编号','video_list'=>'视频片段',
        'image_references'=>'参考图片','keyframes'=>'中间关键帧','keyframe_urls'=>'中间关键帧','loop_url'=>'循环帧','mask_url'=>'重绘遮罩','reference_mask_urls'=>'参考图遮罩',
        'reference_image_urls'=>'参考图片','reference_video_urls'=>'参考视频','reference_audio_urls'=>'参考音频','reference_file_urls'=>'参考文件','reference_link_urls'=>'参考链接',
        'image_url'=>'输入图片','image_urls'=>'输入图片','image_input'=>'输入图片','input_urls'=>'输入图片','input_image'=>'输入图片','file_url'=>'输入图片','files_url'=>'输入图片',
        'first_frame_url'=>'首帧图片','last_frame_url'=>'尾帧图片','first_frame_image_url'=>'首帧图片','last_frame_image_url'=>'尾帧图片','end_image_url'=>'尾帧图片','tail_image_url'=>'尾帧图片',
        'reference_image'=>'参考图片','reference_video'=>'参考视频','reference_voice'=>'参考音频','driving_audio_url'=>'驱动音频','first_clip_url'=>'前段视频','audio_url'=>'音频素材','video_url'=>'视频素材',
        'template_id'=>'效果模板','effect_type'=>'模板素材数量','name'=>'名称','description'=>'描述','url'=>'素材地址','image'=>'图片','video'=>'视频','audio_url'=>'音频地址',
        'timestamp_s'=>'时间位置（秒）','start'=>'起点（秒）','end'=>'终点（秒）','starts'=>'起点（秒）','ends'=>'终点（秒）','ref_name'=>'引用名称','type'=>'类型',
    ];
    public static function label(string $name, array $definition): string
    {
        if (isset(self::LABELS[$name])) return self::LABELS[$name];
        foreach (['title','label'] as $key) if (is_string($definition[$key] ?? null)) return $definition[$key];
        $description = (string)($definition['description'] ?? '');
        $description = preg_replace('/适用生成方式\s+\w+[（(][^）)]*[）)][:：]?\s*/u', '', $description);
        $first = preg_split('/[。；\n]/u', $description)[0] ?? '';
        return $first !== '' && mb_strlen($first) <= 24 ? $first : $name;
    }
    public static function definitions(array $model): array
    {
        $schema = MarketGenerationSchema::array($model['params_schema'] ?? []);
        $modes = (array)($model['material_modes'] ?? []);
        $result = [];
        foreach (MarketGenerationSchema::properties($schema) as $key=>$definition) {
            if (str_starts_with($key,'_') || in_array($key,self::OWNED,true)) continue;
            $type = $definition['type'] ?? 'string';
            if (!is_string($type) || !in_array($type,['string','integer','number','boolean','array','object'],true)) continue;
            $field = ['key'=>$key,'label'=>self::label($key,$definition),'type'=>$type,'description'=>(string)($definition['description'] ?? ''),'options'=>MarketGenerationSchema::choices($definition),'schema'=>self::annotate($definition),'required'=>MarketGenerationSchema::required($definition)];
            foreach (['minimum','maximum','minLength','maxLength','minItems','maxItems','default'] as $constraint) if (array_key_exists($constraint,$definition)) $field[$constraint]=$definition[$constraint];
            $field['applicable_modes'] = [];
            foreach ($modes as $mode) if (MarketGenerationSchema::applies($definition,(string)($mode['native_mode'] ?? ''))) $field['applicable_modes'][]=$mode['key'];
            $result[]=$field;
        }
        return $result;
    }
    private static function annotate(array $schema): array
    {
        foreach ((array)($schema['properties'] ?? []) as $key=>$child) if (is_array($child)) $schema['properties'][$key] = self::annotate($child) + ['title'=>self::label($key,$child)];
        if (isset($schema['items']) && is_array($schema['items'])) $schema['items']=self::annotate($schema['items']);
        return $schema;
    }
}
