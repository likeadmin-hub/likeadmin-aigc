<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Pure, opt-in Full Video fee arithmetic. No configuration lookup, activation, transport or wallet access. */
final class MarketFullVideoBilling
{
    private function __construct(private readonly array $terms) {}

    /** The caller must supply resolved server configuration, never a request/body field. */
    public static function fromTrustedMarketConfiguration(array $snapshot, array $terms): self
    {
        self::assertSnapshot($snapshot);
        self::assertTerms($terms);
        $metadata=$snapshot['market_metadata'];$binding=$terms['binding'];
        if ($binding['app_code']!==$snapshot['app_code'] || $binding['api_code']!==$snapshot['api_code']
            || $binding['upstream_app_id']!==($metadata['upstream_app_metadata']['id']??null)
            || $binding['upstream_api_id']!==($metadata['upstream_api_metadata']['id']??null)
            || $binding['schema_digest']!==MarketGenerationReviewedContract::digest(['value'=>$metadata['params_schema']??[]])
            || $binding['defaults_digest']!==MarketGenerationReviewedContract::digest($metadata['default_params']??[])
            || $terms['meter']['maximum_reference_images']!==($metadata['content_schema']['limits']['reference_image']??null)) {
            throw new InvalidArgumentException('Full Video 附加合同与冻结声明不一致');
        }
        return new self($terms);
    }

    /** This typed capability cannot be supplied as a native JSON override. Rates are original frozen SKU rates. */
    public function freeze(array $snapshot, array $native, array $rates): array
    {
        self::fromTrustedMarketConfiguration($snapshot,$this->terms);
        $rates=self::rates($rates);
        $context=self::context($native,$this->terms['meter']);
        $record=['version'=>1,'application_contract'=>$snapshot['application_contract'],'terms'=>$this->terms,
            'rates'=>$rates,'context'=>$context,'amounts'=>self::calculate($this->terms,$rates,$context)];
        $record['freeze_digest']=MarketGenerationReviewedContract::digest($record);
        return $record;
    }

    public static function assertPrepared(array $frozen, array $native): void
    {
        self::assertFrozen($frozen);
        if(self::context($native,$frozen['terms']['meter'])!==$frozen['context'])throw new InvalidArgumentException('Full Video 提交计量与冻结请求不一致');
    }

    /** Supplier usage/duration is not an additional fee instruction. Failure uses the existing complete refund path. */
    public static function settledAmounts(array $frozen, array $response=[], bool $failed=false): array
    {
        self::assertFrozen($frozen);
        return $failed?['api'=>'0.000000','tenant'=>'0.000000','user'=>'0.000000']:$frozen['amounts'];
    }

    private static function assertSnapshot(array $snapshot): void
    {
        MarketGenerationApplicationProtocol::assertSnapshotContract($snapshot);
        if(($snapshot['application_contract']['profile']??null)!=='full_video_submit_20261101'
            ||($snapshot['resource_type']??'')!=='app_api'||($snapshot['app_code']??'')!=='full_video'||($snapshot['api_code']??'')!=='submit'
            ||($snapshot['usage_unit']??'')!=='input_second')throw new InvalidArgumentException('Full Video 附加计费需要经过审核的新冻结合同');
    }

