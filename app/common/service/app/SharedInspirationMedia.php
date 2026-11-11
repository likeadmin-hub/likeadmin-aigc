<?php
declare(strict_types=1);
namespace app\common\service\app;
/** Exact approved storage coordinates only, including retained references of deleted definitions. */
final class SharedInspirationMedia
{
    public static function url(string $uri,string $engine,string $domain): ?string
    {return \app\common\service\app\aigc_product_video\ProductVideoBuiltinCatalog::mediaUrl($uri,$engine,$domain)??StudioBuiltinCatalog::mediaUrl($uri,$engine,$domain);}
}
