<?php
namespace app\common\service\open_platform;

use app\common\service\power\{PowerMarketService,TenantPowerMarketService};

/** Live, read-only references. Discovery does not create contracts or authorize calls. */
final class OpenDocumentationCatalog
{
    public static function build(int $tenantId): array
    {
        $local=OpenStore::settings($tenantId);$global=OpenStore::settings(0);
        $published=OpenStore::table('contract')->where('status','published')->order('id','asc')->select()->toArray();
        $byProduct=[];$byTool=[];
        foreach($published as $row){if($row['kind']==='market')$byProduct[(int)$row['product_id']][]=$row;else $byTool[$row['method'].' '.$row['path']]=$row;}
        $products=TenantPowerMarketService::catalogueProducts($tenantId);$modelCounts=[];
        foreach($products as $product)if($product['resource_type']==='model')$modelCounts[$product['upstream_model_code']]=($modelCounts[$product['upstream_model_code']]??0)+1;
        $entries=[];
        foreach($products as $product){
            $id=(int)$product['id'];$model=$product['resource_type']==='model';
            $category=$model?(['text'=>'文本生成','image'=>'图片生成','video'=>'视频生成'][$product['model_type']??'']??'其他模型'):(PowerMarketService::appCategory($product)['category_name']??'其他应用');
            $meta=OpenStore::json($product['source_payload']??[])['market_metadata']??[];
            $category=trim((string)$category)?:'其他应用';
            $group=$model?$category:(string)($meta['app_name']??$product['upstream_app_code']??'应用接口');
            $selected=in_array((string)$id,$local['models'],true)&&in_array((string)$id,$global['models'],true);
            $compiled=OpenMarketContractCompiler::compileProduct($product,[],[],($modelCounts[$product['upstream_model_code']??'']??0)>1);
            $routes=[];foreach($compiled['candidates'] as $candidate){$route=$candidate['method'].' '.$candidate['path'];$routes[$route][]=$candidate;}
            foreach($byProduct[$id]??[] as $row){$route=$row['method'].' '.$row['path'];if(!isset($routes[$route]))$routes[$route]=[];}
            if(!$routes)$routes['reference']=[];
            foreach($routes as $route=>$candidates){
                $variants=[];
                foreach($byProduct[$id]??[] as $row)if($row['method'].' '.$row['path']===$route){$operation=self::published($row);$liveSkuIds=array_map('intval',array_column($product['skus'],'id'));$skuIds=array_map('intval',$operation['x-sku-ids']);$saleReady=empty($operation['x-billable'])||($skuIds&&!array_diff($skuIds,$liveSkuIds));$operation['x-callable']=$selected&&$global['enabled']&&$local['enabled']&&$saleReady;$variants[]=['code'=>$row['code'],'version'=>(int)$row['version'],'model'=>$compiled['model_code'],'operation'=>$operation];}
                $publishedSkus=[];foreach($variants as $variant)foreach($variant['operation']['x-sku-ids'] as $sku)$publishedSkus[$sku]=true;
                foreach($candidates as $candidate){if(isset($publishedSkus[$candidate['sku_id']]))continue;
                    $operation=self::reference($candidate['code'],(string)$product['name'],$candidate['input'],$candidate['example'],$candidate['responses']??[]);
                    $operation['x-billable']=true;$operation['method']=$candidate['method'];$operation['path']=$candidate['path'];$operation['x-published']=false;$operation['x-callable']=false;
                    $variants[]=['code'=>$candidate['code'],'version'=>null,'model'=>$compiled['model_code'],'operation'=>$operation];
                }
                $first=$variants[0]['operation']??self::reference('documentation.market.'.$id,(string)$product['name'],$compiled['input'],[],[]);
                [$method,$path]=array_pad(explode(' ',$route,2),2,'');if($route==='reference'){$method='';$path='';}
                $first['method']=$method;$first['path']=$path;$first['key']='market:'.$id.':'.hash('crc32b',$route);
                $first['summary']=(string)$product['name'];$first['description']=self::text((string)($product['description']??''));
                $first['section']=$model?'模型 API':'应用 API';$first['category']=$category;$first['group']=self::text($group);$first['model']=$compiled['model_code'];
                $first['x-catalog-entry']=true;$first['x-product-id']=$id;$first['x-api-selected']=$selected;
                $first['x-contract-variants']=$variants;$first['x-prices']=array_map(static fn($sku)=>['sku_key'=>$sku['sku_key'],'price'=>(float)$sku['sale_points'],'unit'=>$sku['usage_unit'],'unit_size'=>(float)$sku['usage_unit_size']],$product['skus']);
                $entries[]=self::sanitize($first);
            }
        }
        foreach(OpenToolRegistry::all() as $app=>$tool)foreach($tool['operations'] as $route=>$registered){
            if(!OpenToolRegistry::executable($registered))continue;
            [$method,$suffix]=explode(' ',$route,2);$path='/api/v1/tools/'.$app.'/'.$suffix;$row=$byTool[$method.' '.$path]??null;
            $op=$row?self::published($row):self::reference('documentation.tool.'.$app.'.'.$suffix,$tool['name'].' · '.($registered['description']??$suffix),$registered['input'],[],[]);
            $op['key']='tool:'.$method.' '.$path;$op['method']=$method;$op['path']=$path;$op['section']='工具 API';$op['category']=$tool['name'];$op['group']=$tool['name'];
            $op['description']=$op['description']??$registered['description']??'';$op['x-billable']=(bool)$registered['billable'];$op['x-published']=$row!==null;if(!$row&&$method==='GET'){unset($op['requestBody']);foreach($registered['input']['properties']??[] as $name=>$field)$op['parameters'][]=['name'=>$name,'in'=>'query','required'=>in_array($name,$registered['input']['required']??[],true),'schema'=>$field,'description'=>$field['description']??''];}preg_match_all('/\{([a-z_]+)\}/',$path,$captures);foreach($row?[]:$captures[1] as $name)$op['parameters'][]=['name'=>$name,'in'=>'path','required'=>true,'schema'=>['type'=>'string']];$op['x-api-selected']=in_array($app,$local['apps'],true)&&in_array($app,$global['apps'],true);
            $history=in_array($route,['GET tasks','GET tasks/{task_id}','GET results'],true);$op['x-builtin-history']=$history;$op['x-callable']=$history||($row!==null&&$op['x-api-selected']&&$local['enabled']&&$global['enabled']&&OpenToolRegistry::ready($app,$registered));if($history)$op['x-billing-description']='只读历史记录，不新增生成，不重复计费；只能读取当前用户的任务与结果。';
            $op['x-catalog-entry']=true;$entries[]=self::sanitize($op);
        }
        return $entries;
    }

