<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

/** Transport formatting only. Never repair truncated JSON or extract an
 * executable object from surrounding prose or multiple candidate objects. */
final class ConversationResponseJson
{
    public static function decode(string $response,int $depth=32): mixed
    {
        $text=trim($response);
        if (str_starts_with($text,"\xEF\xBB\xBF")) $text=trim(substr($text,3));
        if (preg_match('/\A```(?:json)?\s*\R(.*)\R```\z/is',$text,$match)) $text=trim($match[1]);
        return json_decode($text,true,$depth,JSON_THROW_ON_ERROR);
    }

    public static function hasKeys(array $value,array $keys): bool
    {
        return count($value)===count($keys) && !array_diff($keys,array_keys($value));
    }
}
