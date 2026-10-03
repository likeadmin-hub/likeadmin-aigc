<?php
namespace app\common\service;

/** OEM presentation defaults, based on the owner's selected Higgsfield reference sections. */
class OfficialSiteOemTemplate
{
    public static function modules(): array
    {
        $asset = static fn($name) => '/pc/oem-enterprise/' . $name;
        $block = static fn($key, $title, $description = '') => ['key'=>$key, 'enabled'=>1, 'sort'=>0, 'title'=>$title, 'description'=>$description, 'cards'=>[]];
        $clients = [
            [2, '制作广泛传播的混合现实营销作品', 'ESPN · Vertex CGI'],
            [5, '麦当娜和杜嘉班纳的创意总监', 'Dolce & Gabbana · Sasha Kasiuha'],
            [1, '为 Netflix 和 Hulu 创作的制作工作室', 'Boxel Studio'],
            [6, '为 Lacoste 和 SuperStep 创作的制作工作室', 'Lacoste · Sinecera'],
            [3, '专注于体育和娱乐的 AI 工作室', 'One of One'],
            [4, '韩国领先的创意工作室', 'AdQUA'],
        ];
        $features = [
            [1, '50+ 款模型', '一站式套件，汇集 50+ 款模型', '平台涵盖视频、图像和音频模型，以及专为规模化创意生产打造的工具。', 'image'],
            [5, '营销工作室', '扩大您的营销产出', '利用预设的钩子和设置，为任何产品或服务创建用户生成内容（UGC）广告和宣传片。为您的营销部门提供完全的灵活性。', 'marketing'],
            [3, '电影制片厂', '为您的制作工作室提供完整解决方案', '借助电影工作室，精准控制摄影机、镜头和光线，塑造每一个镜头。', 'cinema'],
            [2, 'MCP 和 CLI', '用 MCP 和 CLI 增强您的工作流程', '将企业工作空间与 Claude、ChatGPT、Figma 等现有企业解决方案无缝连接。', 'mcp'],
            [6, '代理引擎', '智能设计，专业开发', '通过技能、连接器和自动化，构建、生成并推广您的创意。', 'image'],
            [7, '画布', '一张画布，连接所有创作', '让整个团队在同一张节点画布中，有条理地连接素材和工作流程。', 'image'],
            [4, '角色工作室', '创建专属角色、吉祥物和品牌形象', '上传不同角度的照片，在新的图片和视频中保持角色一致。', 'image'],
        ];
        return [
            array_merge($block('oem_hero', "专为企业打造的\nAI原生创意套件", '财富500强企业中已有390家与我们合作。'), [
                'eyebrow'=>'OEM贴牌', 'media'=>'', 'media_type'=>'video', 'poster'=>'',
                'icon_url'=>$asset('suite-mark.svg'), 'badge'=>$asset('fortune-logo.png'),
            ]),
            array_merge($block('oem_intro', '专为现代创意企业量身打造', '获取平台个性化演示，查看真实用例，了解领先企业如何将制作时间缩短 90%，同时在每份内容上节省数千美元。'), ['button_text'=>'联系销售', 'button_link'=>'/official/oem#oem-packages']),
            array_merge($block('oem_clients', '已有行业领先企业加入'), ['eyebrow'=>'企业客户', 'cards'=>array_map(static fn($row) => [
                'title'=>$row[1], 'description'=>$row[2], 'media'=>'', 'media_type'=>'image',
                'icon_url'=>$asset('logo-primary-'.$row[0].'.webp'),
                'secondary_icon_url'=>$row[0]===1?'':$asset('logo-secondary-'.$row[0].'.webp'),
            ], $clients)]),
            array_merge($block('oem_features', '创意能力'), ['cards'=>array_map(static function($row) use ($asset) {
                return ['tab_label'=>$row[1], 'title'=>$row[2], 'description'=>$row[3], 'preview_layout'=>$row[4],
                    'media'=>'', 'media_type'=>'image',
                    'background_media'=>'', 'background_media_type'=>'image',
                    'preview_media'=>'', 'preview_poster'=>'', 'preview_media_type'=>'video',
                    'icon_url'=>$row[4]==='mcp'?$asset('mcp-pill.png'):'',
                ];
            }, $features)]),
            array_merge($block('oem_packages', '选择适合您的贴牌套餐', '以您的品牌开启 AI 创作业务。'), ['eyebrow'=>'OEM PACKAGES', 'button_text'=>'立即开通']),
        ];
    }
}
