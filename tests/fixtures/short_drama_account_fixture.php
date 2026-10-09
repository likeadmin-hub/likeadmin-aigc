<?php
namespace Tests\Fixtures;

use app\common\service\power\MarketTextModelRuntimeService as Runtime;

/** Recorded text model and private ledgers only; never contacts a provider. */
final class ShortDramaAccountFixture
{
    public static function call(string $method, ...$args)
    {
        $reflection = new \ReflectionMethod(Runtime::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, ...$args);
    }

    public static function model(): array
    {
        $sku = ['sku_id'=>0, 'platform_price'=>1, 'tenant_price'=>2, 'usage_unit_size'=>1000];
        return ['product_id'=>0, 'model_code'=>'account_fixture', 'channel_code'=>'fixture',
            'protocol'=>'openai_chat', 'billing_unit_size'=>1000, 'input'=>$sku, 'output'=>$sku];
    }

    public static function reserve(int $tenant, int $user, string $key, int $parent = 0, int $attempt = 1, int $business = 123): array
    {
        return self::call('createConsumption', $tenant, $user, self::model(), 'script_plan',
            ['durable_receipt_version'=>1, 'account_reservation'=>['version'=>1, 'request_key'=>preg_match('/^[a-f0-9]{64}$/D',$key)?$key:hash('sha256',$key),
                'attempt'=>$attempt, 'state'=>'prepared', 'upper_bound'=>['total_tokens'=>4000,'tenant_cost_points'=>4,'user_charge_points'=>8]]],
            ['tenant_cost_points'=>4,'user_charge_points'=>8], $parent, 'aigc_short_drama', 'aigc_short_drama_script_task', $business);
    }

    public static function receipt(array $context, int $input = 500, int $output = 500): array
    {
        return ['durable_receipt_version'=>1,'content'=>'完整正文及最后一句。','content_source'=>'content','_require_final_content'=>true,
            'app_task_id'=>(int)$context['appTask']['id'], 'consumption_id'=>(int)$context['consumption']['id'],
            'model_code'=>'account_fixture','usage'=>['prompt_tokens'=>$input,'completion_tokens'=>$output],
            'provider_request_id'=>'fixed_account_receipt'];
    }
}
