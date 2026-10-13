<?php
namespace app\common\service\open_platform;
use app\common\service\app\aigc_image\AigcImageChannelService as Channels;
use app\common\service\power\MarketImageModelRuntimeService as Models;
use app\common\service\power\MarketNanoBananaAppRuntimeService as Nano;
/** Freeze the native image selection and supplier SKU before accepting a paid call. */
final class OpenImagePricing
{
    public const COMPOSITES=['aigc_product_image','aigc_style_transfer','aigc_background_removal','aigc_outpaint','aigc_image_translate','aigc_local_redraw','aigc_photo_restore','aigc_model_wear','aigc_hairstyle'];
    private const MULTI_IMAGE=['aigc_fitting','aigc_product_multi_angle','aigc_fashion_lookbook'];
    public static function fittingBudget($request,array $params): array
    {
        OpenToolAdapter::authorize((int)$request->tenantId,(int)$request->userId,'aigc_fitting');
        return self::applicationFittingBudget((int)$request->tenantId,(int)$request->userId,$params);
    }
    public static function applicationFittingBudget(int $tenant,int $user,array $params): array
    {
        $denied=\app\common\service\app\AppAccessService::assertTenantCanUse($tenant,'aigc_fitting',$user,true,false);
        if($denied)throw new OpenException('permission_denied','试衣工具不可用',403);
        $params=json_decode(OpenStore::encode($params),true,64,JSON_THROW_ON_ERROR);
        // Preset model images are validated by the native service. All other
        // input, including custom model images and aliases, needs ownership first.
        $config=\app\common\service\app\aigc_fitting\AigcFittingService::config($tenant);
        $mode=(string)($params['mode']??$config['default_mode']??'single');
        $params['mode']=in_array($mode,['single','group','custom'],true)?$mode:'single';
        $private=$params;
        if($params['mode']!=='custom')unset($private['model_images'],$private['model_image']);
        $resolved=OpenMaterials::resolve($tenant,$user,$private);
        $params=array_replace($params,$resolved);
        $prepared=\app\common\service\app\aigc_fitting\AigcFittingService::openPlatformAdmission($tenant,$user,$params);
        $payload=$prepared['prepared']['image_payload'];$payload['quantity']=1;
        $native=self::capture($tenant,$user,$payload,'aigc_fitting');
        $budget=OpenToolAdapter::estimateBudget($prepared['estimate']);
        $quantity=(int)$prepared['estimate']['quantity'];
        if($quantity<1||$quantity>4||$budget===null||abs($budget['tenant']-round($native['tenant']*$quantity,2))>0.000001)throw new OpenException('pricing_unavailable','试衣价格与平台 SKU 成本不一致',503);
        $budget['snapshot']['native_selection_blob']=$native['snapshot']['native_selection_blob'];
        $budget['snapshot']['native_prepared_blob']=OpenVault::seal(OpenStore::encode(['app_code'=>'aigc_fitting','tenant_id'=>$tenant,'user_id'=>$user]+$prepared));
        return $budget;
    }
    public static function multiAngleBudget($request,array $params): array
    {
        $params=json_decode(OpenStore::encode($params),true,64,JSON_THROW_ON_ERROR);
        $tenant=(int)$request->tenantId;$user=(int)$request->userId;$app='aigc_product_multi_angle';
        OpenToolAdapter::authorize($tenant,$user,$app);
        $recipe=\app\common\service\app\aigc_product_multi_angle\AigcProductMultiAngleService::openPlatformAdmission($tenant,$user,$params);
        OpenMaterials::resolve($tenant,$user,['source_image'=>$recipe['prepared']['source_image']]);
        $native=self::capture($tenant,$user,$recipe['prepared']['image_payload'],$app);
        $selection=OpenStore::json(OpenVault::open($native['snapshot']['native_selection_blob']))['selection'];
        if($selection['_open_image']['runtime']!=='model')throw new OpenException('capability_unavailable','商品多角度开放调用需要商城模型通道',503);
        $budget=OpenToolAdapter::estimateBudget($recipe['estimate']);$quantity=(int)$recipe['estimate']['quantity'];
        if($quantity<1||$quantity>100||$budget===null||abs($budget['tenant']-round($native['tenant']*$quantity,2))>0.000001)throw new OpenException('pricing_unavailable','多角度价格与平台 SKU 成本不一致',503);
        $budget['snapshot']['native_selection_blob']=$native['snapshot']['native_selection_blob'];
        $budget['snapshot']['native_prepared_blob']=OpenVault::seal(OpenStore::encode(['app_code'=>$app,'tenant_id'=>$tenant,'user_id'=>$user]+$recipe));
        return $budget;
    }
    public static function fashionBudget($request,array $params): array
    {
        $params=json_decode(OpenStore::encode($params),true,64,JSON_THROW_ON_ERROR);
        $params=OpenMaterials::nativeInputs('aigc_fashion_lookbook',$params);
        $tenant=(int)$request->tenantId;$user=(int)$request->userId;$app='aigc_fashion_lookbook';OpenToolAdapter::authorize($tenant,$user,$app);
        $recipe=\app\common\service\app\aigc_fashion_lookbook\AigcFashionLookbookService::openPlatformAdmission($tenant,$user,$params);
        $native=self::capture($tenant,$user,$recipe['prepared']['image_payload'],$app);
        $selection=OpenStore::json(OpenVault::open($native['snapshot']['native_selection_blob']))['selection'];
        if($selection['_open_image']['runtime']!=='model')throw new OpenException('capability_unavailable','服饰套图开放调用需要商城模型通道',503);
        $budget=OpenToolAdapter::estimateBudget($recipe['estimate']);$quantity=(int)$recipe['estimate']['quantity'];
        if($quantity<1||$quantity>6||$budget===null||abs($budget['tenant']-round($native['tenant']*$quantity,2))>0.000001)throw new OpenException('pricing_unavailable','服饰套图价格与平台 SKU 成本不一致',503);
        $budget['snapshot']['native_selection_blob']=$native['snapshot']['native_selection_blob'];
        $budget['snapshot']['native_prepared_blob']=OpenVault::seal(OpenStore::encode(['app_code'=>$app,'tenant_id'=>$tenant,'user_id'=>$user]+$recipe));return $budget;
    }
    public static function budget($request,array $params): array
    {
        $tenant=(int)$request->tenantId;$user=(int)$request->userId;
        OpenToolAdapter::authorize($tenant,$user,'aigc_image');
        return self::capture($tenant,$user,$params,'aigc_image');
    }
    /** Same native pricing for logged-in application users, independent of API-key publication. */
    public static function applicationBudget(int $tenant,int $user,array $params): array
    {
        $denied=\app\common\service\app\AppAccessService::assertTenantCanUse($tenant,'aigc_image',$user,true,false);
        if($tenant<1||$user<1||$denied)throw new OpenException('permission_denied','图片工具不可用',403);
        return self::capture($tenant,$user,$params,'aigc_image');
    }
    private static function capture(int $tenant,int $user,array $params,string $app): array
    {
        if(empty($params['channel'])&&!empty($params['model_id']))$params['channel']=$params['model_id'];
        $selection=Channels::resolveSelection($tenant,$params);$spec=$selection['spec'];$channel=$selection['channel'];
        $quantity=Channels::normalizeQuantity($params['quantity']??1);Channels::assertChannelQuantity($channel,$quantity);
        $references=array_filter(array_map('strval',(array)($params['reference_images']??[])),static fn($value)=>trim($value)!=='');
        if(count($references)>(int)$channel['max_reference_images'])throw new OpenException('invalid_request','参考图数量超出限制');
        $runtime=match($channel['provider']){'power_market_model'=>'model','power_market_app_api'=>'nano',default=>throw new OpenException('capability_unavailable','图片通道尚未接入算力市场',503)};
        $resolved=array_merge($params,['channel'=>$channel['code'],'model_id'=>$channel['code'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio'],'quantity'=>$quantity,
            'variant'=>$spec['variant']??$params['variant']??'','market_product_id'=>(int)($spec['market_product_id']??0),'market_sku_id'=>self::skuId($spec['market_sku_id']??$params['market_sku_id']??$params['sku_id']??0)]);
        $capture=($runtime==='model'?Models::class:Nano::class)::openPlatformAdmissionQuote($tenant,$resolved,$quantity);
        $quote=$capture['quote'];$budget=OpenToolAdapter::estimateBudget($quote);
        if($budget===null)throw new OpenException('pricing_unavailable','图片生成缺少可靠的计费额度边界',503);
        $selection['_open_image']=['runtime'=>$runtime,'market'=>$capture['market'],'quantity'=>$quantity];
        $budget['snapshot']['native_selection_blob']=OpenVault::seal(OpenStore::encode(['app_code'=>$app,'tenant_id'=>$tenant,'user_id'=>$user,'selection'=>$selection]));
        return $budget;
    }
    public static function compositeBudget($request,string $app,array $params): array
    {
        if(!in_array($app,self::COMPOSITES,true))throw new OpenException('capability_unavailable','未注册图片工具',404);
        $original=app()->request;
        app()->instance('request',OpenToolRequest::from($request,$params,['app_code'=>$app,'route'=>'POST tasks']));
        try{return self::compositeBudgetScoped($request,$app,$params);}
        finally{app()->instance('request',$original);}
    }
    private static function compositeBudgetScoped($request,string $app,array $params): array
    {
        $tenant=(int)$request->tenantId;$user=(int)$request->userId;OpenToolAdapter::authorize($tenant,$user,$app);
        if($app==='aigc_model_wear'&&!isset($params['model_image'])&&isset($params['model']))$params['model_image']=$params['model'];
        $params=OpenMaterials::resolve($tenant,$user,$params);
        $suffix=substr($app,5);$class='app\\common\\service\\app\\'.$app.'\\Aigc'.implode('',array_map('ucfirst',explode('_',$suffix))).'Service';
        $prepared=$class::openPlatformAdmission($tenant,$user,$params);
        // Native aliases are canonicalized by the service (e.g. model -> model_image).
        // Check that canonical recipe as well, before freezing or dispatching it.
        $materials=$prepared['prepared'];
        // These two native services load templates by tenant and check status/VIP.
        // Their administrator-owned template image is a shared business asset;
        // caller input was already checked above, even when it equals that URI.
        $shared=in_array($app,['aigc_product_image','aigc_style_transfer'],true)&&!empty($materials['template']['id'])
            &&(int)$materials['template']['id']===(int)$materials['template_id']?(string)($materials['template']['image']??''):'';
        unset($materials['template']);
        if($shared!==''){
            unset($materials[$app==='aigc_product_image'?'scene_image':'style_image']);
            $materials['image_payload']['reference_images']=array_values(array_filter($materials['image_payload']['reference_images'],static fn($uri)=>$uri!==$shared));
        }
        OpenMaterials::resolve($tenant,$user,$materials);
        $native=self::capture($tenant,$user,$prepared['prepared']['image_payload'],$app);
        $budget=OpenToolAdapter::estimateBudget($prepared['estimate']);
        if($budget===null||abs($budget['tenant']-$native['tenant'])>0.000001)throw new OpenException('pricing_unavailable','图片工具价格与平台 SKU 成本不一致',503);
        $budget['snapshot']['native_selection_blob']=$native['snapshot']['native_selection_blob'];
        $budget['snapshot']['native_prepared_blob']=OpenVault::seal(OpenStore::encode(['app_code'=>$app,'tenant_id'=>$tenant,'user_id'=>$user]+$prepared));
        return $budget;
    }
    public static function prepared(int $tenant,int $user,string $app): ?array
    {
        if(!in_array($app,array_merge(self::COMPOSITES,self::MULTI_IMAGE),true))return null;$id=OpenCallContext::id();if($id==='')return null;
        $row=OpenStore::owner('request',$tenant,$user)->where(['request_id'=>$id,'app_code'=>$app,'kind'=>'tool','method'=>'POST','path'=>'/api/v1/tools/'.$app.'/tasks'])->find();if(!$row)return null;
        $blob=OpenStore::json($row['snapshot'])['native_prepared_blob']??'';
        if($blob===''&&in_array($app,self::MULTI_IMAGE,true))throw new OpenException('pricing_snapshot_invalid','试衣请求缺少受理快照',409);
        if($blob==='')return null;
        $data=OpenStore::json(OpenVault::open($blob));
        if(($data['app_code']??'')!==$app||(int)($data['tenant_id']??0)!==$tenant||(int)($data['user_id']??0)!==$user||!isset($data['prepared']['image_payload'],$data['estimate']['user_charge_points'],$data['estimate']['tenant_cost_points']))throw new OpenException('pricing_snapshot_invalid','图片工具参数快照不完整',409);
        return ['prepared'=>$data['prepared'],'estimate'=>$data['estimate']];
    }
    public static function selection(int $tenant,int $user): ?array
    {
        $agent=\app\common\service\app\aigc_llm\agent\AgentToolContext::image($tenant,$user);if($agent!==null)return $agent;
        $id=OpenCallContext::id();if($id==='')return null;
        $row=OpenStore::owner('request',$tenant,$user)->where(['request_id'=>$id,'kind'=>'tool','method'=>'POST'])->find();if(!$row||!in_array($row['app_code'],array_merge(['aigc_image'],self::COMPOSITES,self::MULTI_IMAGE),true))return null;
        return OpenToolPricing::forRequest($row['app_code'],$tenant,$user);
    }
    /** Only accepted, owned, currently leased calls may persist input preprocessing. */
    public static function materializePrepared(int $tenant,int $user,string $app,callable $materialize): array
    {
        return \think\facade\Db::transaction(static function()use($tenant,$user,$app,$materialize){
            $row=OpenStore::owner('request',$tenant,$user)->where(['request_id'=>OpenCallContext::id(),'app_code'=>$app,'kind'=>'tool','method'=>'POST','path'=>'/api/v1/tools/'.$app.'/tasks'])->lock(true)->find();
            if(!$row||!(int)$row['active']||$row['tool_task_id']!=='')throw new OpenException('task_context_invalid','素材处理请求上下文无效',409);
            OpenCallContext::assertLease($row);
            $frozen=self::prepared($tenant,$user,$app);if($frozen===null)throw new OpenException('pricing_snapshot_invalid','图片工具参数快照不存在',409);
            $snapshot=OpenStore::json($row['snapshot']);
            if(empty($snapshot['native_materialized'])){
                $frozen['prepared']=$materialize($frozen['prepared']);
                $snapshot['native_prepared_blob']=OpenVault::seal(OpenStore::encode(['app_code'=>$app,'tenant_id'=>$tenant,'user_id'=>$user]+$frozen));
                $snapshot['native_materialized']=true;
                OpenAdmission::finish($row['request_id'],['snapshot'=>OpenStore::encode($snapshot)]);
            }
            return $frozen;
        });
    }
    /** Only an owned accepted native image request may replace a live market lookup. */
    public static function assertQuantity(int $tenant,int $quantity): void
    {
        $frozen=self::selection($tenant,(int)(request()->userId??0));
        if($frozen&&(int)($frozen['selection']['_open_image']['quantity']??0)!==max(1,$quantity))throw new OpenException('pricing_snapshot_conflict','图片数量与受理快照不一致',409);
    }
    public static function market(int $tenant,array $params,string $runtime): ?array
    {
        $frozen=self::selection($tenant,(int)(request()->userId??0));if(!$frozen)return null;
        $selection=$frozen['selection'];$image=$selection['_open_image']??null;
        if(!is_array($image)||($image['runtime']??'')!==$runtime||!isset($image['market']['product'],$image['market']['sku'],$image['market']['tenant_price']))throw new OpenException('pricing_snapshot_invalid','图片价格快照不完整',409);
        foreach(['channel','model_id'] as $field)if(isset($params[$field])&&$params[$field]!==''&&(string)$params[$field]!==$selection['channel']['code'])throw new OpenException('pricing_snapshot_conflict','图片通道与受理快照不一致',409);
        foreach(['quality','ratio'] as $field)if(isset($params[$field])&&$params[$field]!==''&&(string)$params[$field]!==$selection['spec'][$field])throw new OpenException('pricing_snapshot_conflict','图片规格与受理快照不一致',409);
        foreach(['market_sku_id','sku_id'] as $field)if(!empty($params[$field])&&self::skuId($params[$field])!== (int)$image['market']['sku']['id'])throw new OpenException('pricing_snapshot_conflict','图片 SKU 与受理快照不一致',409);
        if(!empty($params['market_product_id'])&&(int)$params['market_product_id']!==(int)$image['market']['product']['id'])throw new OpenException('pricing_snapshot_conflict','图片商品与受理快照不一致',409);
        if(isset($params['quantity'])&&Channels::normalizeQuantity($params['quantity'])!==(int)$image['quantity'])throw new OpenException('pricing_snapshot_conflict','图片数量与受理快照不一致',409);
        return $image['market'];
    }
    /** Preserve the existing native market_sku:<id> alias convention. */
    private static function skuId(mixed $value): int {return (int)(is_string($value)?preg_replace('/^market_sku:/','',$value):$value);}
}
