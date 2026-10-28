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
    public function preserveUncertain(string $message): bool
    {
        return (bool)preg_match('/timeout|timed out|超时|cURL|connection|reset|empty response|无效响应|连接|任务已取消/i',$message);
    }
}
