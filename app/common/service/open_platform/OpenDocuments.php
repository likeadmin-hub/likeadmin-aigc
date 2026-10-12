<?php
namespace app\common\service\open_platform;
use think\facade\Db;
final class OpenDocuments
{
    public static function save(int $tenantId,array $params): array
    {
        $slug=(string)($params['slug']??'');$title=trim((string)($params['title']??''));if(!preg_match('/^[a-z0-9_-]{1,180}$/D',$slug)||$title==='')throw new OpenException('invalid_document','文档编码和标题无效');
        return Db::transaction(function()use($tenantId,$params,$slug,$title){$id=(int)($params['id']??0);$q=OpenStore::table('document')->where('tenant_id',$tenantId);$old=$id?(clone $q)->where('id',$id)->lock(true)->find():null;if($id&&!$old)throw new OpenException('not_found','文档不存在',404);if($old&&$old['status']!=='draft')throw new OpenException('immutable_version','发布内容不可修改，请创建新版本',409);
            $values=['slug'=>$slug,'title'=>mb_substr($title,0,255),'category'=>mb_substr((string)($params['category']??'指南'),0,100),'content'=>(string)($params['content']??''),'contract_code'=>(string)($params['contract_code']??''),'sort'=>(int)($params['sort']??0),'update_time'=>time()];if(strlen($values['content'])>1000000)throw new OpenException('invalid_document','文档过长');
            if($old)$q->where('id',$id)->update($values);else $id=OpenStore::table('document')->insertGetId($values+['tenant_id'=>$tenantId,'version'=>(int)(clone $q)->where('slug',$slug)->max('version')+1,'status'=>'draft','create_time'=>time()]);return OpenStore::table('document')->where('id',$id)->find();});
    }
    public static function publish(int $tenantId,int $id): array
    {
        return Db::transaction(function()use($tenantId,$id){$row=OpenStore::table('document')->where(['tenant_id'=>$tenantId,'id'=>$id])->lock(true)->find();if(!$row||trim($row['content'])==='')throw new OpenException('invalid_document','文档不存在或内容为空');OpenStore::table('document')->where(['tenant_id'=>$tenantId,'slug'=>$row['slug'],'status'=>'published'])->update(['status'=>'archived','update_time'=>time()]);OpenStore::table('document')->where('id',$id)->update(['status'=>'published','update_time'=>time()]);return ['id'=>$id,'version'=>$row['version'],'status'=>'published'];});
    }
    public static function publicLists(int $tenantId,string $search=''): array
    {
        $q=OpenStore::table('document')->whereIn('tenant_id',[0,$tenantId])->where('status','published');if($search!=='')$q->where('title|content','like','%'.mb_substr($search,0,100).'%');$rows=$q->order('tenant_id','asc')->order('sort','asc')->select()->toArray();$allowed=array_column(OpenManagement::contractsForTenant($tenantId),'code');$items=[];foreach($rows as $row){if($row['contract_code']!==''&&!in_array($row['contract_code'],$allowed,true))continue;$items[$row['slug']]=$row;}return array_values($items);
    }

    /** Internal receipt guidance is not part of the public protocol description. */
    public static function publicResponses(array $config): array
    {
        $responses=(array)($config['responses']??[]);
        if(!empty($config['compiler']))foreach($responses as $status=>&$response)$response['description']='HTTP '.$status.' response';
        unset($response);return $responses;
    }

    public static function openapi(int $tenantId): array
    {
        $paths=[];$config=OpenStore::settings($tenantId);$global=OpenStore::settings(0);
        foreach(OpenStore::table('contract')->where('status','published')->order('id','asc')->select()->toArray() as $row){
            $c=OpenContracts::decodeConfig($row['config']);$app=$c['app_code']??'';
            if($row['kind']==='tool'&&!OpenToolRegistry::supported($row['method'],$row['path']))continue;
            if($row['kind']==='tool'&&(!in_array($app,$config['apps'],true)||!in_array($app,$global['apps'],true)))continue;
            if($row['kind']==='market'&&(int)$row['product_id']>0&&(!in_array((string)$row['product_id'],$config['models'],true)||!in_array((string)$row['product_id'],$global['models'],true)))continue;
            $input=$c['input'];$method=strtolower($row['method']);$op=['operationId'=>$row['code'],'summary'=>$c['title']??$row['code'],'description'=>$c['description']??'','tags'=>[$row['kind']==='tool'?OpenToolRegistry::get($app)['name']:'商城 API'],'security'=>[['apiKey'=>[]]],'responses'=>self::publicResponses($c),'x-contract-version'=>(int)$row['version'],'x-billable'=>(bool)$c['billable'],'x-billing-description'=>$c['billing_description']??''];
            if($row['kind']==='tool'&&!empty(OpenToolRegistry::get($app)['limitations']))$op['x-capability-limitations']=OpenToolRegistry::get($app)['limitations'];
            preg_match_all('/\{([a-z_]+)\}/',$row['path'],$captures);
            foreach($captures[1] as $name)$op['parameters'][]=['name'=>$name,'in'=>'path','required'=>true,'schema'=>['type'=>'string'],'description'=>$name==='task_id'?'此用户在对应能力中创建的任务 ID':'资源 ID'];
            if($method==='get'){
                foreach($input['properties']??[] as $name=>$field)$op['parameters'][]=['name'=>$name,'in'=>'query','required'=>in_array($name,$input['required']??[],true),'schema'=>$field,'description'=>$field['description']??'','example'=>$c['example'][$name]??$field['example']??$field['default']??null];
                foreach($op['parameters']??[] as &$parameter)if(array_key_exists('example',$parameter)&&$parameter['example']===null)unset($parameter['example']);unset($parameter);
            }else{
                $type=OpenContracts::contentType($c);
                $op['requestBody']=['required'=>!empty($input['required']),'content'=>[$type=>['schema'=>$input,'example'=>(object)$c['example']]]];
                if(!empty($c['billable']))$op['parameters'][]=['name'=>'Idempotency-Key','in'=>'header','required'=>false,'schema'=>['type'=>'string','maxLength'=>200],'description'=>'同一用户及接口作用域的幂等键'];
            }
            $variant=['code'=>$row['code'],'version'=>$row['version'],'product_id'=>$row['product_id'],'model'=>$c['model']??null,'when'=>$c['when']??[],'operation'=>$op];
            if(isset($paths[$row['path']][$method])){$existing=&$paths[$row['path']][$method];$existing['x-contract-variants'][]=$variant;foreach($op['requestBody']['content']??[] as $type=>$content){if(!isset($existing['requestBody']['content'][$type])){$existing['requestBody']['content'][$type]=$content;continue;}$old=$existing['requestBody']['content'][$type]['schema'];$existing['requestBody']['content'][$type]['schema']=['anyOf'=>array_merge($old['anyOf']??[$old],[$content['schema']])];$existing['requestBody']['content'][$type]['examples'][$row['code']]=['value'=>(object)$c['example']];}$existing['responses']=array_replace($existing['responses'],$op['responses']);unset($existing);continue;}
            $op['x-contract-variants']=[$variant];$paths[$row['path']][$method]=$op;
        }
        return ['openapi'=>'3.0.3','info'=>['title'=>'LikeAdmin 开放平台','version'=>'1.0.0'],'paths'=>(object)$paths,'components'=>['securitySchemes'=>['apiKey'=>['type'=>'http','scheme'=>'bearer']]]];
    }
}
