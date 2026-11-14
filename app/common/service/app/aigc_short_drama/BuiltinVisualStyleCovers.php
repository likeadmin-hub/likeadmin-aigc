<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama;

final class BuiltinVisualStyleCovers
{
    public static function uri(string $image,string $name): string
    {
        static $covers=null;
        $covers??=json_decode(file_get_contents(dirname(__DIR__,4).'/apps/aigc_short_drama/data/style-covers.json'),true,64,JSON_THROW_ON_ERROR);
        if(isset($covers[$name])&&($image===''||str_contains($image,'aigc_short_drama/style_library/')))return $covers[$name];
        return $image;
    }
}