    private static function published(array $row): array
    {
        $config=OpenContracts::decodeConfig($row['config']);$op=self::reference($row['code'],$config['title']??$row['code'],$config['input'],$config['example']??[],OpenDocuments::publicResponses($config));
        $type=OpenContracts::contentType($config);$body=reset($op['requestBody']['content']);$op['requestBody']['content']=[$type=>$body];$op['description']=$config['description']??'';$op['x-billable']=(bool)($config['billable']??false);$op['x-published']=true;$op['x-contract-version']=(int)$row['version'];$op['x-billing-description']=$config['billing_description']??'';$op['x-sku-ids']=array_column($config['billing']??[],'sku_id');
        if($row['method']==='GET'){unset($op['requestBody']);foreach($config['input']['properties']??[] as $name=>$field)$op['parameters'][]=['name'=>$name,'in'=>'query','required'=>in_array($name,$config['input']['required']??[],true),'schema'=>$field,'description'=>$field['description']??'','example'=>$config['example'][$name]??$field['example']??$field['default']??null];}
        preg_match_all('/\{([a-z_]+)\}/',$row['path'],$captures);foreach($captures[1] as $name)$op['parameters'][]=['name'=>$name,'in'=>'path','required'=>true,'schema'=>['type'=>'string']];
        return $op;
    }
    private static function reference(string $code,string $title,array $input,array|\stdClass $example,array $responses): array
    {
        $type=OpenContracts::contentType(['input'=>$input]);return ['operationId'=>$code,'summary'=>$title,'description'=>'','x-published'=>false,'x-callable'=>false,'responses'=>(object)$responses,'requestBody'=>['required'=>!empty($input['required']),'content'=>[$type=>['schema'=>$input,'example'=>(object)$example]]]];
    }
    private static function text(string $value): string
    {
        return preg_replace('#https?://[^\s<>"\x27]+#u','https://example.com/resource',$value)??$value;
    }
    private static function sanitize(array $data): array
    {
        $data=OpenPolicy::publicData($data);
        foreach($data as $key=>&$value){if(is_array($value))$value=self::sanitize($value);elseif($value instanceof \stdClass)$value=(object)self::sanitize((array)$value);elseif(is_string($value))$value=self::text($value);}
        unset($value);return $data;
    }
}
