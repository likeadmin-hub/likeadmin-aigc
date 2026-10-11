<?php
namespace app\common\service\open_platform;
use app\common\service\app\AppAccessService;
use app\common\service\app\aigc_llm\AigcLlmService;
final class OpenToolAdapter
{
    public static function authorize(int $tenantId,int $userId,string $app): void
    {
        OpenToolRegistry::get($app);$global=OpenStore::settings(0);$config=OpenStore::requireEnabled($tenantId);
        if(!in_array($app,$config['apps'],true)||($global['apps']&&!in_array($app,$global['apps'],true)))throw new OpenException('permission_denied','该工具未开放',403);
        $denied=AppAccessService::assertTenantCanUse($tenantId,$app,$userId,true);if($denied)throw new OpenException('permission_denied',(string)($denied->getData()['msg']??'工具不可用'),403);
    }
    public static function call($request,array $operation,array $params,?callable $onEvent=null): array
    {
        $app=$operation['app_code'];self::authorize((int)$request->tenantId,(int)$request->userId,$app);OpenPolicy::validateInput($params);
        if(isset($operation['params']['task_id']))$params['id']=$operation['params']['task_id'];
        $request->withGet($params)->withPost($params);
        if(isset($operation['service']))return $operation['service']==='streamText'?AigcLlmService::streamText((int)$request->tenantId,(int)$request->userId,$params,$onEvent):AigcLlmService::generateText((int)$request->tenantId,(int)$request->userId,$params);
        // This fixed registry reuses the existing user controller's validation and shared service.
        // No caller-controlled class/action is ever invoked.
        $class='app\\api\\controller\\app\\'.$app.'\\'.$operation['controller'].'Controller';
        $controller=app()->make($class,[],true);$response=$controller->{$operation['action']}();
        if(!$response instanceof \think\Response)throw new OpenException('invalid_response','工具响应无效',502);
        $payload=$response->getData();if(!is_array($payload)||(int)($payload['code']??0)!==1)throw new OpenException('tool_error',(string)($payload['msg']??'工具执行失败'));
        return (array)($payload['data']??[]);
    }
    public static function budget($request,array $operation,array $params): array
    {
        if(empty($operation['billable']))return ['user'=>0,'tenant'=>0,'snapshot'=>[]];
        $tool=OpenToolRegistry::get($operation['app_code']);$estimate=$tool['operations']['POST estimate']??null;
        if($estimate && $operation['route']==='POST tasks'){
            $quote=self::call($request,$estimate+['app_code'=>$operation['app_code'],'params'=>[]],$params);
            $user=$quote['user_charge_points']??$quote['display_points']??$quote['total_points']??null;
            $tenant=$quote['tenant_cost_points']??$quote['cost_points']??null;
            if($user!==null&&$tenant!==null)return ['user'=>max(0,(float)$user),'tenant'=>max(0,(float)$tenant),'snapshot'=>['quote'=>$quote,'billing_owner'=>'tool']];
        }
        $rules=OpenStore::settings((int)$request->tenantId)['tool_budgets'][$operation['app_code']][$operation['route']]??null;
        if(!$rules||!isset($rules['user'],$rules['tenant'])||(float)$rules['user']<=0||(float)$rules['tenant']<=0)throw new OpenException('pricing_unavailable','该操作尚未配置可靠的计费额度边界',503);
        return ['user'=>(float)$rules['user'],'tenant'=>(float)$rules['tenant'],'snapshot'=>['billing_owner'=>'tool','budget_rule'=>$rules]];
    }
}
