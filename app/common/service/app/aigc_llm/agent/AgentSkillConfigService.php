<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\model\app\aigc_llm\AigcLlmConfig;
use app\common\service\app\AppDisplayConfigService as Display;
use app\common\service\FileService;

/** Editable skill presentation and draft templates; executable tools stay fixed. */
final class AgentSkillConfigService
{
    private const DEFAULTS=[
        'fitting'=>['name'=>'AI 试衣','category'=>'图片','tool'=>'aigc_fitting','description'=>'将服装穿到指定模特身上，生成试衣效果图。','prompt'=>'使用 AI 试衣功能，帮我把服装图片中的衣服穿到模特身上，保留模特的脸、姿势和背景，生成一张试衣效果图。请先确认服装图和模特图，缺少素材时引导我补充。','materials'=>'需要服装图片和一张模特照片，可通过输入框的“添加素材”上传。请说明每张图片的用途。'],
        'image'=>['name'=>'图片创作','category'=>'图片','tool'=>'aigc_image','description'=>'根据画面描述或参考图创作图片。','prompt'=>'帮我创作图片，请根据我的用途和画面想法完善提示词，使用我选择的图片模型生成。','materials'=>'描述想要的画面；参考图片可选。'],
        'video'=>['name'=>'视频创作','category'=>'视频','tool'=>'aigc_video','description'=>'把文字创意或参考图片制作成视频。','prompt'=>'帮我创作视频，使用我选择的视频模型。请先确认画面内容、人物动作、镜头和时长；如果需要参考图，请引导我添加。','materials'=>'描述画面、动作和时长；图生视频需要参考图片。'],
        'music'=>['name'=>'音乐创作','category'=>'音频','tool'=>'aigc_music','description'=>'创作歌曲或纯音乐，可指定曲风和使用场景。','prompt'=>'帮我创作一段音乐，使用我选择的音乐模型。请先确认用途、曲风、时长，以及需要歌曲还是纯音乐。','materials'=>'描述音乐用途、曲风和时长；创作歌曲时可以提供歌词。'],
        'document'=>['name'=>'文档创作','category'=>'文本','description'=>'整理方案、流程或报告，并导出 Word 文档。','prompt'=>'帮我撰写一份结构清晰的文档，并导出 Word 文件。请先确认文档主题、用途和需要包含的内容。','materials'=>'说明文档主题和用途，也可以在输入框补充正文或资料。'],
        'script'=>['name'=>'剧本创作','category'=>'文本','description'=>'构思故事、人物、对白和分镜脚本。','prompt'=>'帮我创作剧本，请先确认题材、目标受众、时长和风格，再整理故事梗概、人物设定及分镜内容。','materials'=>'说明题材和创作目标；已有故事或人物设定可直接补充。'],
        'plan'=>['name'=>'创作方案','category'=>'文本','description'=>'把创意整理成清晰、可执行的创作方案。','prompt'=>'帮我制定一份创作方案，请先了解目标、受众和交付形式，再给出内容结构与执行步骤。','materials'=>'描述你的目标、受众和预期交付内容。']
    ];
    public static function detail(int $tenant): array
    {
        $config=AigcLlmConfig::where('tenant_id',$tenant)->findOrEmpty();
        $saved=$config->isEmpty()?[]:((array)($config->config_json['agent_skills']??[]));
        $items=[];$displays=[];
        foreach(self::DEFAULTS as $id=>$default){
            $app=$default['tool']??'aigc_llm';$display=$displays[$app]??=Display::detail($tenant,$app);
            $value=$saved[$id]??[];
            $item=['id'=>$id,'app_code'=>$app,'enabled'=>1,'sort'=>0]+$default;
            if(isset($default['tool'])){$item['name']=$display['title']?:$default['name'];$item['description']=$display['description']?:$default['description'];}
            foreach(['name','description','prompt','materials','enabled','sort'] as $field)if(array_key_exists($field,$value))$item[$field]=$value[$field];
            $item['images']=$value['images']??$display['skill_preview_images'];
            $item['videos']=$value['videos']??$display['skill_preview_videos'];
            $item['image_urls']=array_map(static fn($uri)=>FileService::getFileUrl($uri),$item['images']);
            $item['video_urls']=array_map(static fn($uri)=>FileService::getFileUrl($uri),$item['videos']);
            $item['cover_url']=$display['cover_url'];
            $items[]=$item;
        }
        return $items;
    }
    public static function normalize(int $tenant,mixed $rows): array
    {
        if(!is_array($rows)||count($rows)>count(self::DEFAULTS))throw new \InvalidArgumentException('技能配置格式不正确');
        $result=[];foreach(self::detail($tenant) as $item)$result[$item['id']]=array_intersect_key($item,array_flip(['name','description','prompt','materials','enabled','sort','images','videos']));
        $seen=[];
        foreach($rows as $row){
            if(!is_array($row)||!is_string($row['id']??null)||!isset(self::DEFAULTS[$row['id']])||isset($seen[$row['id']]))throw new \InvalidArgumentException('技能不存在或重复');
            $id=$row['id'];$seen[$id]=true;
            if(array_diff(array_keys($row),['id','name','description','prompt','materials','enabled','sort','images','videos']))throw new \InvalidArgumentException('不支持修改此技能设置');
            foreach(['name'=>80,'description'=>500,'prompt'=>4000,'materials'=>1000] as $field=>$limit)if(array_key_exists($field,$row)){
                if(!is_string($row[$field])||mb_strlen($row[$field])>$limit||in_array($field,['name','prompt'],true)&&trim($row[$field])==='')throw new \InvalidArgumentException('技能名称、提示词或说明格式不正确');
                $result[$id][$field]=trim($row[$field]);
            }
            if(array_key_exists('enabled',$row)){if(!in_array($row['enabled'],[0,1],true))throw new \InvalidArgumentException('技能状态无效');$result[$id]['enabled']=$row['enabled'];}
            if(array_key_exists('sort',$row)){if(!is_int($row['sort'])||abs($row['sort'])>9999)throw new \InvalidArgumentException('技能排序无效');$result[$id]['sort']=$row['sort'];}
            foreach(['images'=>5,'videos'=>3] as $field=>$limit)if(array_key_exists($field,$row))$result[$id][$field]=Display::normalizeSkillMedia($row[$field],$limit);
        }
        return $result;
    }
    public static function forTools(int $tenant,array $tools): array
    {
        return array_values(array_filter(self::detail($tenant),static fn($skill)=>!isset($skill['tool'])||in_array($skill['tool'],$tools,true)));
    }
}
