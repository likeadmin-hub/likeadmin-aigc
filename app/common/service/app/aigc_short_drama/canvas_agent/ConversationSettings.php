<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\power\MarketTextModelRuntimeService;

/** Resolve preferences against the current tenant's real canvas model catalog.
 * No client-supplied model object, provider URL, price or secret is trusted.
 */
final class ConversationSettings
{
    public static function resolve(int $tenant,array $preferences): array
    {
        FeatureGate::assertEnabled($tenant);
        $settings=self::fromCatalog(AigcShortDramaService::canvasModelGroups($tenant),$preferences);
        // Validate the actual market identity/capabilities, not a display name.
        try {
            $model=MarketTextModelRuntimeService::resolveModel($tenant,$settings['reasoning_model']['id']);
        } catch (\Throwable $error) {
            throw new RuntimeException('REASONING_MODEL_UNAVAILABLE',0,$error);
        }
        $settings['reasoning_model']['supports_vision']=!empty($model['supports_vision']);
        $settings['reasoning_model']['market_input_sku_id']=(int)($model['market_input_sku_id']??0);
        $settings['reasoning_model']['market_output_sku_id']=(int)($model['market_output_sku_id']??0);
        return $settings;
    }

    /** Pure catalog projection, also used by contract tests. Not authorization alone. */
    public static function fromCatalog(array $groups,array $preferences): array
    {
        $mapping=['reasoning_model'=>'script_plan','image_model'=>'image','video_model'=>'video'];
        if (array_diff(array_keys($preferences),array_merge(array_keys($mapping),['generation_mode']))) throw new RuntimeException('INVALID_AGENT_PREFERENCES');
        $mode=$preferences['generation_mode']??'manual';
        if (!in_array($mode,['manual','auto'],true)) throw new RuntimeException('INVALID_GENERATION_MODE');
        $result=['generation_mode'=>$mode];
        foreach ($mapping as $field=>$key) {
            $wanted=$preferences[$field]??null;
            if ($wanted!==null && (!is_string($wanted) || strlen($wanted)>191)) throw new RuntimeException('INVALID_MODEL_SELECTION');
            if (array_key_exists($field,$preferences) && $wanted===null) throw new RuntimeException('INVALID_MODEL_SELECTION');
            $group=[];
            foreach ($groups as $candidate) if (($candidate['key']??'')===$key) {$group=$candidate;break;}
            if ($wanted===null) $wanted=(string)($group['default']??'');
            $selected=[];
            foreach ((array)($group['options']??[]) as $option) {
                $id=(string)($option['id']??$option['value']??'');
                if ($id!=='' && $id===$wanted && in_array($option['enabled']??true,[true,1,'1'],true)) {$selected=$option;break;}
            }
            if (!$selected) {
                if ($field==='reasoning_model' || $wanted!=='') throw new RuntimeException(strtoupper($field).'_UNAVAILABLE');
                $result[$field]=[];continue;
            }
            // Explicit public snapshot whitelist: never persist arbitrary
            // catalog metadata or caller-owned credentials/price overrides.
            $result[$field]=[
                'id'=>(string)($selected['id']??$selected['value']),
                'name'=>(string)($selected['name']??$selected['label']??''),
                'market_product_id'=>(int)($selected['market_product_id']??$selected['product_id']??0),
                'market_sku_id'=>(int)($selected['market_sku_id']??$selected['sku_id']??0),
                'model_code'=>(string)($selected['model_code']??''),
            ];
        }
        return $result;
    }
}
