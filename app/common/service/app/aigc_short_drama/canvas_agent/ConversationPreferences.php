<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use think\facade\Db;

/**
 * Account-scoped Agent defaults.  They deliberately live outside a thread so
 * a new conversation starts with the same user-owned choices, while every
 * submitted run still freezes its own resolved snapshot.
 */
final class ConversationPreferences
{
    private const TABLE='aigc_short_drama_canvas_agent_preference';
    private const FIELDS=['reasoning_model','image_model','video_model','generation_mode'];

    public static function read(int $tenant,int $user): array
    {
        FeatureGate::assertEnabled($tenant);
        self::identity($tenant,$user);
        $row=Db::name(self::TABLE)->where(['tenant_id'=>$tenant,'user_id'=>$user])->find();
        if (!$row) return ['preferences'=>[],'revision'=>0];
        return ['preferences'=>self::decode((string)$row['preferences_json']),'revision'=>(int)$row['revision']];
    }

    /**
     * Resolve first against the live tenant catalog.  We persist only chosen
     * model identifiers and mode, never client-owned model objects, prices or
     * provider metadata.  `expectedRevision` gives separate browser sessions
     * a compare-and-swap boundary instead of silently overwriting each other.
     */
    public static function save(int $tenant,int $user,int $expectedRevision,array $preferences): array
    {
        FeatureGate::assertEnabled($tenant);
        self::identity($tenant,$user);
        if ($expectedRevision<0) throw new RuntimeException('INVALID_PREFERENCE_REVISION');
        $normalized=self::normalize($tenant,$preferences);
        return Db::transaction(function () use ($tenant,$user,$expectedRevision,$normalized): array {
            $scope=['tenant_id'=>$tenant,'user_id'=>$user];
            $row=Db::name(self::TABLE)->where($scope)->lock(true)->find();
            $actual=$row?(int)$row['revision']:0;
            if ($actual!==$expectedRevision) throw new RuntimeException('PREFERENCE_VERSION_CONFLICT');
            $now=time();
            if (!$row) {
                Db::name(self::TABLE)->insert($scope+['preferences_json'=>self::json($normalized),'revision'=>1,'create_time'=>$now,'update_time'=>$now]);
                return ['preferences'=>$normalized,'revision'=>1];
            }
            $revision=$actual+1;
            $changed=Db::name(self::TABLE)->where(['id'=>(int)$row['id'],'revision'=>$actual])->update(['preferences_json'=>self::json($normalized),'revision'=>$revision,'update_time'=>$now]);
            if ($changed!==1) throw new RuntimeException('PREFERENCE_VERSION_CONFLICT');
            return ['preferences'=>$normalized,'revision'=>$revision];
        });
    }

    private static function normalize(int $tenant,array $preferences): array
    {
        if (array_diff(array_keys($preferences),self::FIELDS)) throw new RuntimeException('INVALID_AGENT_PREFERENCES');
        // The existing resolver is the model-availability authority.  It also
        // keeps image/video optional for text-only tenants.
        $resolved=ConversationSettings::resolve($tenant,$preferences);
        $result=[];
        foreach (['reasoning_model','image_model','video_model'] as $field) {
            $id=(string)($resolved[$field]['id']??'');
            if ($id!=='') $result[$field]=$id;
        }
        $result['generation_mode']=(string)$resolved['generation_mode'];
        return $result;
    }

    private static function decode(string $json): array
    {
        try {$value=json_decode($json,true,32,JSON_THROW_ON_ERROR);} catch (\Throwable) {return [];}
        if (!is_array($value) || array_diff(array_keys($value),self::FIELDS)) return [];
        $result=[];
        foreach (['reasoning_model','image_model','video_model'] as $field) {
            if (!array_key_exists($field,$value)) continue;
            if (!is_string($value[$field]) || $value[$field]==='' || strlen($value[$field])>191) return [];
            $result[$field]=$value[$field];
        }
        if (array_key_exists('generation_mode',$value)) {
            if (!in_array($value['generation_mode'],['manual','auto'],true)) return [];
            $result['generation_mode']=$value['generation_mode'];
        }
        return $result;
    }

    private static function identity(int $tenant,int $user): void
    {
        if ($tenant<=0 || $user<=0) throw new RuntimeException('CANVAS_NOT_FOUND');
    }
    private static function json(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
}
