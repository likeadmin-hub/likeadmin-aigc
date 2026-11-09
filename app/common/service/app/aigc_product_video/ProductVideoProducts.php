<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;
use app\common\service\app\aigc_micro_video\MicroVideoMedia;
use app\common\service\app\aigc_image\AigcImageAssetService;
use app\common\service\open_platform\OpenSafeHttp;
use InvalidArgumentException;
final class ProductVideoProducts
{
    public static function lists(int $tenant,int $user,array $p): array
    {
        $q=ProductVideoService::table('product')->where(['tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0]);$page=max(1,(int)($p['page_no']??1));$size=max(1,min(30,(int)($p['page_size']??20)));
        $count=(clone $q)->count();$rows=$q->order('id','desc')->page($page,$size)->select()->toArray();
        return ['lists'=>array_map(static fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'body'=>ProductVideoService::publicBody(ProductVideoPolicy::json($r['body_json']),$tenant)],$rows),'count'=>$count];
    }
    public static function save(int $tenant,int $user,array $p): array
    {
        ProductVideoService::available($tenant,$user);$name=ProductVideoPolicy::text($p['name']??'',100);if($name==='')throw new InvalidArgumentException('请填写商品名称');
        $body=['images'=>MicroVideoMedia::validateImages($tenant,$user,(array)($p['images']??[]),15),'product_info'=>ProductVideoPolicy::text($p['product_info']??'',3000)];if(!$body['images'])throw new InvalidArgumentException('请添加商品图片');
        $data=['name'=>$name,'body_json'=>ProductVideoPolicy::encode($body),'update_time'=>time()];$id=(int)($p['id']??0);
        if($id){$q=ProductVideoService::table('product')->where(['id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0]);if(!$q->find())throw new InvalidArgumentException('商品资料不存在');$q->update($data);}
        else $id=ProductVideoService::table('product')->insertGetId($data+['tenant_id'=>$tenant,'user_id'=>$user,'create_time'=>time()]);
        return ['id'=>$id,'name'=>$name,'body'=>ProductVideoService::publicBody($body,$tenant)];
    }
    public static function delete(int $tenant,int $user,int $id): void
    {ProductVideoService::table('product')->where(['id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->update(['delete_time'=>time(),'update_time'=>time()]);}
    /** Parse supported product pages only. Video-sharing pages are deliberately not a media source. */
    public static function import(int $tenant,int $user,string $url): array
    {
        ProductVideoService::available($tenant,$user);$url=ProductVideoPolicy::text($url,2000);$host=strtolower((string)parse_url($url,PHP_URL_HOST));$path=(string)parse_url($url,PHP_URL_PATH);
        $amazon=preg_match('/(?:^|\.)amazon\.(?:com|co\.uk|de|fr|it|es|co\.jp|ca|com\.au|in|com\.br|com\.mx|sg|ae|sa)$/D',$host);
        $shop=preg_match('/(?:^|\.)tiktok(?:globalshop)?\.com$/D',$host);
        if((!$amazon&&!$shop)||!preg_match('~/(?:dp|gp/product|product|view/product|shop/product)/~',$path))throw new InvalidArgumentException('请填写受支持的亚马逊或 TikTok Shop 商品页地址；视频分享链接暂不支持');
        try{$page=OpenSafeHttp::get($url,2097152,15);}catch(\Throwable $e){throw new InvalidArgumentException('商品页面暂时无法读取，请上传图片并填写商品信息',0,$e);}
        $data=self::metadata($page['body'],$page['url']);if(!$data['images'])throw new InvalidArgumentException('未读取到商品图片，页面可能需要登录；请手动上传');
        $images=[];
        foreach(array_slice($data['images'],0,6) as $source){
            try{$file=OpenSafeHttp::get($source,10485760,10);$info=@getimagesizefromstring($file['body']);if(!$info||!in_array($info['mime']??'',['image/png','image/jpeg','image/webp'],true))continue;
                $stored=AigcImageAssetService::persistGeneratedImage('data:'.$info['mime'].';base64,'.base64_encode($file['body']),$tenant,$user);$images[]=['uri'=>$stored['uri']];
            }catch(\Throwable){}
        }
        if(!$images)throw new InvalidArgumentException('商品图片下载失败，请改用本地上传');
        return ['name'=>$data['name'],'product_info'=>$data['product_info'],'images'=>ProductVideoService::publicBody(MicroVideoMedia::validateImages($tenant,$user,$images,6),$tenant),'notice'=>'请核对导入的商品信息，仅保留已确认的事实'];
    }
    public static function metadata(string $html,string $base): array
    {
        if(strlen($html)>2097152)throw new InvalidArgumentException('商品页面过大');$doc=new \DOMDocument();$prior=libxml_use_internal_errors(true);
        try{$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
        $product=null;$walk=static function($node,int $depth=0)use(&$walk,&$product){if($depth>8||!is_array($node)||$product)return;$types=(array)($node['@type']??[]);if(in_array('Product',$types,true)){$product=$node;return;}foreach(array_slice($node,0,200) as $value)if(is_array($value))$walk($value,$depth+1);};
        foreach($doc->getElementsByTagName('script') as $script)if(strtolower($script->getAttribute('type'))==='application/ld+json'){$decoded=json_decode($script->textContent,true,32);$walk($decoded);}
        $meta=[];foreach($doc->getElementsByTagName('meta') as $tag){$key=$tag->getAttribute('property')?:$tag->getAttribute('name');if($key!=='')$meta[strtolower($key)][]=$tag->getAttribute('content');}
        $name=trim(strip_tags((string)($product['name']??$meta['og:title'][0]??$doc->getElementsByTagName('title')->item(0)?->textContent??'')));
        $description=trim(strip_tags((string)($product['description']??$meta['description'][0]??$meta['og:description'][0]??'')));
        $sources=$product['image']??$meta['og:image']??[];if(is_string($sources))$sources=[$sources];elseif(isset($sources['url']))$sources=[$sources['url']];$images=[];
        foreach((array)$sources as $source){if(is_array($source))$source=$source['url']??$source['contentUrl']??'';if(!is_string($source)||$source==='')continue;$source=OpenSafeHttp::redirectUrl($base,html_entity_decode($source,ENT_QUOTES));if(preg_match('#^https?://#i',$source))$images[]=$source;}
        return ['name'=>mb_substr($name,0,100),'product_info'=>mb_substr(trim($name."\n".$description),0,3000),'images'=>array_values(array_unique($images))];
    }
}
