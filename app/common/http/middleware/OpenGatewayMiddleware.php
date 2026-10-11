<?php
namespace app\common\http\middleware;
use app\common\service\open_platform\OpenGateway;
use app\common\service\open_platform\OpenException;
use Closure;
final class OpenGatewayMiddleware
{
    public function handle($request,Closure $next)
    {
        $path='/'.trim($request->pathinfo(),'/');if(!str_starts_with($path,'/api/v1/'))return $next($request);
        if($request->method()==='OPTIONS')return response('',204,['Access-Control-Allow-Origin'=>'*','Access-Control-Allow-Headers'=>'Authorization, Content-Type, X-API-Key, Idempotency-Key, Anthropic-Version, Anthropic-Beta, OpenAI-Beta, X-Open-Callback-URL, X-Open-Callback-Secret, X-Open-Debug','Access-Control-Allow-Methods'=>'GET, POST, PUT, DELETE, OPTIONS']);
        try{return OpenGateway::handle($request,$path)->header(['Access-Control-Allow-Origin'=>'*','Access-Control-Expose-Headers'=>'X-Request-Id']);}
        catch(\Throwable $e){return OpenGateway::error($e,$path)->header(['Access-Control-Allow-Origin'=>'*']);}
    }
}