    private static function assertTerms(mixed $terms): void
    {
        self::keys($terms,['version','status','binding','source_config','meter','layers','rounding','terms_digest']);
        $record=$terms;unset($record['terms_digest']);
        if($terms['version']!==1||$terms['status']!=='reviewed'||$terms['rounding']!=='half_up_6'
            ||!is_string($terms['terms_digest'])||!hash_equals(MarketGenerationReviewedContract::digest($record),$terms['terms_digest']))throw new InvalidArgumentException('Full Video 附加计费合同未审核或摘要不一致');
        $b=$terms['binding'];self::keys($b,['app_code','api_code','upstream_app_id','upstream_api_id','schema_digest','defaults_digest','source_revision','config_digest']);
        if($b['app_code']!=='full_video'||$b['api_code']!=='submit'||$b['upstream_app_id']!==24||$b['upstream_api_id']!==73
            ||$b['source_revision']!=='b5887e1c153111edd4515beb0635e6b66d9978b6'
            ||$b['schema_digest']!=='d342216664eb8b43c85706fb5c712a493afdbb2c55366278b64d6464161bd188'
            ||$b['defaults_digest']!==MarketGenerationReviewedContract::digest(['model'=>'full-video','ratio'=>'16:9','duration'=>4,'resolution'=>'480P','aigc_watermark'=>false]))throw new InvalidArgumentException('Full Video 附加计费来源身份不正确');
        foreach(['schema_digest','defaults_digest','config_digest']as$key)if(!is_string($b[$key])||!preg_match('/^[a-f0-9]{64}$/D',$b[$key]))throw new InvalidArgumentException('Full Video 附加计费来源摘要不正确');
        $source=$terms['source_config'];self::keys($source,['reference_image_free_count','reference_image_overage_cost_points','reference_image_overage_sale_points']);
        if(!is_int($source['reference_image_free_count'])||$source['reference_image_free_count']<0
            ||$b['config_digest']!==MarketGenerationReviewedContract::digest($source))throw new InvalidArgumentException('Full Video 正式配置证据不一致');
        self::decimal($source['reference_image_overage_cost_points']);self::decimal($source['reference_image_overage_sale_points']);
        $meter=$terms['meter'];self::keys($meter,['basis','free_reference_images','maximum_reference_images','slot_type','slot_role']);
        if($meter['basis']!=='request_duration'||$meter['slot_type']!=='image_url'||$meter['slot_role']!=='reference_image'
            ||!is_int($meter['free_reference_images'])||!is_int($meter['maximum_reference_images'])
            ||$meter['free_reference_images']!==$source['reference_image_free_count']||$meter['free_reference_images']<0
            ||$meter['maximum_reference_images']!==9||$meter['free_reference_images']>$meter['maximum_reference_images'])throw new InvalidArgumentException('Full Video 附加计量边界不正确');
        $layers=$terms['layers'];self::keys($layers,['api_to_aigc','platform_to_tenant','tenant_to_user']);
        self::keys($layers['api_to_aigc'],['api_points_per_image','conversion']);
        if(self::decimal($layers['api_to_aigc']['api_points_per_image'])!==self::decimal($source['reference_image_overage_sale_points']))throw new InvalidArgumentException('Full Video API 售价与正式配置不一致');
        $conversion=$layers['api_to_aigc']['conversion'];self::keys($conversion,['numerator','denominator']);
        foreach($conversion as$value)if(!is_int($value)||$value<1||$value>1000000)throw new InvalidArgumentException('Full Video 点数转换缺少可信比例');
        foreach(['platform_to_tenant','tenant_to_user']as$level){self::keys($layers[$level],['points_per_image']);self::decimal($layers[$level]['points_per_image']);}
    }

    private static function context(array $native,array $meter): array
    {
        foreach(['full_video_fee_contract','full_video_billing','application_contract','fee_contract','input','params','parameters']as$key)
            if(array_key_exists($key,$native))throw new InvalidArgumentException('Full Video 原生请求不能注入计费合同或包装计量字段');
        $duration=$native['duration']??null;
        if((!is_int($duration)&&!(is_string($duration)&&preg_match('/^(?:[4-9]|1[0-5])$/D',$duration)))||(int)$duration<4||(int)$duration>15)throw new InvalidArgumentException('Full Video 冻结时长必须是 4–15 秒整数');
        if(!isset($native['content'])||!is_array($native['content'])||!array_is_list($native['content'])||$native['content']===[])throw new InvalidArgumentException('Full Video 缺少原生 content');
        $counts=['first_frame'=>0,'last_frame'=>0,'reference_image'=>0,'reference_video'=>0,'reference_audio'=>0];$texts=0;$length=0;
        foreach($native['content']as$item){
            if(!is_array($item))throw new InvalidArgumentException('Full Video content 必须是对象列表');
            if(($item['type']??null)==='text'){
                if(!is_string($item['text']??null)||trim($item['text'])==='')throw new InvalidArgumentException('Full Video 文本项不能为空');
                $texts++;$length+=mb_strlen(trim($item['text']),'UTF-8');continue;
            }
            $type=$item['type']??'';$role=$item['role']??'';
            if(!is_string($type))throw new InvalidArgumentException('Full Video 素材类型不正确');
            $allowed=['image_url'=>['first_frame','last_frame','reference_image'],'video_url'=>['reference_video'],'audio_url'=>['reference_audio']];
            $address=$item[$type]??null;$url=is_array($address)?($address['url']??null):null;
            if(!isset($allowed[$type])||!in_array($role,$allowed[$type],true)||!is_string($url)||trim($url)==='')throw new InvalidArgumentException('Full Video 素材类型、用途或地址不正确');
            $counts[$role]++; // Count actual slots, including repeated URLs. Never count first/last frames as reference images.
        }
        $references=$counts['reference_image']+$counts['reference_video']+$counts['reference_audio'];$frames=$counts['first_frame']+$counts['last_frame'];
        if(!$texts||$counts['first_frame']>1||$counts['last_frame']>1||$counts['reference_image']>$meter['maximum_reference_images']
            ||$counts['reference_video']>3||$counts['reference_audio']>3||($frames&&$references)
            ||($counts['reference_audio']&&!$counts['reference_image']&&!$counts['reference_video']))throw new InvalidArgumentException('Full Video 素材数量或模式组合不正确');
        $resolution=strtoupper((string)($native['resolution']??'480P'));
        if(!in_array($resolution,['480P','768P','1080P','2K','4K'],true))throw new InvalidArgumentException('Full Video 分辨率未经审核');
        $remote=in_array($resolution,['768P','1080P','2K'],true);
        if($length>($remote?5000:7000)||($remote&&($native['aigc_watermark']??false)!==false))throw new InvalidArgumentException('Full Video 路由文本或水印不合法');
        return ['request_duration'=>(int)$duration,'reference_images'=>$counts['reference_image'],
            'excess_reference_images'=>max(0,$counts['reference_image']-$meter['free_reference_images'])];
    }

