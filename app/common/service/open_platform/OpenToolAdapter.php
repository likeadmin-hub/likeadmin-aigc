<?php
namespace app\common\service\open_platform;
use app\common\service\app\AppAccessService;
use app\common\service\app\aigc_llm\AigcLlmService;
final class OpenToolAdapter
{
    public static function authorize(int $tenantId,int $userId,string $app): void
    {
        OpenToolRegistry::get($app);$global=OpenStore::settings(0);$config=OpenStore::requireEnabled($tenantId);
        if(!in_array($app,$config['apps'],true)||!in_array($app,$global['apps'],true))throw new OpenException('permission_denied','该工具未开放',403);
        $denied=AppAccessService::assertTenantCanUse($tenantId,$app,$userId,true);if($denied)throw new OpenException('permission_denied',(string)($denied->getData()['msg']??'工具不可用'),403);
    }
    public static function call($request,array $operation,array $params,?callable $onEvent=null): array
    {
        $params=json_decode(OpenStore::encode($params),true,64,JSON_THROW_ON_ERROR);$app=$operation['app_code'];if(empty($operation['history']))self::authorize((int)$request->tenantId,(int)$request->userId,$app);OpenPolicy::validateInput($params);
        if(isset($operation['params']['task_id']))$params['id']=!empty($operation['internal'])?$operation['params']['task_id']:OpenToolTasks::decode($app,(string)$operation['params']['task_id']);
        if(isset($params['id'])&&($operation['controller']??'')==='Task'&&!isset($operation['params']['task_id'])&&empty($operation['internal']))$params['id']=OpenToolTasks::decode($app,(string)$params['id']);
        if(isset($params['task_id'])&&empty($operation['internal']))$params['task_id']=OpenToolTasks::decode($app,(string)$params['task_id']);
        if(isset($params['task_id'])&&is_string($params['task_id'])&&str_starts_with($params['task_id'],'ot_'))$params['task_id']=OpenToolTasks::decode($app,$params['task_id']);
        if(isset($params['id'])&&is_string($params['id'])&&str_starts_with($params['id'],'ot_'))$params['id']=OpenToolTasks::decode($app,$params['id']);
        $original=app()->request;$scoped=OpenToolRequest::from($request,$params,$operation);app()->instance('request',$scoped);
        try{
        if(($operation['service']??'')==='readTextTasks')return OpenTextLifecycle::tasks((int)$request->tenantId,(int)$request->userId,$operation['route'],$params);
        if(isset($operation['service'])){$params['_disable_transient_retry']=true;$params['_disable_model_fallback']=true;
            $emit=$onEvent?static function(string $event,array $data)use($onEvent){$onEvent(['type'=>$event,'data'=>$data]);}:null;
            return $operation['service']==='streamText'?AigcLlmService::streamText((int)$request->tenantId,(int)$request->userId,$params,$emit):AigcLlmService::generateText((int)$request->tenantId,(int)$request->userId,$params);}
        // This fixed registry reuses the existing user controller's validation and shared service.
        // No caller-controlled class/action is ever invoked.
        $class='app\\api\\controller\\app\\'.$app.'\\'.$operation['controller'].'Controller';
        $controller=app()->make($class,[],true);$response=$controller->{$operation['action']}();
        if(!$response instanceof \think\Response)throw new OpenException('invalid_response','工具响应无效',502);
        $payload=$response->getData();if(!is_array($payload)||(int)($payload['code']??0)!==1)throw new OpenException('tool_error',(string)($payload['msg']??'工具执行失败'));
        return (array)($payload['data']??[]);
        }finally{app()->instance('request',$original);}
    }
    public static function budget($request,array $operation,array $params): array
    {
        if(empty($operation['billable']))return ['user'=>0,'tenant'=>0,'snapshot'=>[]];
        $nativeModel=null;if($operation['app_code']==='aigc_llm'&&isset($operation['service'])){
            try{$nativeModel=\app\common\service\power\MarketTextModelRuntimeService::resolveModel((int)$request->tenantId,$params['model_code']??$params['model']??'',!empty($params['reference_images'])||!empty($params['image_urls']));}
            catch(\Exception){throw new OpenException('model_unavailable','所选文本模型未上架或已不可用',403);}
        }
        $tool=OpenToolRegistry::get($operation['app_code']);$estimate=$tool['operations']['POST estimate']??null;
        if($estimate && $operation['route']==='POST tasks'){
            $quote=self::call($request,$estimate+['app_code'=>$operation['app_code'],'params'=>[]],$params);
            $user=$quote['user_charge_points']??$quote['display_points']??$quote['total_points']??null;
            $tenant=$quote['tenant_cost_points']??$quote['cost_points']??null;
            if($user!==null&&$tenant!==null)return ['user'=>max(0,(float)$user),'tenant'=>max(0,(float)$tenant),'snapshot'=>['quote'=>$quote,'billing_owner'=>'tool']];
        }
        $rules=OpenStore::settings(0)['tool_budgets'][$operation['app_code']][$operation['route']]??null;
        if(!$rules||!isset($rules['user'],$rules['tenant'])||(float)$rules['user']<=0||(float)$rules['tenant']<=0)throw new OpenException('pricing_unavailable','该操作尚未配置可靠的计费额度边界',503);
        return ['user'=>(float)$rules['user'],'tenant'=>(float)$rules['tenant'],'snapshot'=>['billing_owner'=>'tool','budget_rule'=>$rules]+($nativeModel?['native_text_model'=>$nativeModel]:[])];
    }
}
