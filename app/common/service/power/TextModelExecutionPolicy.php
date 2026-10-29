<?php
declare(strict_types=1);
namespace app\common\service\power;
final class TextModelExecutionPolicy
{
    public function __construct(public readonly string $appCode, public readonly string $expectedModelFingerprint) {}
    public static function fingerprint(array $model): string
    {
        $keys=['id','product_id','model_code','channel_code','protocol','default_params','input','output','billing_unit_size','max_output_tokens','context_window'];
        $data=array_intersect_key($model,array_flip($keys));
        $sort=function(&$value) use (&$sort): void {if(!is_array($value))return;ksort($value);foreach($value as &$v)$sort($v);};
        $sort($data);
        return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
    }
    public function assertModel(array $model,string $appCode): void
    {
        if($this->appCode!=='aigc_copywriting'||$appCode!==$this->appCode||!hash_equals($this->expectedModelFingerprint,self::fingerprint($model))) {
            throw new \InvalidArgumentException('MODEL_OR_PRICE_CHANGED');
        }
    }
    public function generationParams(array $model,array $params): array
    {
        // App-scoped hard opt-out, after market defaults and before serialization.
        // Do not silently retry by dropping these controls or changing the model.
        $code=strtolower((string)($model['model_code']??''));
        $protocol=(string)($model['protocol']??'openai_chat');
        $declared=$params;
        foreach (['enable_thinking','thinking','reasoning','reasoning_effort','thinking_budget','budget_tokens','reasoning_budget'] as $key) unset($params[$key]);
        if ($protocol==='anthropic_messages') {
            $params['thinking']=['type'=>'disabled'];
        } elseif ($protocol==='openai_responses' && (str_contains($code,'deepseek') || preg_match('/(?:^|\/)gpt-(?:5|6)(?:[.\-]|$)/',$code) || array_key_exists('reasoning',$declared))) {
            $params['reasoning']=['effort'=>'none'];
        } elseif (str_contains($code,'qwen')) {
            $params['enable_thinking']=false;
        } elseif (str_contains($code,'deepseek')) {
            $params['thinking']=['type'=>'disabled'];
        } elseif (preg_match('/(?:^|\/)gpt-(?:5|6)(?:[.\-]|$)/',$code) || array_key_exists('reasoning_effort',$declared) || array_key_exists('reasoning',$declared)) {
            $params['reasoning_effort']='none';
        }
        if (!isset($params['thinking']) && !isset($params['reasoning']) && !isset($params['reasoning_effort']) && !array_key_exists('enable_thinking',$params)) {
            if (array_key_exists('enable_thinking',$declared)) $params['enable_thinking']=false;
            elseif (array_key_exists('thinking',$declared)) $params['thinking']=['type'=>'disabled'];
        }
        return $params;
    }
    public function prepareReceipt(): void
    {
        \app\common\service\app\aigc_copywriting\CopywritingReceipt::prepare();
    }
    public function recordReceipt(array $log,array $task,array $result,bool $complete): void
    {
        \app\common\service\app\aigc_copywriting\CopywritingReceipt::record($log,$task,$result,$complete);
    }
    public function preserveUncertain(string $message): bool
    {
        return (bool)preg_match('/timeout|timed out|超时|cURL|connection|reset|empty response|无效响应|连接|任务已取消/i',$message);
    }
}
