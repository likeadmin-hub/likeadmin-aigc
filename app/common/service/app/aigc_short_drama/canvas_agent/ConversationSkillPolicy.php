<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/** Agent Skills may shape creative output but cannot override platform-owned
 * billing, model authorization, tenancy, safety, or execution boundaries. */
final class ConversationSkillPolicy
{
    public static function assertSafe(array $snapshot): void
    {
        $definition=(array)($snapshot['definition']??[]);
        $text=self::flatten($definition);
        // These are command-pattern checks, deliberately applied only to the
        // selected Skill definition. User/node material remains untrusted data
        // in the frozen context and is never treated as a policy override.
        $patterns=[
            '/绕过\s*(积分|计费|安全|审核|权限|租户)/u',
            '/(?:使用|切换|允许).{0,16}任意模型/u',
            '/(?:ignore|bypass).{0,40}(?:billing|credit|safety|moderation|tenant|permission)/i',
            '/(?:any|unrestricted).{0,20}model/i',
        ];
        foreach ($patterns as $pattern) if (preg_match($pattern,$text)===1) throw new RuntimeException('SKILL_POLICY_UNSAFE');
        $policy=(array)($snapshot['execution_policy']??[]);
        if (!empty($policy['bypass_confirmation']) || !empty($policy['bypass_billing']) || !empty($policy['allow_unrestricted_models'])) throw new RuntimeException('SKILL_POLICY_UNSAFE');
    }

    private static function flatten(mixed $value): string
    {
        if (is_string($value)) return $value;
        if (!is_array($value)) return '';
        return implode("\n",array_map([self::class,'flatten'],$value));
    }
}
