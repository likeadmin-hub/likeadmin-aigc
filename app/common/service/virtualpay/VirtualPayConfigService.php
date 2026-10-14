<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;
use think\facade\Db;
use app\common\service\virtualpay\VirtualPayVault as Cipher;
use app\common\service\promotion\PackagePricingService as Price;
use app\common\service\promotion\PackagePromotionService as Promo;
use app\common\service\promotion\PackagePromotionPolicy as Money;
final class VirtualPayConfigService
{
    /** Independent from request source; workers and platform context must still resolve tenant credentials. */
    public static function manual(int $tenant,string $key): string
    {
        $raw=(string)(Db::name('tenant_config')->where(['tenant_id'=>$tenant,'type'=>'mnp_setting','name'=>$key])->value('value')??'');
        $decoded=json_decode($raw,true);return is_string($decoded)?$decoded:$raw;
    }
    public static function identity(int $tenant): array
    {
        if($tenant<=0)throw new \RuntimeException('租户身份不正确');
        $row=Db::name('wechat_authorizers')->where(['tenant_id'=>$tenant,'authorizer_type'=>'miniprogram','authorization_status'=>1])->order('id desc')->find();
        $app=$row?(string)$row['authorizer_appid']:self::manual($tenant,'app_id');
        if($app==='')throw new \RuntimeException('请先配置或授权微信小程序');
        return ['app_id'=>$app,'authorizer_id'=>(int)($row['id']??0),'func_info'=>$row['func_info']??'[]'];
    }
    public static function requireVault(): void
    {
        Cipher::requireKey();
    }
    public static function secret(array $version,string $field): string
    {
        if(!in_array($field,['app_key','token','aes_key','mini_secret'],true))throw new \InvalidArgumentException('凭据字段不正确');
        self::requireVault();$plain=Cipher::decrypt($version[$field]??'');if($plain==='')throw new \RuntimeException('支付密钥无法读取，请联系管理员核对加密配置');return $plain;
    }
    public static function version(int $tenant,int $id): array
    {
        $v=Db::name('virtual_pay_config_version')->where(['tenant_id'=>$tenant,'id'=>$id])->find();if(!$v)throw new \RuntimeException('原支付配置不存在，请联系管理员核对');return $v;
    }
    public static function active(int $tenant,int $env): array
    {
        VirtualPayPolicy::environment($env);$i=self::identity($tenant);
        $c=Db::name('virtual_pay_config')->where(['tenant_id'=>$tenant,'app_id'=>$i['app_id'],'env'=>$env,'enabled'=>1])->find();
        if(!$c)throw new \RuntimeException('当前小程序尚未启用虚拟支付');return self::version($tenant,(int)$c['version_id']);
    }
    public static function detail(int $tenant,int $env): array
    {
        VirtualPayPolicy::environment($env);$i=self::identity($tenant);$c=Db::name('virtual_pay_config')->where(['tenant_id'=>$tenant,'app_id'=>$i['app_id'],'env'=>$env])->find();
        $v=$c&&!empty($c['version_id'])?self::version($tenant,(int)$c['version_id']):[];
        $push=['token'=>'','aes_key'=>''];$vaultReady=true;
        if ($v && !$i['authorizer_id']) { try { foreach (array_keys($push) as $k) $push[$k]=self::secret($v,$k); } catch (\RuntimeException $e) { $vaultReady=false; } }
        $notify=request()->domain().'/wechat/virtual-pay/'.$tenant.'/'.rawurlencode($i['app_id']).'/callback';
        if ($i['authorizer_id']) $notify=str_replace('$APPID$', $i['app_id'], (string)(\app\common\service\wechat\OpenPlatformService::config()['message_callback_url_display']??''));
        return ['vault_ready'=>$vaultReady,'app_id'=>$i['app_id'],'mode'=>$i['authorizer_id']?'authorized':'manual','env'=>$env,'enabled'=>(int)($c['enabled']??0),'offer_id'=>(string)($v['offer_id']??''),'key_configured'=>!empty($v['app_key']),
            'token'=>$push['token'],'aes_key'=>$push['aes_key'],'message_mode'=>$v['message_mode']??'safe','version_id'=>(int)($v['id']??0),
            'notify_url'=>$notify,
            'products'=>Db::name('virtual_pay_product')->where(['tenant_id'=>$tenant,'app_id'=>$i['app_id'],'env'=>$env])->select()->toArray()];
    }
    public static function save(int $tenant,int $env,array $input): array
    {
        VirtualPayPolicy::environment($env);$i=self::identity($tenant);if(isset($input['app_id'])&&$input['app_id']!==$i['app_id'])throw new \RuntimeException('小程序身份已变化，请刷新配置');
        return Db::transaction(function()use($tenant,$env,$input,$i){
            $scope=['tenant_id'=>$tenant,'app_id'=>$i['app_id'],'env'=>$env];Db::name('virtual_pay_config')->extra('IGNORE')->insert($scope);
            $c=Db::name('virtual_pay_config')->where($scope)->lock(true)->find();$v=$c['version_id']?self::version($tenant,(int)$c['version_id']):[];
            if(isset($input['version_id'])&&(int)$input['version_id']!==(int)$c['version_id'])throw new \RuntimeException('支付配置已被修改，请重新加载后再保存');
            $enabled=!empty($input['enabled'])?1:0;
            if(!$enabled&&empty($input['offer_id'])&&empty($input['app_key'])){Db::name('virtual_pay_config')->where('id',$c['id'])->update(['enabled'=>0,'update_time'=>time()]);return self::detail($tenant,$env);}
            self::requireVault();$offer=trim((string)($input['offer_id']??($v['offer_id']??'')));if(!preg_match('/^\d{1,32}$/D',$offer))throw new \RuntimeException('请填写正确的 OfferID');
            $keys=[];foreach(['app_key','token','aes_key'] as $field){$raw=trim((string)($input[$field]??''));if($raw!==''&&str_contains($raw,'*'))throw new \RuntimeException('请填写完整密钥，留空表示保持不变');$keys[$field]=$raw!==''?Cipher::encrypt($raw):(string)($v[$field]??'');}
            if($keys['token']==='')$keys['token']=Cipher::encrypt(bin2hex(random_bytes(16)));
            if($keys['aes_key']==='')$keys['aes_key']=Cipher::encrypt(rtrim(base64_encode(random_bytes(32)),'='));
            if ($keys['app_key'] !== '' && strlen(Cipher::decrypt($keys['app_key'])) < 16) throw new \RuntimeException('请填写完整的当前环境 AppKey');
            if($keys['app_key']==='')throw new \RuntimeException('首次配置请填写当前环境的 AppKey');
            if(!preg_match('/^[A-Za-z0-9+\/]{43}$/D',Cipher::decrypt($keys['aes_key'])))throw new \RuntimeException('EncodingAESKey 应为43位');
            $mode=$input['message_mode']??($v['message_mode']??'safe');if(!in_array($mode,['plain','compatible','safe'],true))throw new \RuntimeException('消息加密模式不正确');
            if($enabled&&$i['authorizer_id']){ $permissions=json_decode($i['func_info'],true)?:[];if(!in_array(157,array_map(static fn($p)=>(int)($p['funcscope_category']['id']??0),$permissions),true))throw new \RuntimeException('请重新授权小程序并授予虚拟支付权限'); }
            $id=Db::name('virtual_pay_config_version')->insertGetId($scope+$keys+['mini_secret'=>$i['authorizer_id']?'':Cipher::encrypt(self::manual($tenant,'app_secret')),'config_id'=>$c['id'],'authorizer_id'=>$i['authorizer_id'],'offer_id'=>$offer,'message_mode'=>$mode,'created_at'=>time()]);
            Db::name('virtual_pay_config')->where('id',$c['id'])->update(['version_id'=>$id,'enabled'=>$enabled,'update_time'=>time()]);return self::detail($tenant,$env);
        });
    }
    public static function saveProduct(int $tenant,int $env,array $input): void
    {
        VirtualPayPolicy::environment($env);$i=self::identity($tenant);if(isset($input['app_id'])&&$input['app_id']!==$i['app_id'])throw new \RuntimeException('小程序身份已变化，请重新加载后再绑定道具');$from=(string)($input['from']??'');$package=(int)($input['package_id']??0);$cycle=(string)($input['cycle']??'package');$variant=(string)($input['variant']??'regular');
        if(!in_array($variant,['regular','discount'],true))throw new \RuntimeException('道具价格类型不正确');
        $catalog=Price::catalog($tenant,$from,$package,$cycle,false);if($catalog['cycle']!==$cycle)throw new \RuntimeException('请按实际购买周期绑定道具');
        $price=Money::cents($input['price']??'0');$expected=$catalog['regular_cents'];
        if($variant==='discount'){$p=Promo::get($tenant,$from,$package);$expected=(int)((json_decode($p['discount_prices']??'{}',true)?:[])[$cycle]??0);}
        if($price<=0||$price!==$expected)throw new \RuntimeException('道具价格必须与对应套餐常规价或优惠价一致');
        $product=trim((string)($input['product_id']??''));if(!preg_match('/^[A-Za-z0-9_.-]{1,128}$/D',$product))throw new \RuntimeException('请填写正确的微信道具ID');
        $scope=['tenant_id'=>$tenant,'app_id'=>$i['app_id'],'env'=>$env,'biz_type'=>$from,'package_id'=>$package,'cycle'=>$cycle,'variant'=>$variant];
        Db::transaction(function()use($scope,$product,$price){Db::name('virtual_pay_product')->extra('IGNORE')->insert($scope+['product_id'=>$product,'price_cents'=>$price,'updated_at'=>time()]);Db::name('virtual_pay_product')->where($scope)->update(['product_id'=>$product,'price_cents'=>$price,'updated_at'=>time()]);});
    }
    public static function product(int $tenant,string $app,int $env,string $from,int $package,string $cycle,string $variant): array
    {
        $row=Db::name('virtual_pay_product')->where(['tenant_id'=>$tenant,'app_id'=>$app,'env'=>$env,'biz_type'=>$from,'package_id'=>$package,'cycle'=>$cycle,'variant'=>$variant])->find();
        if(!$row)throw new \RuntimeException('该套餐尚未配置当前环境的微信道具，请联系管理员');$row['price_cents']=(int)$row['price_cents'];return $row;
    }
}
