<?php
namespace app\common\service\open_platform;
use app\common\service\power\TenantPowerMarketService;
use app\common\service\update\UpdateSourceClient;
use think\facade\Db;
/** Fixed read adapters never expose the shared upstream account's tasks or wallet. */
final class OpenDirectory
{
    public static function supports(string $method,string $path): bool
    {
        return $method==='GET'&&in_array($path,['/api/v1/models','/api/v1/apps','/api/v1/tasks','/api/v1/user/balance','/api/v1/user/usage'],true);
    }
    public static function history(string $path): bool {return in_array($path,['/api/v1/tasks','/api/v1/user/balance','/api/v1/user/usage'],true);}
    public static function execute(array $key,string $path,array $params): array
    {
        $tenant=(int)$key['tenant_id'];$user=(int)$key['user_id'];
        if(!self::history($path))OpenStore::requireEnabled($tenant);
        if($path==='/api/v1/user/balance')return self::success(['available_points'=>(float)Db::name('user')->where(['tenant_id'=>$tenant,'id'=>$user])->value('user_money')]);
        if($path==='/api/v1/user/usage')return self::usage($tenant,$user,$params);
        if($path==='/api/v1/tasks')return self::tasks($key,$params);
        $products=self::products($key);$source=UpdateSourceClient::getSource();$wire=(new OpenTransport())->send(['base_url'=>$source['active_base_url'],'api_key'=>$source['active_api_key'],'ssl_verify'=>UpdateSourceClient::sslVerify($source)],'GET',$path,$params);
        if(OpenGateway::wireFailed($wire['status'],OpenStore::json($wire['body']),[]))return $wire;
        $body=OpenStore::json($wire['body']);$body['data']=self::filter($path,(array)($body['data']??[]),$products);
        $wire['body']=OpenStore::encode($body);return $wire;
    }
    public static function products(array $key): array
    {
        $tenant=(int)$key['tenant_id'];$local=OpenStore::settings($tenant);$global=OpenStore::settings(0);$scopes=OpenStore::json($key['scopes']);$products=[];
        foreach(OpenStore::table('contract')->where(['kind'=>'market','status'=>'published'])->where('product_id','>',0)->select()->toArray() as $row){$id=(string)$row['product_id'];if(isset($products[$id])||!in_array($id,$global['models'],true)||!in_array($id,$local['models'],true)||$scopes&&!in_array('market',$scopes,true)&&!in_array($id,$scopes,true))continue;
            $product=TenantPowerMarketService::detail($tenant,(int)$id);if(!$product||empty($product['status'])||!array_filter($product['skus'],fn($s)=>!empty($s['sale_status'])))continue;$products[$id]=$product;
        }return $products;
    }
    public static function filter(string $path,array $items,array $products): array
    {
        $out=[];foreach($items as $item){if(!is_array($item))continue;
            if($path==='/api/v1/models'){
                foreach($products as $product)if((string)($product['upstream_model_code']??'')===(string)($item['model_code']??$item['id']??'')&&(string)($product['upstream_channel_code']??'')===(string)($item['channel_code']??'')){
                    if(isset($item['pricing']))$item['pricing']=self::prices($product);$out[]=$item;break;
                }
            }else{
                $allowed=[];foreach($products as $product)if(($product['upstream_app_code']??'')===($item['code']??''))$allowed[(string)$product['upstream_api_code']]=$product;
                $apis=[];foreach($item['apis']??[] as $api)if(isset($allowed[$api['code']??'']))$apis[]=$api;
                if($apis){$item['apis']=$apis;$out[]=$item;}
            }
        }return array_values($out);
    }
    private static function prices(array $product): array
    {
        $skus=array_values(array_filter($product['skus'],fn($s)=>!empty($s['sale_status'])));$input=$skus[0];$output=$input;
        foreach($skus as $sku){$hint=strtolower($sku['sku_key'].' '.$sku['usage_unit']);if(str_contains($hint,'input')||str_contains($hint,'prompt'))$input=$sku;if(str_contains($hint,'output')||str_contains($hint,'completion'))$output=$sku;}
        if(str_contains(strtolower($input['usage_unit']),'token'))return ['billing_type'=>1,'per_1k_input'=>(float)$input['sale_points']*1000000/max(1,(float)$input['usage_unit_size']),'per_1k_output'=>(float)$output['sale_points']*1000000/max(1,(float)$output['usage_unit_size']),'fixed_points'=>0];
        return ['billing_type'=>count($skus)===1?2:3,'fixed_points'=>count($skus)===1?(float)$input['sale_points']/max(1,(float)$input['usage_unit_size']):0,'pricing_matrix'=>array_map(fn($sku)=>['sku_key'=>$sku['sku_key'],'unit_price'=>(float)$sku['sale_points'],'usage_unit'=>$sku['usage_unit'],'usage_unit_size'=>(float)$sku['usage_unit_size'],'params'=>OpenStore::json($sku['locked_params']??[])],$skus)];
    }
    private static function tasks(array $key,array $params): array
    {
        $page=max(1,(int)($params['page']??1));$size=min(100,max(1,(int)($params['per_page']??20)));$q=OpenStore::owner('request',(int)$key['tenant_id'],(int)$key['user_id'])->where('kind','market')->where('upstream_task_id','<>','');
        $scopes=OpenStore::json($key['scopes']);if($scopes&&!in_array('market',$scopes,true)){$ids=OpenStore::table('contract')->whereIn('product_id',array_values(array_filter($scopes,'ctype_digit')))->column('id');$q->whereIn('contract_id',$ids?:[-1]);}
        $states=['pending'=>['accepted','pending_confirmation'],'processing'=>['running','pending_usage'],'completed'=>['success','completed'],'failed'=>['failed'],'cancelled'=>['cancelled','canceled']];if(isset($states[$params['status']??'']))$q->whereIn('status',$states[$params['status']]);
        if(!empty($params['type']))$q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(snapshot, ?)) = ?', ['$.contract.task_type',(string)$params['type']]);$total=(clone $q)->count();$rows=$q->order('id','desc')->page($page,$size)->select()->toArray();$tasks=[];
        foreach($rows as $row){$snapshot=OpenStore::json($row['snapshot']);$contract=$snapshot['contract']??[];$type=(string)($contract['task_type']??'');if(!empty($params['type'])&&$params['type']!==$type)continue;
            $state='processing';foreach($states as $label=>$values)if(in_array($row['status'],$values,true))$state=$label;
            $tasks[]=['task_id'=>$row['upstream_task_id'],'model_code'=>$contract['model']??'','channel_code'=>$contract['channel']??'','type_code'=>$type,'call_type'=>'async','status'=>$state,'actual_points'=>(float)$row['actual_user'],'response_time_ms'=>(int)$row['duration_ms'],'created_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$row['create_time']),'completed_at'=>$row['finish_time']?gmdate('Y-m-d\TH:i:s\Z',(int)$row['finish_time']):null];
        }return self::success(['lists'=>$tasks,'total'=>$total,'page'=>$page,'per_page'=>$size]);
    }
    private static function usage(int $tenant,int $user,array $params): array
    {
        $start=(string)($params['start_date']??date('Y-m-d',strtotime('-30 days')));$end=(string)($params['end_date']??date('Y-m-d'));foreach([$start,$end] as $date){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new OpenException('invalid_request','日期必须使用 YYYY-MM-DD');}if($start>$end)throw new OpenException('invalid_request','开始日期晚于结束日期');
        $q=OpenStore::owner('request',$tenant,$user)->whereIn('kind',['market','tool'])->whereBetween('create_time',[strtotime($start),strtotime($end.' 23:59:59')])->where('billing_status','<>','none');$completed=(clone $q)->whereIn('status',['success','completed','partial_success'])->count();$ids=[];foreach((clone $q)->column('consumption_ids') as $json)$ids=array_merge($ids,OpenStore::json($json));$tokens=0;
        if($ids)foreach(Db::name('ai_consumption_log')->where(['tenant_id'=>$tenant,'user_id'=>$user])->whereIn('id',array_unique($ids))->field('usage_snapshot,price_snapshot')->select()->toArray() as $record){$usage=OpenStore::json($record['usage_snapshot']);$total=(int)($usage['total_tokens']??0);$parts=0;$rules=OpenStore::json($record['price_snapshot'])['rules']??[];foreach($rules as $i=>$rule){$path=(string)($rule['usage_path']??'');$units=(int)($usage[$i]['units']??0);if(str_contains($path,'total_tokens'))$total=max($total,$units);elseif(preg_match('/(?:input|output|prompt|completion)_tokens$/',$path))$parts+=$units;}$tokens+=$total?:$parts;}
        $calls=(clone $q)->count();return self::success(['period'=>['start'=>$start,'end'=>$end],'total_calls'=>$calls,'completed'=>$completed,'failed'=>$calls-$completed,'total_points'=>round((float)(clone $q)->sum('actual_user'),2),'total_tokens'=>$tokens]);
    }
    private static function success(array $data): array {return ['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>OpenStore::encode(['code'=>1,'msg'=>'success','data'=>$data])];}
}
