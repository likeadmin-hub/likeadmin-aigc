<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

/** One synchronous planning phase only. Never cache authorization or mutable task state. */
final class AgentReadCache
{
    private static ?array $entries=null;
    private static int $bytes=0;
    private const MAX_BYTES=4194304;
    private const MAX_ENTRIES=32;

    public static function scope(callable $work): mixed
    {
        $previous=self::$entries;$bytes=self::$bytes;
        self::$entries=[];self::$bytes=0;
        try{return $work();}
        finally{self::$entries=$previous;self::$bytes=$bytes;}
    }

    public static function remember(string $key,callable $read): mixed
    {
        if(self::$entries===null)return $read();
        if(array_key_exists($key,self::$entries))return self::$entries[$key]['value'];
        $value=$read(); // Failed reads are never cached.
        $size=strlen(serialize($value));
        if($size>self::MAX_BYTES)return $value;
        while(self::$entries&&(count(self::$entries)>=self::MAX_ENTRIES||self::$bytes+$size>self::MAX_BYTES)){
            $first=array_key_first(self::$entries);self::$bytes-=self::$entries[$first]['size'];unset(self::$entries[$first]);
        }
        self::$entries[$key]=['value'=>$value,'size'=>$size];self::$bytes+=$size;
        return $value;
    }
}
