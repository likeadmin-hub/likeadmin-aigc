<?php
namespace app\common\service\app {
    final class AppAccessService {
        public static array $calls=[];
        public static function assertTenantCanUse(...$args){self::$calls[]=$args;return null;}
        public static function assertTenantCanManage(...$args){self::$calls[]=$args;return null;}
    }
}
namespace {
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\app\AppAccessService;
function routeCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach([new app\api\http\middleware\AppAccessMiddleware(),new app\tenantapi\http\middleware\AppAccessMiddleware()] as $middleware){
    foreach(['Agent','AgentChat','agent_chat','AgentGeneration','agent_generation','AgentTool','agent_tool','Skill','Governance','AdminTrace','admin_trace','AgentChatController'] as $controller){
        $request=new app\Request();$request->setController('app.aigc_canvas.'.$controller);$request->setAction('detail');$request->tenantId=701;$request->userId=801;
        AppAccessService::$calls=[];$called=false;
        $response=$middleware->handle($request,static function()use(&$called){$called=true;});
        routeCheck(!$called && AppAccessService::$calls===[] && (int)$response->getData()['code']!==1,'Retired controller reached execution: '.$controller);
    }
    foreach([['aigc_canvas','Agent','assetRegister'],['aigc_canvas','agent','asset_register'],['aigc_canvas','Conversation','submit'],['aigc_canvas','Project','detail'],['aigc_canvas','Generate','create'],['aigc_canvas','Config','detail'],['aigc_llm','Agent','submit'],['aigc_short_drama','AgentChat','detail']] as [$app,$controller,$action]){
        $request=new app\Request();$request->setController('app.'.$app.'.'.$controller);$request->setAction($action);$request->tenantId=701;$request->userId=801;
        AppAccessService::$calls=[];$called=false;
        $middleware->handle($request,static function()use(&$called){$called=true;return 'allowed';});
        routeCheck($called && count(AppAccessService::$calls)===1,'Current route or access check blocked: '.$app.'.'.$controller.'/'.$action);
    }
}
echo "PASS retired routes denied in both middleware; native canvas, asset compatibility, home Agent and short drama retain access checks\n";
}
