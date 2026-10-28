<?php
namespace app\common\service\app\aigc_short_drama;

/** Generation completion and editorial acceptance are independent outcomes. */
final class ShortDramaReviewOutcome
{
    /** Call only after the provider receipt is durable and settled. */
    public static function decode(array $response, array $request, ?callable $validate = null): array
    {
        try {
            $payload = ShortDramaStructuredResponse::decode($response);
            if ($validate) $validate($payload);
            return $payload;
        } catch (\RuntimeException $error) {
            if (self::enabled($request) && in_array($error->getCode(), [413,422], true)) {
                throw new ShortDramaReviewDeferred($error->getMessage(), [], null, $error->getCode());
            }
            throw $error;
        }
    }

    public static function enabled(array $request): bool
    {
        return (int)($request['_input_contract_version'] ?? $request['generation_settings']['input_contract_version'] ?? 0) >= 19
            || (int)($request['generation_settings']['review_policy_version'] ?? 0) >= 1;
    }

    public static function pending(array $plan, array $request, string $reason, array $issues = [], string $status = 'needs_review'): array
    {
        $plan['generation_settings']['review_policy_version'] = 1;
        $plan['script_review'] = ['version'=>1, 'status'=>$status, 'message'=>$reason,
            'issues'=>array_values($issues ?: [['message'=>$reason,'shot_ids'=>[]]])];
        // Do not expose an unverified episode's claims as the next episode's facts.
        $plan['_continuity'] = ['version'=>1, 'review_policy_version'=>1,
            'episode_number'=>(int)($request['episode_number'] ?? 1), 'summary'=>'剧本已生成，剧情复核尚未通过',
            'state'=>[], 'open_hooks'=>[], 'observed_events'=>[],
            'digest'=>ShortDramaContinuity::fingerprint($plan),
            'previous_digest'=>(string)($request['series_context']['continuity']['previous_digest'] ?? ''),
            'audit_status'=>'pending_review', 'warnings'=>[$reason],
            'content_status'=>'ready', 'dependency_status'=>'blocked'];
        return $plan;
    }

    public static function complete(array $plan, array $request): array
    {
        if (!self::enabled($request)) return $plan;
        $plan['generation_settings']['review_policy_version'] = 1;
        $plan['_continuity']['review_policy_version'] = 1;
        if (($plan['_continuity']['audit_status'] ?? '') !== 'verified') {
            return self::pending($plan, $request, '剧本已保存，仍有剧情疑点需要复核',
                array_map(static fn($message)=>['message'=>$message,'shot_ids'=>[]], (array)($plan['_continuity']['warnings'] ?? [])));
        }
        $plan['script_review'] = ['version'=>1, 'status'=>'verified', 'message'=>'剧情复核通过', 'issues'=>[]];
        return $plan;
    }

    /** Skipping optional review is not a failed review, and never claims verification. */
    public static function unreviewed(array $plan, array $request): array
    {
        $plan['generation_settings']['review_policy_version'] = 1;
        $plan['script_review'] = ['version'=>1,'status'=>'unreviewed','message'=>'剧本已生成，未开启自动剧情复核','issues'=>[]];
        $summary = trim(implode("\n", array_filter((array)($plan['script_lines'] ?? []), 'is_string')));
        if ($summary === '') $summary = trim((string)($plan['story_outline'] ?? ''));
        $ledger = (array)($plan['_continuity'] ?? []);
        $plan['_continuity'] = array_replace($ledger, [
            'version'=>1,'review_policy_version'=>1,'episode_number'=>(int)($request['episode_number'] ?? $ledger['episode_number'] ?? 1),
            'summary'=>mb_substr($summary ?: '以本集正文为准',0,2000),
            'state'=>$ledger['state'] ?? [],'open_hooks'=>$ledger['open_hooks'] ?? [],'observed_events'=>[],
            'digest'=>ShortDramaContinuity::fingerprint($plan),
            'previous_digest'=>(string)($request['series_context']['continuity']['previous_digest'] ?? $ledger['previous_digest'] ?? ''),
            'audit_status'=>'skipped','warnings'=>[],'content_status'=>'ready','dependency_status'=>'ready',
        ]);
        return $plan;
    }

    /** Read compatibility for early v19 opt-outs; never release an actual failed review or a different revision. */
    public static function compatibleLedger(array $ledger, array $plan): array
    {
        if (($ledger['review_policy_version'] ?? 0) >= 1
            && ($ledger['audit_status'] ?? '') === 'pending_review'
            && ($plan['script_review']['status'] ?? '') === 'unreviewed'
            && ($ledger['digest'] ?? '') === ShortDramaContinuity::fingerprint($plan)) {
            $plan['_continuity'] = $ledger;
            return self::unreviewed($plan, [])['_continuity'];
        }
        return $ledger;
    }

    public static function present(array $plan): array
    {
        if (!self::enabled($plan) || empty($plan['storyboard'])) return $plan;
        if (empty($plan['script_review'])) {
            $plan['script_review'] = ['version'=>1,'status'=>'unreviewed','message'=>'剧本已生成，尚未通过剧情复核','issues'=>[]];
        } elseif (($plan['script_review']['status'] ?? '') === 'verified'
            && ($plan['_continuity']['digest'] ?? '') !== ShortDramaContinuity::fingerprint($plan)) {
            $plan['script_review'] = ['version'=>1,'status'=>'needs_review','message'=>'剧本内容已修改，需要重新复核','issues'=>[]];
        }
        return $plan;
    }

    public static function blocksNext(array $plan): bool
    {
        $ledger = self::compatibleLedger((array)($plan['_continuity'] ?? []), $plan);
        return ($ledger['review_policy_version'] ?? 0) >= 1
            && !in_array($ledger['audit_status'] ?? '', ['verified','skipped'], true);
    }
}
