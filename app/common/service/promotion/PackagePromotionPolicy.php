<?php
declare(strict_types=1);
namespace app\common\service\promotion;

/** Pure pricing rules; caller supplies the authoritative clock, identity and claim. */
final class PackagePromotionPolicy
{
    public static function cents(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',(string)$value)) {
            throw new \InvalidArgumentException('金额格式不正确，请填写最多两位小数的非负金额');
        }
        $parts=explode('.',(string)$value);return (int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
    }
    public static function amount(int $cents): string
    {
        if($cents<0)throw new \InvalidArgumentException('金额不能小于零');
        return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }
    public static function validate(int $regular,int $discount,int $hours,int $new,int $all): void
    {
        if($discount<=0||$discount>=$regular)throw new \InvalidArgumentException('优惠价格必须大于零且低于常规售价');
        if($hours<1||$hours>8760)throw new \InvalidArgumentException('优惠时长请填写1到8760小时');
        if(!$new&&!$all)throw new \InvalidArgumentException('请至少选择一种优惠规则');
    }
    public static function window(array $old,int $now,int $hours,bool $restart): array
    {
        if(!$restart&&(int)($old['all_start']??0)>0)return ['all_start'=>(int)$old['all_start'],'all_end'=>(int)$old['all_end']];
        return ['all_start'=>$now,'all_end'=>$now+$hours*3600];
    }
    public static function quote(array $p,int $regular,int $registered,int $now,string $claim): array
    {
        $result=['regular_cents'=>$regular,'payable_cents'=>$regular,'discount_cents'=>0,'is_discount'=>false,'matched_rules'=>[],'eligible_end_at'=>0,'reason'=>'regular'];
        if(!(int)($p['enabled']??0))return $result;
        if(in_array($claim,['used','reserved'],true)){ $result['reason']=$claim;return $result; }
        $discount=(int)($p['discount_cents']??0);$hours=(int)($p['hours']??0);
        if($discount<=0||$discount>=$regular||$hours<1)return $result;
        $ends=[];
        if((int)($p['rule_new']??0)&&$registered>0&&$registered<=$now&&$now<$registered+$hours*3600){$result['matched_rules'][]='new_user';$ends[]=$registered+$hours*3600;}
        if((int)($p['rule_all']??0)&&(int)($p['all_start']??0)>0&&$now>=(int)$p['all_start']&&$now<(int)($p['all_end']??0)){$result['matched_rules'][]='all_users';$ends[]=(int)$p['all_end'];}
        if(!$ends)return $result;
        return array_replace($result,['payable_cents'=>$discount,'discount_cents'=>$regular-$discount,'is_discount'=>true,'eligible_end_at'=>max($ends),'reason'=>'discount']);
    }
}