    private static function calculate(array $terms,array $rates,array $context): array
    {
        $seconds=(string)$context['request_duration'];$excess=(string)$context['excess_reference_images'];$layers=$terms['layers'];
        $api=bcadd(bcmul($rates['api_unit_points'],$seconds,12),bcmul(self::decimal($layers['api_to_aigc']['api_points_per_image']),$excess,12),12);
        $conversion=$layers['api_to_aigc']['conversion'];$api=bcdiv(bcmul($api,(string)$conversion['numerator'],12),(string)$conversion['denominator'],12);
        $amounts=['api'=>self::rounded($api)];
        foreach(['tenant'=>'platform_to_tenant','user'=>'tenant_to_user']as$key=>$level) $amounts[$key]=self::rounded(bcadd(
            bcmul($rates[$key.'_unit_points'],$seconds,12),bcmul(self::decimal($layers[$level]['points_per_image']),$excess,12),12));
        return $amounts;
    }

    private static function assertFrozen(array $frozen): void
    {
        self::keys($frozen,['version','application_contract','terms','rates','context','amounts','freeze_digest']);
        $record=$frozen;unset($record['freeze_digest']);
        if($frozen['version']!==1||!is_string($frozen['freeze_digest'])||!hash_equals(MarketGenerationReviewedContract::digest($record),$frozen['freeze_digest']))throw new InvalidArgumentException('Full Video 冻结附加账单摘要不一致');
        self::assertTerms($frozen['terms']);
        self::keys($frozen['application_contract'],['version','profile','binding_digest']);
        if($frozen['application_contract']['version']!==1||$frozen['application_contract']['profile']!=='full_video_submit_20261101'
            ||!is_string($frozen['application_contract']['binding_digest'])||!preg_match('/^[a-f0-9]{64}$/D',$frozen['application_contract']['binding_digest']))throw new InvalidArgumentException('Full Video 冻结应用身份不一致');
        if(self::rates($frozen['rates'])!==$frozen['rates'])throw new InvalidArgumentException('Full Video 冻结金额精度不一致');
        $context=$frozen['context'];self::keys($context,['request_duration','reference_images','excess_reference_images']);
        if(!is_int($context['request_duration'])||$context['request_duration']<4||$context['request_duration']>15
            ||!is_int($context['reference_images'])||$context['reference_images']<0||$context['reference_images']>$frozen['terms']['meter']['maximum_reference_images']
            ||$context['excess_reference_images']!==max(0,$context['reference_images']-$frozen['terms']['meter']['free_reference_images']))throw new InvalidArgumentException('Full Video 冻结计量边界不一致');
        if($frozen['amounts']!==self::calculate($frozen['terms'],$frozen['rates'],$frozen['context']))throw new InvalidArgumentException('Full Video 冻结附加账单金额不一致');
    }

    private static function decimal(mixed $value): string
    {
        if(is_int($value))$value=(string)$value;
        if(!is_string($value)||!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,6})?$/D',$value)||bccomp($value,'10000000000',6)>=0)throw new InvalidArgumentException('Full Video 金额必须是明确的非负六位小数');
        return bcadd($value,'0',6);
    }

    private static function rates(mixed $rates): array
    {
        self::keys($rates,['api_unit_points','tenant_unit_points','user_unit_points','usage_unit_size']);
        foreach(['api_unit_points','tenant_unit_points','user_unit_points']as$key)$rates[$key]=self::decimal($rates[$key]);
        if(self::decimal($rates['usage_unit_size'])!=='1.000000')throw new InvalidArgumentException('Full Video 附加合同仅支持已审核的每秒单位');
        $rates['usage_unit_size']='1.000000';return $rates;
    }

    private static function rounded(string $value): string
    {
        return self::decimal(bcadd($value,'0.0000005',6));
    }

    private static function keys(mixed $value,array $keys): void
    {
        if(!is_array($value)||count($value)!==count($keys)||array_diff(array_keys($value),$keys)!==[])throw new InvalidArgumentException('Full Video 计费合同字段缺失或未知');
    }
}
