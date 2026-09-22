<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use app\common\service\app\aigc_short_drama\ShortDramaSkillService;
use think\facade\Db;

/** Explicit tenant opt-in; absent configuration never enables Agent writes. */
final class FeatureGate
{
    private static function truthy(mixed $value): bool
    {
        return in_array($value,[true,1,'1','true'],true);
    }

    private static function config(int $tenant): array
    {
        if ($tenant<=0) return [];
        $row=Db::name('aigc_short_drama_config')->where('tenant_id',$tenant)->find();
        if (!$row || (int)$row['status']!==1) return [];
        $config=json_decode((string)$row['config_json'],true);
        return is_array($config) ? $config : [];
    }

    /** Product-approved P2 default: both directions are checked, rejects are
     * terminal, audit records retain only a digest for 30 days, and no manual
     * review queue is created. */
    public static function defaultSafetyPolicy(): array
    {
        return ['version'=>ConversationSafety::POLICY_VERSION,'input_review'=>true,'output_review'=>true,
            'action'=>'reject','audit_retention_days'=>30,'manual_review'=>false,'blocked_terms'=>[]];
    }

    /** Accept only a small tenant-scoped rule set; provider identities never
     * enter tenant configuration. */
    public static function normalizeSafetyPolicy(mixed $value): array
    {
        $policy=self::defaultSafetyPolicy();
        if (!is_array($value)) return $policy;
        $terms=[];
        foreach ((array)($value['blocked_terms']??[]) as $term) {
            if (!is_string($term)) continue;
            $term=trim($term);
            if ($term!=='' && mb_strlen($term,'UTF-8')<=100) $terms[$term]=true;
            if (count($terms)>=100) break;
        }
        $policy['blocked_terms']=array_keys($terms);
        return $policy;
    }

    public static function safetyPolicy(int $tenant): array
    {
        return self::normalizeSafetyPolicy((array)((self::config($tenant)['canvas_agent']??[])['safety']??[]));
    }

    public static function enabled(int $tenant): bool
    {
        $config=self::config($tenant);
        if ($config===[]) return false;
        // Agent conversation is part of the short-drama canvas by default.
        // Existing tenants can still explicitly disable it in tenant admin.
        return !array_key_exists('enabled',(array)($config['canvas_agent']??[]))
            || self::truthy($config['canvas_agent']['enabled']);
    }

    /** A separate, explicit opt-in is required before a Worker can spend
     * tenant/user credits by invoking the configured text model. */
    public static function executionEnabled(int $tenant): bool
    {
        $agent=(array)(self::config($tenant)['canvas_agent']??[]);
        return self::truthy($agent['enabled']??false)
            && self::truthy($agent['execution_enabled']??false);
    }
    /** Platform catalogues are fixed in source.  A tenant may only opt in to
     * an approved key, never upload a replacement workflow definition. */
    public static function workflowEnabled(int $tenant,string $key): bool
    {
        if (!self::enabled($tenant) || $key!==ConversationWorkflow::KEY) return false;
        $workflow=(array)((self::config($tenant)['canvas_agent']??[])['workflow']??[]);
        if (array_key_exists('enabled',$workflow) && !self::truthy($workflow['enabled'])) return false;
        $allowed=$workflow['enabled_workflows']??[];
        if ($allowed===[] || $allowed===null) return true;
        return is_array($allowed) && in_array($key,array_map('strval',$allowed),true);
    }

    /**
     * Tenant configuration may select published Skill versions for a
     * platform-defined stage, but it cannot add stages or relax workflow
     * rules. Resolution and policy validation happen again when a thread is
     * created, before any snapshot is persisted.
     *
     * @return array<string,list<array{skill_id:int,skill_version:int}>>
     */
    public static function workflowStageSkillSelections(int $tenant): array
    {
        $agent=(array)(self::config($tenant)['canvas_agent']??[]);
        $workflow=(array)($agent['workflow']??[]);
        $raw=(array)($workflow['stage_skills']??[]);
        $result=[];
        foreach ($raw as $stage=>$items) {
            if (!is_string($stage) || !preg_match('/^[a-z_]{2,64}$/D',$stage) || !is_array($items) || !array_is_list($items)) continue;
            $seen=[];
            foreach (array_slice($items,0,4) as $item) {
                if (!is_array($item)) continue;
                $id=(int)($item['skill_id']??0);$version=(int)($item['skill_version']??0);
                if ($id<=0 || $version<=0 || isset($seen[$id.':'.$version])) continue;
                $seen[$id.':'.$version]=true;
                $result[$stage][]=['skill_id'=>$id,'skill_version'=>$version];
            }
        }
        // An empty tenant setting means “use the platform baseline”, not “run
        // with no Skill instructions”.  Non-empty tenant selections remain
        // authoritative and are validated again when the conversation is
        // frozen. This lets the tenant manager persist a custom version while
        // keeping a newly enabled workflow immediately executable.
        foreach (ShortDramaSkillService::workflowDefaultSelections($tenant) as $stage=>$defaults) {
            if (empty($result[$stage])) $result[$stage]=$defaults;
        }
        return $result;
    }
    public static function assertEnabled(int $tenant): void
    {
        if (!self::enabled($tenant)) throw new RuntimeException('CANVAS_AGENT_DISABLED');
    }
}
