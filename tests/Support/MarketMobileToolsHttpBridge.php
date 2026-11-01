<?php
/** Owner-marked test bridge; invokes existing mobile SFC DTOs and request wrappers. */
function marketMobileToolsCommand(array $command): array
{
    global $marketMobileTools;
    $f=$marketMobileTools['fixture'];$sequence=++$marketMobileTools['sequence'];
    $target=$f->dir.'/mobile-command.json';file_put_contents($target.'.tmp',json_encode(['sequence'=>$sequence]+$command,JSON_THROW_ON_ERROR));rename($target.'.tmp',$target);
    $result=null;MarketWorkerFixture::wait(function()use($f,$sequence,&$result){
        if(is_file($f->dir.'/mobile-failed.json'))throw new RuntimeException(file_get_contents($f->dir.'/mobile-failed.json'));
        $result=is_file($f->dir.'/mobile-response.json')?json_decode(file_get_contents($f->dir.'/mobile-response.json'),true):null;
        return ($result['sequence']??0)===$sequence;
    },'Mobile tool command did not finish: '.$command['action'],120);
    if(isset($result['error']))throw new RuntimeException('Mobile tool command: '.$result['error']);
    return $result['data'];
}
function marketMobileToolsStart($f,string $api,string $suffix,string $token): void
{
    global $marketMobileTools;$marketMobileTools=['fixture'=>$f,'sequence'=>0];
    $tokens=[];foreach(['h5'=>3,'mp'=>1]as$name=>$terminal)$tokens[$name]=dataHttp($api,'/api/login/account'.$suffix,['account'=>$f->prefix,'password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>$terminal])['token'];
    file_put_contents($f->dir.'/mobile-input.json',json_encode(['api'=>$api,'api_peer'=>$api,'tenant'=>$f->tenant,'tokens'=>$tokens],JSON_THROW_ON_ERROR));
    fwrite(STDERR,'MOBILE_TOOLS_READY '.$f->dir.chr(10));
    marketMobileToolsCommand(['action'=>'start']);
}
function marketMobileToolHttp(string $path,array $data,string $method): ?array
{
    global $marketMobileTools;
    if(empty($marketMobileTools['active'])||!preg_match('~^/api/app\.(aigc_[a-z_]+)\.(config/detail|generate/estimate|generate/index|task/detail|task/lists)~',$path,$m))return null;
    parse_str(parse_url($path,PHP_URL_QUERY)??'',$query);unset($query['tenant_id']);
    return marketMobileToolsCommand(['action'=>'http','app'=>$m[1],'operation'=>$m[2],'data'=>$method==='GET'?$query:$data,'method'=>$method]);
}
