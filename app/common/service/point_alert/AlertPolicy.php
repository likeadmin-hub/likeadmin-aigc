<?php
namespace app\common\service\point_alert;

use InvalidArgumentException;

/** All balances are integer hundredths. This policy never changes an account balance. */
final class AlertPolicy
{
    public static function cents(mixed $value): int
    {
        $text = (string)$value;
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $text)) throw new InvalidArgumentException('点数需为非负数字，最多两位小数');
        [$whole, $part] = array_pad(explode('.', $text), 2, '');
        return (int)$whole * 100 + (int)str_pad($part, 2, '0');
    }
    public static function amount(int $cents): string { return sprintf('%d.%02d', intdiv($cents,100), $cents%100); }
    public static function normalize(array $p): array
    {
        $levels = $p['thresholds'] ?? ['1000','200','50','0'];
        if (!is_array($levels) || !$levels || count($levels)>10) throw new InvalidArgumentException('请设置1至10个提醒档位');
        $unique=[];
        foreach($levels as $level) $unique[self::cents($level)]=true;
        $levels=array_keys($unique); rsort($levels,SORT_NUMERIC);
        $r=['enabled'=>filter_var($p['enabled']??false,FILTER_VALIDATE_BOOLEAN), 'thresholds'=>array_map([self::class,'amount'],$levels)];
        foreach(['max_cycle'=>3,'max_daily'=>3,'min_interval_minutes'=>60,'repeat_hours'=>24] as $key=>$default){
            $v=$p[$key]??$default;
            if(filter_var($v,FILTER_VALIDATE_INT)===false || (int)$v<1 || (int)$v>($key==='min_interval_minutes'?10080:1000)) throw new InvalidArgumentException('提醒次数和间隔必须为有效正整数');
            $r[$key]=(int)$v;
        }
        $r['repeat_enabled']=filter_var($p['repeat_enabled']??false,FILTER_VALIDATE_BOOLEAN);
        if($r['repeat_enabled'] && $r['repeat_hours']*60<$r['min_interval_minutes']) throw new InvalidArgumentException('重复提醒间隔不能小于最短提醒间隔');
        $channels=$p['channels']??['in_app'];
        if(!is_array($channels)||array_diff($channels,['in_app','sms','email'])) throw new InvalidArgumentException('通知渠道无效');
        $r['channels']=array_values(array_unique(array_merge(['in_app'],$channels)));
        $r['level_channels']=[];
        foreach($r['thresholds'] as $level){
            $v=$p['level_channels'][$level]??$r['channels'];
            if(!is_array($v)||array_diff($v,['in_app','sms','email']))throw new InvalidArgumentException('档位通知渠道无效');
            $r['level_channels'][$level]=array_values(array_unique(array_merge(['in_app'],$v)));
        }
        $r['sms_template_id']=trim((string)($p['sms_template_id']??''));
        if(strlen($r['sms_template_id'])>100)throw new InvalidArgumentException('短信模板编号过长');
        // Only allow known template variables; identifiers/PII are not forwarded to providers.
        $vars=$p['sms_variables']??['name','points','threshold'];
        if(!is_array($vars)||array_diff($vars,['name','points','threshold','unit']))throw new InvalidArgumentException('短信模板变量无效');
        $r['sms_variables']=array_values($vars);
        return $r;
    }
    public static function decide(array $r,array $s,int $balance,int $now): array
    {
        $day=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        $s=array_replace(['cycle_no'=>1,'cycle_count'=>0,'day_count'=>0,'day_key'=>$day,'last_at'=>0,'last_threshold'=>null,'recovery_at'=>0],$s);
        if($s['day_key']!==$day){$s['day_key']=$day;$s['day_count']=0;}
        $threshold=null;foreach($r['thresholds'] as $level)if($balance<=self::cents($level))$threshold=$level;
        $highest=self::cents($r['thresholds'][0]);
        if($balance>$highest+max(1,(int)ceil($highest*.05))){
            if(!$s['recovery_at'])$s['recovery_at']=$now;
            if($s['cycle_count'] && $now-$s['recovery_at']>=600){$s['cycle_no']++;$s['cycle_count']=0;$s['last_threshold']=null;}
        } else $s['recovery_at']=0;
        $reason='ready';
        if(!$r['enabled'])$reason='disabled';
        elseif($threshold===null)$reason='recovered';
        elseif($s['cycle_count'] >= $r['max_cycle'])$reason='cycle_limit';
        elseif($s['day_count'] >= $r['max_daily'])$reason='daily_limit';
        elseif($s['last_at'] && $now-$s['last_at']<$r['min_interval_minutes']*60)$reason='interval';
        elseif($s['last_threshold']!==null && self::cents($threshold)>=self::cents($s['last_threshold']) && (!$r['repeat_enabled']||$now-$s['last_at']<$r['repeat_hours']*3600))$reason='covered';
        $send=$reason==='ready';
        if($send){$s['cycle_count']++;$s['day_count']++;$s['last_at']=$now;$s['last_threshold']=$threshold;}
        return ['send'=>$send,'reason'=>$reason,'threshold'=>$threshold,'state'=>$s];
    }
}
