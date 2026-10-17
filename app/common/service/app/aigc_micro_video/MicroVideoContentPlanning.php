<?php
namespace app\common\service\app\aigc_micro_video;

use InvalidArgumentException;

/** Content chooses the partition. Local validation enforces capabilities and explicit user clocks. */
final class MicroVideoContentPlanning
{
    public static function required(array $snapshot): bool
    {
        return (int)($snapshot['content_planning_version']??0)>=1;
    }

    public static function contract(array $params,array $supported,int $max,int $version=2): array
    {
        $allowed=array_values(array_unique(array_filter(array_map('intval',$supported),fn($d)=>$d>0&&$d<=120)));sort($allowed);
        $target=(int)($params['target_duration']??0);
        if(!$allowed||$target<1||$target>600||$max<1||$max>60)throw new InvalidArgumentException('目标时长或模型时长配置无效');
        $timing=($params['mode']??'theme')==='prompt'?self::timing((string)($params['prompt']??'')):['duration'=>null,'shots'=>[]];
        if($version<2&&$timing['duration']!==null){
            try{$possible=MicroVideoPolicy::durations($timing['duration'],$allowed,$max);}
            catch(InvalidArgumentException){throw new InvalidArgumentException('当前模型无法匹配提示词明确的'.$timing['duration'].'秒总时长，请调整提示词或更换模型');}
            if(array_sum($possible)!==$timing['duration'])throw new InvalidArgumentException('当前模型无法匹配提示词明确的'.$timing['duration'].'秒总时长，请调整提示词或更换模型');
        }
        $bounds=[];
        if($version>=2){
            $ceiling=min(600,$target+5);$reachable=[0=>0];
            // Calculate reachable totals only; never prescribe a segment count or duration vector.
            for($sum=0;$sum<=$ceiling;$sum++){
                if(!isset($reachable[$sum])||$reachable[$sum]>=$max)continue;
                foreach($allowed as $duration){$next=$sum+$duration;if($next>$ceiling)continue;$reachable[$next]=min($reachable[$next]??PHP_INT_MAX,$reachable[$sum]+1);}
            }
            $totals=array_values(array_filter(array_keys($reachable),fn($sum)=>$sum>=$target));sort($totals);
            if(!$totals)throw new InvalidArgumentException('当前模型和最大分段数无法达到目标时长或仅小幅超出，请调整目标时长或更换模型');
            $bounds=['minimum_total_duration'=>$target,'max_total_duration'=>max($totals),'allowed_total_durations'=>$totals,'preferred_total_duration'=>$totals[0]];
        }
        return $bounds+['target_duration'=>$target,'allowed_durations'=>$allowed,'max_segments'=>$max,'max_total_duration'=>600,
            'required_total_duration'=>$version<2?$timing['duration']:null,'source_duration'=>$timing['duration'],'source_timeline'=>$timing['shots'],
            'preserve_source_clocks'=>$version<2||$timing['duration']===($bounds['preferred_total_duration']??null),
            'output_schema'=>['prompt'=>'完整整片提示词','continuity'=>'整片主体与风格共同设定','segments'=>[
                ['title'=>'本段叙事或动作目的','duration'=>$allowed[0],'prompt'=>'本段完整画面与秒级镜头时间轴','image_indices'=>[0],
                    'start_state'=>'本段起始状态','end_state'=>'本段结束状态','continuity'=>'与相邻段的连续动作和一致性要求']
            ]], 'requirements'=>self::instructions($version)];
    }

