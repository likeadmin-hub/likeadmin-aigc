<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

final class DecorationAgentExecutionBudget
{
    // Legacy frozen runs retain their original cumulative allowance.
    public const RUN_TOOLS = 40;

    public static function allowsNext(int $used,bool $unlimited=false): bool
    {
        return $unlimited||max(0,$used)<self::RUN_TOOLS;
    }

    public static function context(int $used,bool $unlimited=false): array
    {
        $used=max(0,$used);
        return ['run_tools'=>$unlimited?null:self::RUN_TOOLS,'decision_operations'=>20,
            'used_tools'=>$used,'remaining_tools'=>$unlimited?null:max(0,self::RUN_TOOLS-$used)];
    }

    public static function consume(int &$used,array &$context,bool $unlimited=false): bool
    {
        if(!self::allowsNext($used,$unlimited))return false;
        $used=max(0,$used)+1;
        $context['tool_calls']=$used;
        return true;
    }
}
