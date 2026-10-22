<?php
declare(strict_types=1);
namespace app\common\service\decorate;

use RuntimeException;

/** Rewrite media leaves, never links, while retaining JSON objects and layout numbers. */
final class DecorationPortableDocument
{
    private const JSON_FIELDS=['data','meta','draft_data','draft_meta','published_data','published_meta'];
    private const MEDIA='/^(?:image|images|image_uri|image_url|src|srcset|poster|cover|cover_image|cover_url|thumbnail|thumb|avatar|default_avatar|empty_image|qrcode|title_img|bg_video|logo|icon|selected|unselected|selected_icon|unselected_icon|icon_atlas|bg_image|bg_img|background_image|backgroundImage|background|bg|icon_image|background_url|video|video_url|audio|audio_url|url|uri|file|file_url|font_url)$/i';
    private const LINKS='/^(?:link|links|.*_link|.*_links|route|route_path|path|query|href|target|redirect|redirect_url|jump_url|action_url|source|source_params|resolved_sources|resolved_asset_urls|api_config|request_config)$/i';

    public static function rewrite(array $payload,callable $replace,bool $stripRefs=true): array
    {
        if(isset($payload['template'])){
            $encoded=[];
            foreach(['draft_settings','published_settings'] as $field)if(isset($payload['template'][$field])&&is_string($payload['template'][$field])&&$payload['template'][$field]!==''){
                try{$payload['template'][$field]=json_decode($payload['template'][$field],false,64,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new RuntimeException('模板设置 JSON 无效',0,$e);}
                $encoded[]=$field;
            }
            $payload['template']=self::walk($payload['template'],$replace,'/template','',false,0,$stripRefs);
            foreach($encoded as $field)$payload['template'][$field]=json_encode($payload['template'][$field],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        foreach($payload['pages']??[] as $index=>$page){
            foreach(self::JSON_FIELDS as $field){
                if(!isset($page[$field])||$page[$field]==='')continue;
                if(!is_string($page[$field]))throw new RuntimeException('模板页面 JSON 类型无效');
                try{$value=json_decode($page[$field],false,64,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new RuntimeException('模板页面 '.$field.' JSON 无效',0,$e);}
                if(!is_array($value)&&!is_object($value))throw new RuntimeException('模板页面 JSON 必须为组件结构');
                $value=self::walk($value,$replace,'/pages/'.$index.'/'.$field,'',false,0,$stripRefs);
                $page[$field]=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            }
            $payload['pages'][$index]=$page;
        }
        return $payload;
    }

    public static function value(mixed $value,callable $replace): mixed
    {return self::walk($value,$replace,'/value','',false,0,false);}

    private static function walk(mixed $value,callable $replace,string $path,string $key,bool $link,int $depth,bool $stripRefs): mixed
    {
        if($depth>48)throw new RuntimeException('模板素材嵌套过深');
        if(is_array($value)||is_object($value)){
            $object=is_object($value);$result=$object?new \stdClass():[];
            foreach($value as $name=>$child){
                if($name==='_decoration_asset_refs'){if(!$stripRefs){if($object)$result->$name=$child;else $result[$name]=$child;}continue;} // Source IDs are never portable authority.
                $name=(string)$name;$childLink=$link||(bool)preg_match(self::LINKS,$name);
                $next=self::walk($child,$replace,$path.'/'.str_replace(['~','/'],['~0','~1'],$name),ctype_digit($name)?$key:$name,$childLink,$depth+1,$stripRefs);
                if($object)$result->$name=$next;else $result[$name]=$next;
            }
            return $result;
        }
        if(!is_string($value)||$link||$value==='')return $value;
        if(preg_match('~<[a-z]~i',$value))return self::html($value,$replace,$path);
        $value=self::css($value,$replace,$path);
        if($key==='srcset')return self::srcset($value,$replace,$path);
        if(preg_match(self::MEDIA,$key)&&self::address($value))return $replace($value,$path);
        return $value;
    }

    private static function address(string $value): bool
    {
        return (bool)preg_match('~^(?:https?://|//|/|(?:resource|static|uploads)/|(?:blob|data|file):|[a-z0-9_./-]+\.(?:png|jpe?g|webp|gif|avif|svg|ico|mp4|webm|mov|mp3|m4a|wav|ogg|flac|woff2?|ttf|otf)(?:[?#].*)?$)~i',$value);
    }
    private static function css(string $value,callable $replace,string $path): string
    {
        return preg_replace_callback('~url\(\s*([\x22\x27]?)(.*?)\1\s*\)~is',static function($m)use($replace,$path){
            $url=trim($m[2]);if($url===''||str_starts_with($url,'#'))return $m[0];
            return str_replace($m[2],$replace($url,$path),$m[0]);
        },$value);
    }

    /** DOM tokenization handles quoted >, entity-encoded style values and unquoted attributes. */
    private static function html(string $html,callable $replace,string $path): string
    {
        if(!class_exists(\DOMDocument::class))throw new RuntimeException('HTML素材处理需要PHP DOM扩展');
        $document=new \DOMDocument('1.0','UTF-8');$document->resolveExternals=false;$document->substituteEntities=false;
        $previous=libxml_use_internal_errors(true);
        try{
            if(!$document->loadHTML('<?xml encoding="UTF-8"><html><body><div id="decoration-portable-root">'.$html.'</div></body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new RuntimeException('富文本素材结构无效');
            $root=$document->getElementById('decoration-portable-root');if(!$root)throw new RuntimeException('富文本素材结构无效');
            foreach($root->getElementsByTagName('*') as $element){
                if($element->hasAttribute('style'))$element->setAttribute('style',self::css($element->getAttribute('style'),$replace,$path));
                if(strtolower($element->tagName)==='style')$element->nodeValue=self::css($element->textContent,$replace,$path);
                if(!in_array(strtolower($element->tagName),['img','video','audio','source','track','input'],true))continue;
                foreach(['src','poster','srcset'] as $attribute){
                    if(!$element->hasAttribute($attribute))continue;$value=$element->getAttribute($attribute);if($value==='')continue;
                    $element->setAttribute($attribute,$attribute==='srcset'?self::srcset($value,$replace,$path):$replace($value,$path));
                }
            }
            $result='';foreach($root->childNodes as $node)$result.=$document->saveHTML($node);return $result;
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
    private static function srcset(string $value,callable $replace,string $path): string
    {
        if(preg_match('~(?:^|,)\s*data:~i',$value))throw new RuntimeException('srcset内嵌图片请先上传为文件');
        $candidates=[];
        foreach(explode(',',$value) as $candidate){
            $candidate=trim($candidate);if($candidate==='')continue;
            if(!preg_match('/^(\S+)(\s+.+)?$/D',$candidate,$match))throw new RuntimeException('srcset素材地址无效');
            $candidates[]=$replace($match[1],$path).($match[2]??'');
        }
        return implode(', ',$candidates);
    }
}
