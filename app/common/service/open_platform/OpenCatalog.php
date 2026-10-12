<?php
namespace app\common\service\open_platform;
final class OpenCatalog
{
    /** Console discovery includes on-shelf market products without publishing executable routes. */
    public static function forConsole(int $tenantId,int $userId): array
    {
        $existing=self::forUser($tenantId,$userId);$items=[];$byProduct=[];
        $contracts=OpenStore::table('contract')->where(['kind'=>'market','status'=>'published'])->column('product_id','code');
        foreach($existing as $item){
            if($item['kind']==='tool'){$item['category_code']='tools';$item['category_name']='工具 API';$items[]=$item;continue;}
            $id=(int)($contracts[$item['code']]??0);$item['published']=true;
            if($id>0)$byProduct[$id][]=$item;
            else $items[]=array_replace($item,['category_code'=>'other','category_name'=>'其他','operations'=>[$item]]);
        }
        $labels=array_column(\app\common\service\power\PowerMarketService::appCategories(),'name','code');
        $local=OpenStore::settings($tenantId);$global=OpenStore::settings(0);
        foreach(\app\common\service\power\TenantPowerMarketService::catalogueProducts($tenantId) as $product){
            $id=(int)$product['id'];$model=$product['resource_type']==='model';
            $category=$model?(string)($product['model_type']??'other'):(string)(\app\common\service\power\PowerMarketService::appCategory($product)['category_code']??'other');
            if(!isset($labels[$category]))$category='other';
            $ops=$byProduct[$id]??[];$enabled=$global['enabled']&&$local['enabled']&&in_array((string)$id,$global['models'],true)&&in_array((string)$id,$local['models'],true);
            $prices=[];foreach($product['skus'] as $sku)$prices[]=['sku_key'=>$sku['sku_key'],'price'=>(float)$sku['sale_points'],'usage_unit'=>$sku['usage_unit'],'usage_unit_size'=>(float)$sku['usage_unit_size']];
            $items[]=['kind'=>'market','code'=>'market_product_'.$id,'product_id'=>$id,'name'=>$product['name'],'resource_type'=>$product['resource_type'],'model_code'=>$product['upstream_model_code']??'','app_code'=>$product['upstream_app_code']??'','api_code'=>$product['upstream_api_code']??'','category_code'=>$category,'category_name'=>$labels[$category],'description'=>$product['description']??'','available'=>$enabled&&count($ops)>0,'reason'=>!$ops?'接口尚未发布':(!$enabled?'本站尚未开通此 API':''),'prices'=>$prices,'operations'=>$ops];
        }
        return $items;
    }
    public static function forUser(int $tenantId,int $userId): array
    {
        $items=[];$config=OpenStore::settings($tenantId);$global=OpenStore::settings(0);
        $contracts=OpenStore::table('contract')->where('status','published')->order('id','desc')->select()->toArray();$toolContracts=[];
        foreach($contracts as $c)if($c['kind']==='tool'&&!isset($toolContracts[$c['method'].' '.$c['path']]))$toolContracts[$c['method'].' '.$c['path']]=$c;
        foreach(OpenToolRegistry::all() as $app=>$tool){$available=false;$reason='';try{OpenToolAdapter::authorize($tenantId,$userId,$app);$available=true;}catch(OpenException $e){$reason=$e->getMessage();}
            $ops=[];foreach($tool['operations'] as $route=>$operation){if(!OpenToolRegistry::executable($operation))continue;[$verb,$suffix]=explode(' ',$route,2);$path='/api/v1/tools/'.$app.'/'.$suffix;$c=$toolContracts[$verb.' '.$path]??null;$published=$c?OpenContracts::decodeConfig($c['config']):[];
                $ops[]=['method'=>$verb,'path'=>$path,'billable'=>$operation['billable'],'schema'=>$published['input']??$operation['input'],'example'=>$published['example']??new \stdClass(),'responses'=>$published['responses']??[],'description'=>$published['description']??$operation['description'],'published'=>$c!==null,'execution_ready'=>OpenToolRegistry::ready($app,$operation),'code'=>$c['code']??null,'executor'=>$published['executor']??'tool','content_type'=>OpenContracts::contentType($published),'version'=>$c['version']??null];}
            $any=count(array_filter($ops,fn($o)=>$o['published']&&$o['execution_ready']))>0;
            $publishedRoutes=array_map(static fn($op)=>$op['method'].' '.substr($op['path'],strlen('/api/v1/tools/'.$app.'/')),array_filter($ops,static fn($op)=>$op['published']));
            $limits=$app==='aigc_music'?OpenToolRegistry::capabilities($app,[],$publishedRoutes)['limitations']:($tool['limitations']??new \stdClass());
            $items[]=['kind'=>'tool','code'=>$app,'name'=>$tool['name'],'limitations'=>$limits,'available'=>$available&&$any,'reason'=>$available&&!$any?'操作尚未发布或运行依赖不可用':$reason,'operations'=>$ops];}
        foreach($contracts as $contract){if($contract['kind']!=='market')continue;$c=OpenContracts::decodeConfig($contract['config']);$product=(string)$contract['product_id'];$items[]=['kind'=>'market','executor'=>$c['executor']??'json','code'=>$contract['code'],'name'=>$c['title']??$contract['code'],'available'=>$global['enabled']&&$config['enabled']&&(($c['executor']??'')==='directory'||in_array($product,$config['models'],true)&&in_array($product,$global['models'],true)),'method'=>$contract['method'],'path'=>$contract['path'],'version'=>$contract['version'],'schema'=>$c['input']??[],'example'=>$c['example']??new \stdClass(),'responses'=>OpenDocuments::publicResponses($c),'billable'=>$c['billable']??false,'description'=>$c['description']??''];}
        return $items;
    }
}
