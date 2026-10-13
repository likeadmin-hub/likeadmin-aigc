<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;
use app\common\service\open_platform\{OpenVault,OpenStore};

/** Process-local, server-only bridge for an already confirmed native price snapshot. */
final class AgentToolContext
{
    private static ?array $prepared=null;
    public static function within(array $prepared,callable $call): array
    {
        if(self::$prepared!==null)throw new \LogicException('Nested Agent submit');
        self::$prepared=$prepared;
        try{return $call();}finally{self::$prepared=null;}
    }
    public static function snapshot(int $tenant,int $user,string $app,string $field): ?array
    {
        if(self::$prepared===null||self::$prepared['capability']['code']!==$app)return null;
        $blob=self::$prepared['quote']['snapshot'][$field]??'';
        if($blob==='')throw new \RuntimeException('创作价格快照缺失');
        $value=OpenStore::json(OpenVault::open($blob));
        if((int)($value['tenant_id']??0)!==$tenant||(int)($value['user_id']??0)!==$user||($value['app_code']??'')!==$app)throw new \RuntimeException('创作价格归属不匹配');
        return $value;
    }
    public static function image(int $tenant,int $user): ?array
    {
        $value=self::snapshot($tenant,$user,'aigc_image','native_selection_blob');
        return $value?['selection'=>$value['selection'],'quote'=>self::$prepared['quote']['snapshot']['quote'],'request_id'=>'']:null;
    }
    public static function priceFingerprint(array $quote): string
    {
        // Encrypted captures use random IVs. Compare their contents, never ciphertext.
        foreach($quote['snapshot']??[] as $key=>$value)if(str_ends_with($key,'_blob'))$quote['snapshot'][$key]=OpenStore::json(OpenVault::open($value));
        return hash('sha256',AgentStore::encode(self::canonical($quote)));
    }
    private static function canonical($value)
    {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value);
        foreach($value as &$item)$item=self::canonical($item);
        return $value;
    }
}
