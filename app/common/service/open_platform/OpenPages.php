<?php
namespace app\common\service\open_platform;
final class OpenPages
{
    public static function defaults(): array
    {
        $items=['overview'=>['开发概览','/user/developer/overview'],'keys'=>['API密钥','/user/api-keys'],'catalog'=>['API目录','/user/developer/catalog'],'playground'=>['在线调试','/user/developer/playground'],'requests'=>['调用记录','/user/developer/requests'],'tasks'=>['异步任务','/user/developer/tasks'],'usage'=>['用量账单','/user/developer/usage'],'home'=>['API平台首页','/official/open'],'docs'=>['API文档','/docs']];
        $out=[];foreach($items as $id=>$item)$out[]=['id'=>$id,'name'=>$item[0],'path'=>$item[1],'icon'=>$id,'sort'=>count($out)*10,'visible'=>true,'description'=>'','help_url'=>''];return $out;
    }
    public static function configured(int $tenantId): array
    {
        $overrides=array_replace(OpenStore::settings(0)['pages'],OpenStore::settings($tenantId)['pages']);$rows=[];foreach(self::defaults() as $default){$row=array_replace($default,$overrides[$default['id']]??[]);if(($row['icon']??'api')==='api')$row['icon']=$default['icon'];$legacy=['overview'=>'开发者概览','keys'=>'API 密钥','catalog'=>'API 能力目录','usage'=>'用量与账单','home'=>'API 平台首页','docs'=>'API 文档'];if(($row['name']??'')===($legacy[$default['id']]??null))$row['name']=$default['name'];$row['id']=$default['id'];$row['path']=$default['path'];$rows[]=$row;}usort($rows,fn($a,$b)=>[$a['sort'],$a['id']]<=>[$b['sort'],$b['id']]);return $rows;
    }
    public static function validate(array $pages): array
    {
        $allowed=array_column(self::defaults(),'id');$out=[];foreach($pages as $page){$id=(string)($page['id']??'');if(!in_array($id,$allowed,true))throw new OpenException('invalid_page','页面未注册');$name=trim((string)($page['name']??''));if($name===''||mb_strlen($name)>60)throw new OpenException('invalid_page','页面名称无效');$help=(string)($page['help_url']??'');if($help!==''&&!preg_match('#^https://[^\s]+$#D',$help))throw new OpenException('invalid_page','帮助链接必须使用 HTTPS');$out[$id]=['name'=>$name,'icon'=>in_array($page['icon']??'api',['api','overview','keys','catalog','playground','requests','tasks','usage','home','docs'],true)?($page['icon']??'api'):'api','sort'=>(int)($page['sort']??0),'visible'=>(bool)($page['visible']??true),'description'=>mb_substr((string)($page['description']??''),0,500),'help_url'=>$help];}return $out;
    }
}