    public static function instructions(int $version=2): string
    {
        return '本次采用内容驱动的智能分段。先理解用户内容、图片、对白、动作及叙事节奏，再选择自然的段落边界、段数和各段时长。'
            .'allowed_durations仅表示模型可调用的每段秒数，不是预设的分段数组；output_schema中的一个片段仅是字段示例，不规定段数或时长。'
            .'每段实际时长由本段内容决定且不超过模型单次能力；一个连贯剧情单元能在一段完成就合并镜头，不要把每个镜头或每句对白都拆成独立视频。需要分段时按叙事转折、对白句末或连续动作的合适节点划分，允许各段长短不同。'
            .($version>=2?'target_duration是本次整片目标时长，必须先结合主题、创作方向、参考图片和用户提示词规划足量的完整剧情，再决定各段长度。各段duration之和不得短于minimum_total_duration，必须属于allowed_total_durations，优先达到preferred_total_duration；仅为自然结束剧情或适配模型时长规格才可略长，最多上浮5秒，不得随意缩短。旧提示词中的整片秒数与目标不同时，以本次目标重新安排剧情和镜头。内容较少时围绕原主题和创作方向补充有意义的动作、展示和叙事，不得编造价格、功效或无依据事实；不得通过放慢动作、静止定格、重复展示、空镜或拖长对白凑时长。'
                :'target_duration是创作目标，不是必须填满的秒数；内容较短可以自然提前完成，不得为了凑时长、凑段数或填满模型上限而加慢动作、无意义静止、重复展示、空镜、拖长对白或新增无关情节。required_total_duration非空时是用户明确的整片时长，所有段duration之和必须等于它，优先于target_duration。')
            .'source_timeline是已有镜头参考；preserve_source_clocks为true时保留原镜头时间、内容顺序、对白和动作节奏，分段后只把各段局部镜头时钟换算为从0秒开始；为false时按本次目标重新编排具体镜头和合理内容，保留人物商品事实、故事意图和对白，不得把原有动作直接拉慢。'
            .'每段duration必须是allowed_durations中的JSON整数，禁止字符串和秒单位。总段数不得超过max_segments，总时长不得超过max_total_duration。'
            .'所有段保持同一人物、商品、空间与光线，用连续动作和运镜衔接；首尾帧是运动状态的约束，不要求在段首段尾停住或定格。'
            .'只输出指定JSON，每段完整提供提示词、实际图片索引、起止状态和衔接要求。若其他模板仍要求固定段数、均分或按预设durations填内容，以本次内容驱动规则为准。';
    }

    public static function validate(array $parsed,array $contract,int $imageCount,int $referenceLimit,bool $timeline): array
    {
        $segments=MicroVideoPolicy::segments((array)($parsed['segments']??[]),$contract['allowed_durations'],$imageCount,$referenceLimit,$contract['max_segments'],$timeline);
        $total=array_sum(array_column($segments,'duration'));
        if($total>$contract['max_total_duration'])throw new InvalidArgumentException('内容方案超过允许的整片时长，请精简内容后重新规划');
        if(isset($contract['minimum_total_duration'])&&($total<$contract['minimum_total_duration']||!in_array($total,$contract['allowed_total_durations'],true)))throw new InvalidArgumentException('内容方案未达到目标时长，请按目标时长重新规划完整剧情');
        if($contract['required_total_duration']!==null&&$total!==$contract['required_total_duration'])throw new InvalidArgumentException('内容方案必须保留提示词明确的'.$contract['required_total_duration'].'秒总时长，不能拉伸或填充');
        self::preserveClocks($segments,$contract);
        if(trim((string)($parsed['continuity']??''))==='')throw new InvalidArgumentException('方案缺少整片一致性设定');
        return $segments;
    }

    public static function validatePrompt(string $prompt,array $contract): void
    {
        $duration=$contract['preferred_total_duration']??$contract['required_total_duration']??$contract['target_duration'];
        MicroVideoTimeline::validate($prompt,$duration);
        self::preserveClocks([['prompt'=>$prompt,'duration'=>$duration]],$contract);
    }

