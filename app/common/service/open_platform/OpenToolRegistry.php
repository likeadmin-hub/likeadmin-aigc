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
    public static function capabilities(string $app,array $data): array
    {
        $tool=self::get($app);
        if($app==='aigc_music') {
            $data['limitations']=$tool['limitations'];
            $data['config_json']['allow_voice_clone']=false;
            unset($data['export_types']);
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
