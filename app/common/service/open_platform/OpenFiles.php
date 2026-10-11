<?php
namespace app\common\service\open_platform;
use think\file\UploadedFile;
final class OpenFiles
{
    public static function flatten(array $files,string $prefix=''): array
    {
        $out=[];
        foreach($files as $key=>$file){
            if(!preg_match('/^[a-zA-Z0-9_-]+$/D',(string)$key))throw new OpenException('invalid_file','上传字段名称无效');
            $name=$prefix===''?(string)$key:$prefix.'['.$key.']';
            if(is_array($file))$out+=self::flatten($file,$name);
            elseif($file instanceof UploadedFile&&$file->isValid())$out[$name]=$file;
            else throw new OpenException('invalid_file','上传文件无效');
        }
        if(count($out)>100)throw new OpenException('invalid_file','上传文件数量过多');
        return $out;
    }
    public static function fingerprint(array $params,array $files=[]): string
    {
        $identities=[];
        foreach(self::flatten($files) as $name=>$file){$hash=hash_file('sha256',$file->getPathname());if($hash===false)throw new OpenException('invalid_file','上传文件不可读');$identities[$name]=['sha256'=>$hash,'name'=>$file->getOriginalName(),'size'=>$file->getSize(),'mime'=>$file->getMime()];}
        return OpenPolicy::fingerprint(['parameters'=>$params,'files'=>$identities]);
    }
    public static function forSchema(array $params,array $files,array $schema): array
    {
        self::flatten($files);
        foreach($schema['properties']??[] as $name=>$field){
            $binary=($field['format']??'')==='binary';$multiple=($field['type']??'')==='array'&&($field['items']['format']??'')==='binary';
            if(!$binary&&!$multiple)continue;
            if(isset($files[$name])){$uploaded=$files[$name];if($multiple){$params[$name]=array_map(fn($f)=>$f->getOriginalName(),is_array($uploaded)?$uploaded:[$uploaded]);}else{if(is_array($uploaded))throw new OpenException('invalid_file',$name.' 只接受一个文件');$params[$name]=$uploaded->getOriginalName();}}
            elseif(array_key_exists($name,$params)||in_array($name,$schema['required']??[],true))throw new OpenException('invalid_file','请上传 '.$name.' 文件');
        }
        return $params;
    }
}
