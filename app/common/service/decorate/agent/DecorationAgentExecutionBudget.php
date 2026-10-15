<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

final class DecorationAgentExecutionBudget
{
    // Queries and edits share the run budget. Two bounded decisions (e.g.
    // detailed contract discovery followed by a full-page edit) must fit.
    public const RUN_TOOLS = 40;

    public static function allowsNext(int $used): bool
    {
        return max(0,$used)<self::RUN_TOOLS;
    }

    public static function context(int $used): array
    {
        $used=max(0,$used);
        return ['run_tools'=>self::RUN_TOOLS,'decision_operations'=>20,
            'used_tools'=>$used,'remaining_tools'=>max(0,self::RUN_TOOLS-$used)];
    }

    public static function consume(int &$used,array &$context): bool
    {
        if(!self::allowsNext($used))return false;
        $used=max(0,$used)+1;
        $context['tool_calls']=$used;
        return true;
    }
}
