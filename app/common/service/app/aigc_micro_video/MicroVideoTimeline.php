<?php
namespace app\common\service\app\aigc_micro_video;

use InvalidArgumentException;

/** Local shot clocks inside a single provider generation; no billing or I/O. */
final class MicroVideoTimeline
{
    public static function required(array $snapshot): bool
    {
        return (int)($snapshot['shot_timeline_version']??0)>=1;
    }

    public static function instructions(bool $planning=true): string
    {
        return '镜头时间轴是必填要求。一个生成片段内必须包含多个镜头，不能用一个整段画面描述代替。每个片段独立从0秒开始，按镜头顺序逐行写成“0-3秒：画面、动作、景别和运镜”“3-6秒：承接上一镜头的画面……”的格式；示例时间仅表示格式，须按该片段实际时长编排。每行必须有具体画面描述，至少两个镜头，各时间范围首尾相接、不能重叠或留空，最后一个镜头必须恰好结束于本片段duration秒。通常每个镜头约2至5秒，短片段可更短。下一片段重新从0秒计时。共同主体、服装、商品结构颜色、场景、光线、运动方向保持稳定，后镜头承接前镜头结束状态，用连续运镜与动作自然衔接，不要每个镜头换场景或风格。共同设定可写在时间轴前，不要在时间轴正文额外重复示例。'.($planning?'只在对应prompt字段内输出时间轴正文，不新增JSON字段。':'本次写整片提示词，将目标时长视为整片时间轴的终点，只返回正文。');
    }

    public static function validate(string $prompt, int $duration): void
    {
        // Keep ranges line-based: numbers in scene descriptions are not shot boundaries.
        preg_match_all('/^\h*(?:[-*#]+\h*)?[\[【]?(?<start>-?\d+(?:\.\d+)?)\h*(?:秒|s)?\h*[-–—~～至]\h*(?<end>-?\d+(?:\.\d+)?)\h*(?:秒|s)[\]】]?\h*[:：]\h*(?<body>[^\r\n]*)$/imu', trim($prompt), $rows, PREG_SET_ORDER);
        $valid=$duration>0&&count($rows)>=2;$end=0.;
        foreach($rows as $row){
            $start=(float)$row['start'];$next=(float)$row['end'];
            if(abs($start-$end)>0.00001||$next<=$start||$start<0||$next>$duration||trim($row['body'])==='')$valid=false;
            $end=$next;
        }
        if(!$valid||abs($end-$duration)>0.00001)throw new InvalidArgumentException('镜头时间轴无效：请从0秒开始，连续覆盖本段时长，至少编排两个镜头，不能重叠、留空或超时');
    }
}
