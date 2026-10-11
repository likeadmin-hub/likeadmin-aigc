<?php
namespace app\common\service\open_platform;
final class OpenCatalog
{
    public static function forUser(int $tenantId,int $userId): array
    {
        $items=[];$config=OpenStore::settings($tenantId);$global=OpenStore::settings(0);
        $contracts=OpenStore::table('contract')->where('status','published')->order('id','desc')->select()->toArray();$toolContracts=[];
        foreach($contracts as $c)if($c['kind']==='tool'&&!isset($toolContracts[$c['method'].' '.$c['path']]))$toolContracts[$c['method'].' '.$c['path']]=$c;
        foreach(OpenToolRegistry::all() as $app=>$tool){$available=false;$reason='';try{OpenToolAdapter::authorize($tenantId,$userId,$app);$available=true;}catch(OpenException $e){$reason=$e->getMessage();}
            $ops=[];foreach($tool['operations'] as $route=>$operation){[$verb,$suffix]=explode(' ',$route,2);$path='/api/v1/tools/'.$app.'/'.$suffix;$c=$toolContracts[$verb.' '.$path]??null;$published=$c?OpenStore::json($c['config']):[];
                $ops[]=['method'=>$verb,'path'=>$path,'billable'=>$operation['billable'],'schema'=>$published['input']??$operation['input'],'example'=>$published['example']??new \stdClass(),'responses'=>$published['responses']??[],'description'=>$published['description']??$operation['description'],'published'=>$c!==null,'version'=>$c['version']??null];}
            $any=count(array_filter($ops,fn($o)=>$o['published']))>0;$items[]=['kind'=>'tool','code'=>$app,'name'=>$tool['name'],'available'=>$available&&$any,'reason'=>$available&&!$any?'操作尚未发布':$reason,'operations'=>$ops];}
        foreach($contracts as $contract){if($contract['kind']!=='market')continue;$c=OpenStore::json($contract['config']);$product=(string)$contract['product_id'];$items[]=['kind'=>'market','code'=>$contract['code'],'name'=>$c['title']??$contract['code'],'available'=>$global['enabled']&&$config['enabled']&&in_array($product,$config['models'],true)&&in_array($product,$global['models'],true),'method'=>$contract['method'],'path'=>$contract['path'],'version'=>$contract['version'],'schema'=>$c['input']??[],'example'=>$c['example']??new \stdClass(),'responses'=>$c['responses']??[],'billable'=>$c['billable']??false,'description'=>$c['description']??''];}
        return $items;
    }
}
