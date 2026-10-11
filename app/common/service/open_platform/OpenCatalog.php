<?php
namespace app\common\service\open_platform;
final class OpenCatalog
{
    public static function forUser(int $tenantId,int $userId): array
    {
        $items=[];$config=OpenStore::settings($tenantId);
        foreach(OpenToolRegistry::all() as $app=>$tool){$available=false;$reason='';try{OpenToolAdapter::authorize($tenantId,$userId,$app);$available=true;}catch(OpenException $e){$reason=$e->getMessage();}
            $published=OpenStore::table('contract')->where(['kind'=>'tool','status'=>'published'])->column('path');$ops=[];foreach($tool['operations'] as $route=>$operation){[$verb,$suffix]=explode(' ',$route,2);$ops[]=['method'=>$verb,'path'=>'/api/v1/tools/'.$app.'/'.$suffix,'billable'=>$operation['billable'],'schema'=>$operation['input'],'description'=>$operation['description'],'published'=>in_array('/api/v1/tools/'.$app.'/'.$suffix,$published,true)];}
            $items[]=['kind'=>'tool','code'=>$app,'name'=>$tool['name'],'available'=>$available&&count(array_filter($ops,fn($o)=>$o['published']))>0,'reason'=>$available&&count(array_filter($ops,fn($o)=>$o['published']))===0?'操作尚未发布':$reason,'operations'=>$ops];}
        foreach(OpenStore::table('contract')->where(['status'=>'published','kind'=>'market'])->order('id','desc')->select()->toArray() as $contract){$c=OpenStore::json($contract['config']);$items[]=['kind'=>'market','code'=>$contract['code'],'name'=>$c['title']??$contract['code'],'available'=>$config['enabled']&&in_array((string)$contract['product_id'],$config['models'],true),'method'=>$contract['method'],'path'=>$contract['path'],'version'=>$contract['version'],'schema'=>$c['input']??[],'description'=>$c['description']??''];}
        return $items;
    }
}
