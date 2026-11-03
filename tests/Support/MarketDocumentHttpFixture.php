<?php
/** Loaded only inside isolated documentation tests. Every socket/DB configuration path is replaced. */
namespace Tests\Support {
    final class MarketDocumentSourceFixture
    {
        public static function getSource(): array
        {
            return ['active_base_url'=>'https://market-docs.invalid','active_api_key'=>'synthetic-document-key'];
        }
        public static function sslVerify(array $source): bool { return true; }
    }

    final class MarketDocumentHttpFixture
    {
        public static array $responses=[];
        public static array $requests=[];
        public static array $violations=[];

        public static function execute($handle): string
        {
            $options=$handle->options;$parts=parse_url($options[CURLOPT_URL]??'');
            parse_str($parts['query']??'',$query);
            $path=$parts['path']??'';
            if (($parts['scheme']??'')!=='https' || ($parts['host']??'')!=='market-docs.invalid'
                || !empty($options[CURLOPT_POST]) || !empty($options[CURLOPT_POSTFIELDS])
                || !in_array($path,['/api/user_center/developerDocDetail','/api/user_center/developerDocTree'],true)
                || ($query['terminal']??null)!=='4') self::reject('Unexpected documentation transport');
            $selector='tree';
            if ($path==='/api/user_center/developerDocDetail') {
                $fields=array_intersect(['model_id','app_api_id','slug'],array_keys($query));
                if(count($fields)!==1 || count($query)!==2) self::reject('Unexpected document selector');
                $field=array_values($fields)[0];$selector=$field.':'.$query[$field];
            } elseif(count($query)!==1) self::reject('Unexpected document-tree parameters');
            self::$requests[]=$selector;
            if(!array_key_exists($selector,self::$responses)) self::reject('Unregistered synthetic document request');
            $response=self::$responses[$selector];
            if($response instanceof \Throwable) throw $response;
            return json_encode(['code'=>1,'data'=>$response],JSON_THROW_ON_ERROR);
        }

        private static function reject(string $message): void
        {
            self::$violations[]=$message;
            throw new \RuntimeException($message);
        }
    }
}

namespace app\common\service\app {
    function curl_init() { return new \stdClass(); }
    function curl_setopt_array($handle,array $options) { $handle->options=$options;return true; }
    function curl_setopt($handle,$option,$value) { $handle->options[$option]=$value;return true; }
    function curl_exec($handle) { return \Tests\Support\MarketDocumentHttpFixture::execute($handle); }
    function curl_errno($handle) { return 0; }
    function curl_error($handle) { return ''; }
    function curl_getinfo($handle,$option) { return 200; }
    function curl_close($handle) {}
}
