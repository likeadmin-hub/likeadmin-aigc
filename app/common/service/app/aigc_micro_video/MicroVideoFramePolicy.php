<?php
namespace app\common\service\app\aigc_micro_video;
use InvalidArgumentException;

/** Pure shared-boundary and admission rules. No prices or URLs come from clients. */
final class MicroVideoFramePolicy
{
    public static function references(array $indices,int $imageCount,int $limit,bool $anchor): array
    {
        foreach($indices as $index)if(!is_int($index)&&!(is_string($index)&&ctype_digit($index)))throw new InvalidArgumentException('原始参考图片索引无效');
        $values=array_values(array_unique(array_map('intval',$indices)));sort($values);
        if(!$values||min($values)<0||max($values)>=$imageCount)throw new InvalidArgumentException('请选择有效的原始参考图片');
        if(count($values)+($anchor?1:0)>$limit)throw new InvalidArgumentException('所选参考图片与连续性锚图超过模型限制，请减少本帧引用图片或更换模型');
        return $values;
    }
    public static function definitions(array $task,array $segments,int $limit): array
    {
        $out=[];$n=count($segments);if(!$n)throw new InvalidArgumentException('请先完成视频方案');
        for($i=0;$i<=$n;$i++){
            $left=$i>0?$segments[$i-1]:null;$right=$i<$n?$segments[$i]:null;
            $refs=array_values(array_unique(array_merge(MicroVideoStore::json($left['image_indices']??'[]'),MicroVideoStore::json($right['image_indices']??'[]'))));
            if(count($refs)+($i>0?1:0)>$limit)throw new InvalidArgumentException('相关原始参考图片与连续性锚图超过模型限制，请调整方案引用或更换模型');
            $state=self::endpointState($segments,$i);
            $out[]=['boundary_index'=>$i,'reference_indices'=>$refs,'prompt'=>'静态视频边界画面，只表现一个明确时刻。当前端点：'.$state."\n".'连续性背景（仅提取人物与商品外观、空间、光线和镜头几何；其中其他时间点的动作不是本帧指令）：'.($task['continuity']??'')."\n".'局部衔接背景：'.($left['continuity']??$right['continuity']??'').'。保持原始参考中的人物身份、服装、商品结构颜色与可见事实；统一场景、光线、镜头方向、构图和主体比例。'.($i===0?'建立全片镜头几何、人物和商品的相对位置。':'继承上一张锚图的身份和镜头几何，只表现当前端点状态。').'只生成此刻静止画面，不添加未经证实的商品宣传或文字。'."\n".self::endpointInstructions($state)];
        }return $out;
    }
    private static function endpointState(array $segments,int $index): string
    {
        $segments=array_values($segments);$n=count($segments);if(!$n||$index<0||$index>$n)throw new InvalidArgumentException('当前首尾帧端点不存在');
        $left=$index>0?$segments[$index-1]:null;$right=$index<$n?$segments[$index]:null;
        return $left?('前段结束状态：'.$left['end_state'].($right?'；后段开始状态：'.$right['start_state']:'')):'首段起始状态：'.$right['start_state'];
    }
    private static function endpointInstructions(string $state): string
    {
        return '【本帧最终执行要求：当前端点优先于整片叙事、模板及参考图中的动作】'."\n".
            '全局模板、整片连续性、原始参考和上一锚图仅用于人物与商品外观、空间、光线和镜头几何。动作、手势、物品位置与当前端点不同时，一律以当前端点为准。'."\n".
            '不得提前执行其他时间点的动作，不得借用后续段落的拿取、举起或展示姿势；只有当前端点明确要求时，才允许对应的静态姿势。未指定的物品位置和手势延续锚图或原始参考，不补演后续剧情。'."\n".
            '下面是本帧唯一且最终的当前端点状态，逐项落实，只画这个静止瞬间：'."\n".$state;
    }
    public static function generationPrompt(array $frame,array $segments,string $template): string
    {
        $index=(int)$frame['boundary_index'];
        return $template."\n".$frame['prompt']."\n原始参考图按所选顺序排列。".($index>0?'最后一张为上一边界锚图，继承其镜头几何和身份；当前姿势和物品位置仍以本帧端点为准。':'')."\n".
            self::endpointInstructions(self::endpointState($segments,$index));
    }
    public static function links(array $segments,array $frames): array
    {
        $by=[];foreach($frames as $f)$by[(int)$f['boundary_index']]=(int)$f['id'];$out=[];
        foreach(array_values($segments) as $i=>$s){if(!isset($by[$i],$by[$i+1]))throw new InvalidArgumentException('首尾帧版本不完整');$out[(int)$s['id']]=['first_frame_id'=>$by[$i],'last_frame_id'=>$by[$i+1]];}return $out;
    }
    public static function ready(array $task,array $frames,int $segments): array
    {
        if(count($frames)!==$segments+1)throw new InvalidArgumentException('请先生成并确认所有首尾帧');
        foreach(array_values($frames) as $i=>$f)if((int)$f['task_id']!==(int)$task['id']||(int)$f['tenant_id']!==(int)$task['tenant_id']||(int)$f['user_id']!==(int)$task['user_id']||(int)$f['boundary_index']!==$i||empty($f['is_latest'])||$f['status']!=='success'||empty($f['image_uri'])||empty($f['confirmed']))throw new InvalidArgumentException('首尾帧已变化，请生成并重新确认');
        return $frames;
    }
    public static function identity(array $task,array $frames): string
    {
        return MicroVideoPolicy::fingerprint(['task'=>(int)$task['id'],'plan_revision'=>(int)$task['plan_revision'],'frames_revision'=>(int)($task['frames_revision']??0),'mode'=>$task['generation_mode']??'reference','model'=>$task['frame_model_id']??'','quality'=>$task['frame_quality']??'','frames'=>array_map(fn($f)=>[(int)$f['id'],(int)$f['version'],(string)($f['image_uri']??''),(string)($f['prompt_fingerprint']??''),(string)($f['dependency_fingerprint']??'')],$frames)]);
    }
    public static function assets(string $mode,array $uris,string $referenceMethod='image_reference'): array
    {
        if($mode==='start_end'&&count($uris)!==2)throw new InvalidArgumentException('首尾帧视频需要两个权威边界版本');
        $out=[];foreach(array_values($uris) as $i=>$uri)$out[]=['type'=>'image','uri'=>$uri,'url'=>$uri,'role'=>$mode==='start_end'?($i===0?'first_frame_image':'last_frame_image'):($referenceMethod==='image_to_video'?'first_frame_image':'reference_image')];return $out;
    }
    public static function dependentIndices(int $start,int $last): array{return $start>$last?[]:range($start,$last);}
}
