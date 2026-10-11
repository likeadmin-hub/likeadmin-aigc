<?php
namespace app\common\service\open_platform;
use app\common\model\file\TenantFile;
use app\common\service\FileService;
use app\common\enum\FileEnum;
/** Local user uploads need ownership; external public URLs remain normal tool inputs. */
final class OpenMaterials
{
    public static function resolve(int $tenantId,int $userId,array $params,string $context=''): array
    {
        foreach($params as $key=>&$value){$field=is_numeric($key)?$context:(string)$key;
            if(is_array($value)){$value=self::resolve($tenantId,$userId,$value,$field);continue;}
            if(!is_string($value)||$value===''||!preg_match('/(?:url|uri|images?|videos?|audio|file|clothes)$/i',$field))continue;
            if(preg_match('/^of_[a-f0-9]{32}$/D',$value)){$value=OpenAssets::detail($tenantId,$userId,$value)['url'];continue;}
            if(str_starts_with($value,'data:')){if(!preg_match('#^data:(?:image/(?:png|jpeg|webp|gif)|audio/[a-z0-9.+-]+|video/[a-z0-9.+-]+);base64,#i',$value))throw new OpenException('invalid_material','素材 Data URI 格式无效');continue;}
            if(preg_match('#^https?://#i',$value)){
                $uri=FileService::setFileUrl($value);
                // Native storage readers recognize an existing uploads path even
                // on another hostname. Enforce ownership before that alias can
                // become a local file in a shared tool service.
                $local=ltrim((string)(parse_url($value,PHP_URL_PATH)?:''),'/');
                if((str_starts_with($local,'uploads/')||str_starts_with($local,'resource/'))&&is_file(public_path().$local))$uri=$local;
                // The storage service only strips configured local domains, preserving external URLs.
                if($uri===$value||preg_match('#^https?://#i',$uri)){OpenSafeHttp::resolve($value,false);continue;}
            }else $uri=ltrim($value,'/');
            if(str_contains($uri,'..')||str_contains($uri,'\\')||str_contains($uri,':')||preg_match('/[\x00-\x1f]/',$uri))throw new OpenException('invalid_material','本地素材路径无效');
            if(str_starts_with($uri,'resource/')&&preg_match('/\.(?:png|jpe?g|webp|gif|mp4|webm|mov|mp3|wav|m4a)$/i',$uri))continue;
            $file=TenantFile::where(['tenant_id'=>$tenantId,'source_id'=>$userId,'source'=>FileEnum::SOURCE_USER,'uri'=>$uri])->findOrEmpty();if($file->isEmpty())throw new OpenException('not_found','本地素材不存在或不属于当前用户',404);
        }
        return $params;
    }
}
