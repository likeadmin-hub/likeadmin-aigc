<?php

namespace app\common\service\app\aigc_short_drama;

/** Versioned product defaults packaged with the short-drama app, never tenant data. */
final class ShortDramaBuiltinSkillCatalog
{
    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return [[
            'skill_key' => 'product_promo_short',
            'name' => '商品宣传短片',
            'description' => '将产品卖点、包装细节、真实使用场景与品牌记忆点组织成节奏鲜明的商品宣传短片。优先复用用户提供的产品素材，在不改变产品外观的前提下完成剧本、分镜和媒体生成。',
            'invocation_rule' => '适用于新品发布、商品种草、功能演示、电商详情页、品牌社媒投放等需要突出产品外观、核心卖点、使用场景和行动引导的短片需求。',
            'category_names' => ['商业广告', '产品展示'],
            // The public cover URL is intentionally detached from any tenant-file ID.
            'cover_url' => 'https://opcaigclikeadmin.oss-cn-shenzhen.aliyuncs.com/uploads/video/20260916/2026091610500831cfc7189.mp4',
            'cover_type' => 'video',
            'definition' => [
                'stages' => [
                    'workflow' => '先汇总产品名称、包装/外观参考、核心卖点、目标人群、投放场景、成片时长与比例，输出《商品宣传创作规格》并请用户确认。随后依次完成素材绑定、广告分镜与媒体生成；剧本、素材绑定、分镜均需在用户确认后再进入下一阶段。始终保留产品与卖点的可追溯引用，不执行任何视频剪辑或导出规则。',
                    'asset_analysis' => '优先检索当前用户可见的上传素材、主体、场景和项目资产。若存在产品包装图、Logo、产品视频、人物或场景参考，先分析并绑定为可复用资产，明确其对应的镜头位置；不得重复生成已提供且可直接使用的产品外观素材。提取并锁定产品轮廓、材质、颜色、Logo 位置、包装文字、尺寸比例和不可改变的品牌元素；缺少必要产品参考时，明确提示风险并让用户补充。',
                    'storyboard' => '以“产品主角展示 + 卖点佐证 + 品牌收束”设计商业短片。至少包含一个清晰产品英雄镜头、两个与核心卖点对应的使用或细节镜头，以及一个干净的品牌/行动引导收束镜头。镜头之间应有明确视觉变化，交替安排全景建立、中景使用、微距细节与产品高潮镜头；每个镜头标明主体/场景/道具引用、画面内容、景别、机位、运镜、动作、台词或屏幕文字、时长和卖点。产品外观、包装与 Logo 在所有镜头中保持一致；若镜头不展示产品，必须说明它在支撑哪个卖点或氛围。',
                    'media_generation' => '先按分镜生成或复用产品、场景、道具等参考资产，再依据每个镜头的绑定资产生成图片和视频。产品作为镜头重点时，优先使用产品参考图并避免改写品牌标识、包装文字、主色和材质；所有视频镜头都应引用其所需的产品/场景/道具资产。按已确认分镜的顺序生成，保持规定比例与时长，必要时生成独立背景音乐或旁白；媒体任务仍必须使用系统已配置且可用的模型、队列、审核和积分链路。',
                    'prompt_writing' => '提示词使用用户当前语言；旁白和画面内文字遵循已确认的输出语言。图像提示词只描述可见的产品、动作、空间、构图、镜头和光线，突出准确材质、包装细节、品牌主色与高质量商业摄影质感，避免虚构或篡改品牌元素。视频提示词按“镜头运动 → 产品/人物动作 → 空间变化 → 声音”顺序描述，动作按发生顺序表达，不写不可靠的逐秒指令。若有独立旁白，不在视频提示词重复旁白；默认避免无关背景音乐和自动字幕，画面内文字仅在已确认需要时用精确文本描述。',
                ],
                'positive_examples' => ['为一款无线耳机做 15 秒新品宣传短片', '用产品图生成咖啡机的电商卖点视频', '为护肤品设计社媒广告短片'],
                'negative_examples' => ['纯剧情短剧', '人物访谈纪录片', '与商品无关的风景片'],
                'keywords' => ['商品宣传', '产品宣传', '新品发布', '产品广告', '电商短片', '功能演示', '品牌宣传', '种草视频'],
                'required_slots' => [
                    ['key' => 'product_name', 'label' => '产品名称', 'ask' => '请补充产品名称或 SKU，方便保持包装与文案一致。'],
                    ['key' => 'product_features', 'label' => '核心卖点', 'ask' => '请补充 1–3 个最需要被看见的产品卖点。'],
                    ['key' => 'target_audience', 'label' => '目标人群', 'ask' => '请补充目标用户或使用场景。'],
                    ['key' => 'duration', 'label' => '成片时长', 'ask' => '请补充期望成片时长，例如 15 秒、30 秒。'],
                    ['key' => 'ratio', 'label' => '画面比例', 'ask' => '请补充画面比例，例如 9:16、16:9 或 1:1。'],
                ],
                'model_policy' => [],
                'execution_policy' => [],
                'output_policy' => ['required' => ['剧本', '主体设定', '场景设定', '分镜', '图像', '视频', '音频']],
            ],
            'model_policy' => ['text_models' => [], 'image_models' => [], 'video_models' => [], 'audio_models' => [], 'allowed_ratios' => ['9:16', '16:9', '1:1'], 'default_ratio' => '9:16', 'min_duration' => '3', 'max_duration' => '30', 'allow_audio' => false],
            'execution_policy' => ['allow_auto_complete' => false, 'require_confirmation' => ['script', 'assets', 'storyboard']],
            'home_recommended' => 1,
            'sort' => 1000,
        ]];
    }
}
