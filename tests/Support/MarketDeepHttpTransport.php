<?php
/** Test-process-only DNS/transport boundary. Public-URL validation still runs; no external socket is opened. */
namespace app\common\service\open_platform;
function dns_get_record($host,$type) {
    if($host!=='private-media.example.test')throw new \RuntimeException('Unexpected fixture DNS destination');
    return $type===DNS_A?[['ip'=>'93.184.216.34']]:[];
}
function curl_init($url=null) {
    if($url!==null&&parse_url($url,PHP_URL_HOST)==='private-media.example.test') {
        $base=trim(file_get_contents(getenv('AIGC_HTTP_FIXTURE_DIR').'/supplier-url'));
        $url=$base.parse_url($url,PHP_URL_PATH);
    }
    return \curl_init($url);
}
function curl_setopt_array($curl,$options) {
    unset($options[CURLOPT_RESOLVE]);
    return \curl_setopt_array($curl,$options);
}
