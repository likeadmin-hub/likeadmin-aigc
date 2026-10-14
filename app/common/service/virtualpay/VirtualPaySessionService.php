<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;
use think\facade\Db;
use app\common\model\user\UserAuth;
use app\common\service\virtualpay\VirtualPayVault as Cipher;
final class VirtualPaySessionService
{
    private VirtualPayGateway $gateway;
    public function __construct(?VirtualPayGateway $gateway=null){$this->gateway=$gateway??app()->make(VirtualPayGateway::class);}
    public function exchange(int $tenant,int $user,string $code): string
    {
        VirtualPayConfigService::requireVault();if($code===''||$user<=0)throw new \RuntimeException('请重新登录微信小程序');
        $i=VirtualPayConfigService::identity($tenant);$r=$this->gateway->exchange($tenant,$code);
        if(empty($r['openid'])||empty($r['session_key']))throw new \RuntimeException('微信登录凭据已失效，请重新登录');
        $auth=UserAuth::where(['tenant_id'=>$tenant,'user_id'=>$user,'terminal'=>1,'openid'=>(string)$r['openid']])->findOrEmpty();
        if($auth->isEmpty())throw new \RuntimeException('当前微信身份与登录账号不一致，请退出后重新登录');
        $session=bin2hex(random_bytes(32));
        Db::name('virtual_pay_session')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'app_id'=>$i['app_id'],'session_id'=>hash('sha256',$session),'openid'=>(string)$r['openid'],'session_key'=>Cipher::encrypt((string)$r['session_key']),'expires_at'=>time()+600]);
        Db::name('virtual_pay_session')->where('expires_at','<',time()-600)->limit(100)->delete();return $session;
    }
    public function get(int $tenant,int $user,string $app,string $session): array
    {
        VirtualPayConfigService::requireVault();$row=Db::name('virtual_pay_session')->where(['tenant_id'=>$tenant,'user_id'=>$user,'app_id'=>$app,'session_id'=>hash('sha256',$session)])->where('expires_at','>',time())->find();
        if(!$row)throw new \RuntimeException('支付登录凭据已过期，请重新登录');
        $row['session_key']=Cipher::decrypt($row['session_key']);if(!$row['session_key'])throw new \RuntimeException('支付凭据无法读取，请重新登录');return $row;
    }
}
