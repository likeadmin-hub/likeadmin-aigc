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
        $q=OpenStore::table('document')->whereIn('tenant_id',[0,$tenantId])->where('status','published');if($search!=='')$q->where('title|content','like','%'.mb_substr($search,0,100).'%');$rows=$q->order('tenant_id','asc')->order('sort','asc')->select()->toArray();$items=[];foreach($rows as $row)$items[$row['slug']]=$row;return array_values($items);
    }
    public static function openapi(int $tenantId): array
    {
        $paths=[];$config=OpenStore::settings($tenantId);foreach(OpenStore::table('contract')->where(['status'=>'published','kind'=>'market'])->select()->toArray() as $row){if($row['product_id']>0&&!in_array((string)$row['product_id'],$config['models'],true))continue;$c=OpenStore::json($row['config']);$paths[$row['path']][strtolower($row['method'])]=['operationId'=>$row['code'],'summary'=>$c['title']??$row['code'],'description'=>$c['description']??'','security'=>[['apiKey'=>[]]],'requestBody'=>['content'=>['application/json'=>['schema'=>$c['input'],'example'=>$c['example']]]],'responses'=>$c['responses']];}
        foreach(OpenToolRegistry::all() as $app=>$tool){if(!in_array($app,$config['apps'],true))continue;$published=OpenStore::table('contract')->where(['kind'=>'tool','status'=>'published'])->column('path');foreach($tool['operations'] as $route=>$op){[$verb,$suffix]=explode(' ',$route,2);if(!in_array('/api/v1/tools/'.$app.'/'.$suffix,$published,true))continue;$paths['/api/v1/tools/'.$app.'/'.$suffix][strtolower($verb)]=['operationId'=>$app.'_'.str_replace(['/','{','}'],['_','',''],$suffix),'summary'=>$op['description'],'security'=>[['apiKey'=>[]]],'requestBody'=>['content'=>['application/json'=>['schema'=>$op['input']]]],'responses'=>['200'=>['description'=>'工具响应，包含 request_id 和 data']]];}}
        return ['openapi'=>'3.0.3','info'=>['title'=>'LikeAdmin 开放平台','version'=>'1.0.0'],'paths'=>(object)$paths,'components'=>['securitySchemes'=>['apiKey'=>['type'=>'http','scheme'=>'bearer']]]];
    }
}
