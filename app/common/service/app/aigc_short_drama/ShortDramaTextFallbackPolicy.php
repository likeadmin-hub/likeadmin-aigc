<?php
namespace app\common\service\app\aigc_short_drama;

use app\common\service\power\MarketTextModelProviderException;

/** Candidate metadata comes exclusively from this tenant's saleable text catalogue. */
final class ShortDramaTextFallbackPolicy
{
    public const MAX_ATTEMPTS = 3;

    public static function allows(\Throwable $error): bool
    {
        if ($error instanceof MarketTextModelProviderException) {
            return $error->partialResult === null && ($error->requestNotSent() || $error->allowsModelFallback);
        }
        if ($error instanceof \app\common\service\power\TextSettlementPending) return false;
        // Only local availability checks and explicit before-generation rejection codes.
        return preg_match('/\\b(?:model_not_found|invalid_model|unsupported_model|model_unavailable)\\b|所选文本模型未上架或已不可用/i', $error->getMessage()) === 1;
    }

    public static function candidates(array $preferred, array $catalogue, array $params): array
    {
        if (!$preferred) return [];
        $result=[$preferred];
        if (!empty($params['_agent_tool_history']) || !empty($params['tools'])) return $result;
        $seen=[(string)($preferred['id']??'')=>true];
        $desired=max(1,(int)($params['model_config']['max_tokens']??$params['max_tokens']??8192));
        $input=(string)($params['system_prompt']??'').(string)($params['content']??'');
        foreach ($catalogue as $candidate) {
            $id=(string)($candidate['id']??'');
            if ($id==='' || isset($seen[$id]) || empty($candidate['enabled']) || ($candidate['model_type']??'')!=='text') continue;
            if (($candidate['protocol']??'')!==($preferred['protocol']??'') || empty($candidate['protocol'])) continue;
            if ((!empty($params['requires_vision']) || !empty($params['reference_images'])) && empty($candidate['supports_vision'])) continue;
            if (!self::notMoreExpensive($preferred,$candidate)) continue;
            // Unknown limits cannot prove that the identical input and output allowance fit.
            if ((int)($candidate['context_window']??0)<=0 || (int)($candidate['max_output_tokens']??0)<$desired) continue;
            try { if (ShortDramaPlanningBudget::capacity($input,$candidate)['output_capacity']<$desired) continue; }
            catch (\RuntimeException) { continue; }
            $seen[$id]=true;$result[]=$candidate;
            if (count($result)>=self::MAX_ATTEMPTS) break;
        }
        return $result;
    }

    private static function notMoreExpensive(array $preferred,array $candidate): bool
    {
        foreach (['input','output'] as $side) {
            $a=$preferred[$side]??[];$b=$candidate[$side]??[];
            foreach ([$a,$b] as $price) {
                foreach (['usage_unit_size','tenant_price','platform_price'] as $key)
                    if (!isset($price[$key]) || !is_numeric($price[$key]) || !is_finite((float)$price[$key]) || (float)$price[$key]<0) return false;
                if ((float)$price['usage_unit_size']<=0) return false;
                foreach (['cached_tenant_price','cached_platform_price'] as $cached)
                    if (isset($price[$cached]) && (!is_numeric($price[$cached]) || !is_finite((float)$price[$cached]) || (float)$price[$cached]<0)) return false;
            }
            foreach (['tenant_price','platform_price'] as $key) {
                if ((float)$b[$key]/$b['usage_unit_size']>(float)$a[$key]/$a['usage_unit_size']+1e-12) return false;
                if ($side==='input') {
                    $cached='cached_'.$key;
                    if ((float)($b[$cached]??$b[$key])/$b['usage_unit_size']>(float)($a[$cached]??$a[$key])/$a['usage_unit_size']+1e-12) return false;
                }
            }
        }
        return true;
    }
}