    private static function preserveClocks(array $segments,array $contract): void
    {
        if($contract['source_timeline']&&($contract['preserve_source_clocks']??true)){
            $boundaries=[0.];$offset=0.;
            foreach($segments as $segment){
                preg_match_all('/^\h*(?:[-*#]+\h*)?[\[【]?-?\d+(?:\.\d+)?\h*(?:秒|s)?\h*[-–—~～至]\h*(\d+(?:\.\d+)?)/imu',$segment['prompt'],$ends);
                foreach($ends[1] as $end)$boundaries[]=$offset+(float)$end;
                $offset+=$segment['duration'];$boundaries[]=$offset;
            }
            foreach($contract['source_timeline'] as $shot)if(!array_filter($boundaries,fn($b)=>abs($b-$shot['end'])<0.00001))throw new InvalidArgumentException('内容分段改变了原镜头时间，请保留原镜头节奏后重新规划');
        }
    }

    private static function timing(string $prompt): array
    {
        $text=str_replace(["\r\n","\r"],"\n",trim($prompt));
        // Only explicit whole-work declarations, never prop numbers or a segment's own duration.
        $whole=preg_replace('/(?:本|该|每|上(?:一)?|下(?:一)?|第[0-9一二三四五六七八九十]+)段\h*(?:视频)?(?:总时长|时长|共)?\h*(?:为|是|：|:)?\h*\d+(?:\.\d+)?\h*(?:秒|s)/iu','',$text);
        preg_match_all('/(?:整片(?:总时长|时长)?|全片(?:总时长|时长)?|整体(?:总时长|时长)?|视频(?:总时长|时长)|总时长|整片共|全片共)\h*(?:为|是|：|:)?\h*(\d+(?:\.\d+)?)\h*(?:秒|s)/iu',$whole,$declared);
        preg_match_all('/(\d+(?:\.\d+)?)\h*秒(?:的)?(?:剧情)?(?:带货|商品|宣传|电商|口播)?(?:微视频|视频|短片|广告|宣传片)/u',$whole,$natural);
        $values=array_values(array_unique(array_map('floatval',array_merge($declared[1]??[],$natural[1]??[]))));
        if(count($values)>1)throw new InvalidArgumentException('提示词中整片总时长不一致，请先调整');
        preg_match_all('/^\h*(?:[-*#]+\h*)?[\[【]?(?<start>-?\d+(?:\.\d+)?)\h*(?:秒|s)?\h*[-–—~～至]\h*(?<end>-?\d+(?:\.\d+)?)\h*(?:秒|s)[\]】]?\h*[:：]\h*(?<body>[^\r\n]*)$/imu',$text,$rows,PREG_SET_ORDER);
        preg_match_all('/^\h*(?:[-*#]+\h*)?[\[【]?-?\d+(?:\.\d+)?\h*(?:秒|s)?\h*[-–—~～至]\h*-?\d+(?:\.\d+)?/imu',$text,$candidates);
        if(count($rows)!==count($candidates[0]))throw new InvalidArgumentException('提示词时间轴格式不完整，请先调整镜头时间');
        $end=0.;$offset=0.;$shots=[];
        foreach($rows as $row){
            $start=(float)$row['start'];$next=(float)$row['end'];
            if($start===0.&&$end>0){$offset+=$end;$end=0.;}
            if(abs($start-$end)>0.00001||$next<=$start||$start<0||trim($row['body'])==='')throw new InvalidArgumentException('提示词时间轴必须从0秒开始并连续，不能重叠、留空或倒序');
            $shots[]=['start'=>$offset+$start,'end'=>$offset+$next,'content'=>trim($row['body'])];$end=$next;
        }
        $timeline=$shots?$offset+$end:null;$declared=$values[0]??null;
        if($timeline!==null&&$declared!==null&&abs($timeline-$declared)>0.00001)throw new InvalidArgumentException('提示词整片总时长与镜头时间轴不一致，请先调整');
        $duration=$timeline??$declared;
        if($duration!==null){if($duration<1||$duration>600||abs($duration-round($duration))>0.00001)throw new InvalidArgumentException('提示词整片总时长需为1至600的整秒数，请调整提示词或更换模型');$duration=(int)round($duration);}
        return ['duration'=>$duration,'shots'=>$shots];
    }
}
