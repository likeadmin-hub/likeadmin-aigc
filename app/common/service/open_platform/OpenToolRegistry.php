<?php
namespace app\common\service\open_platform;
final class OpenToolRegistry
{
    public static function all(): array { static $items; return $items ??= json_decode(file_get_contents(__DIR__.'/tools.json'),true,512,JSON_THROW_ON_ERROR); }
    public static function get(string $code): array { return self::all()[$code] ?? throw new OpenException('not_found','工具不存在或未开放',404); }
    public static function executable(array $operation): bool { return ($operation['available']??true)===true; }
    public static function supported(string $method,string $path): bool
    {
        try { self::match($method,$path);return true; } catch(OpenException $e) { if($e->httpStatus!==404)throw $e;return false; }
    }
    public static function assertExecutable(array $operation): void
    {
        foreach(self::get($operation['app_code'])['operations'] as $route=>$registered) {
            $same=isset($operation['route'])?$route===$operation['route']:isset($operation['controller'],$operation['action'])&&($registered['controller']??'')===$operation['controller']&&($registered['action']??'')===$operation['action'];
            if($same&&!self::executable($registered))throw new OpenException('not_found','该操作尚未实现，不能开放调用',404);
        }
    }
    public static function ready(string $app,array $operation): bool
    {
        return self::executable($operation)&&!($app==='aigc_music'&&($operation['music_operation']??'')==='mp4'&&\app\common\service\app\aigc_music\MusicExportDecoder::binary()==='');
    }
    public static function capabilities(string $app,array $data,?array $publishedRoutes=null): array
    {
        $tool=self::get($app);
        if($app==='aigc_music') {
            $data['limitations']=$tool['limitations'];
            // Creating a voice resource does not mean the song generator consumes it.
            $data['config_json']['allow_voice_clone']=false;
            unset($data['export_types']);
            unset($data['option_config']['export_types']);
            if($publishedRoutes===null){
                $publishedRoutes=[];
                foreach(OpenStore::table('contract')->where(['kind'=>'tool','status'=>'published'])->field('method,path')->select()->toArray() as $contract){
                    $prefix='/api/v1/tools/aigc_music/';
                    if(str_starts_with($contract['path'],$prefix))$publishedRoutes[]=$contract['method'].' '.substr($contract['path'],strlen($prefix));
                }
            }
            $publishedRoutes=array_filter($publishedRoutes,static fn($route)=>isset($tool['operations'][$route])&&self::ready($app,$tool['operations'][$route]));
            $types=[];
            foreach(\app\common\service\app\aigc_music\AigcMusicService::exportTypes() as $type)if(in_array('POST export/'.$type['value'],$publishedRoutes,true))$types[]=$type;
            if($types)$data['export_types']=$types;
            $data['limitations']['converted_exports']=(bool)array_intersect(array_column($types,'value'),['wav','midi','mp4']);
            $data['limitations']['voice_clone_creation']=in_array('POST voiceclone/create',$publishedRoutes,true);
            $data['limitations']['upstream_persona_creation']=in_array('POST persona/upstream',$publishedRoutes,true);
            $data['limitations']['upstream_audio_registration']=in_array('POST asset/upload_upstream',$publishedRoutes,true);
        }
        return $data;
    }
    public static function match(string $method, string $path): array
    {
        if (!preg_match('#^/api/v1/tools/([a-z_]+)/(.+)$#D',$path,$m)) throw new OpenException('not_found','工具接口不存在',404);
        $tool=self::get($m[1]); foreach ($tool['operations'] as $route=>$operation) { if(!self::executable($operation))continue;[$verb,$template]=explode(' ',$route,2); $params=OpenPolicy::matches($template,$m[2]); if ($verb===$method && $params!==null) return $operation+['app_code'=>$m[1],'route'=>$route,'params'=>$params]; }
        throw new OpenException('not_found','该操作未开放',404);
    }
}
