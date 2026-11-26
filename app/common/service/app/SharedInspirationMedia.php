<?php
declare(strict_types=1);
namespace app\common\service\app;
use app\common\service\app\aigc_product_video\{ProductVideoBuiltinCatalog, ProductVideoPolicy};

/** Exact approved storage coordinates only, including retained references of deleted definitions. */
final class SharedInspirationMedia
{
    public static function url(string $uri,string $engine,string $domain): ?string
    {
        $lookup = static fn() => ProductVideoBuiltinCatalog::mediaUrl($uri, $engine, $domain);
        return ProductVideoPolicy::forProduct($lookup) ?? ProductVideoPolicy::forSale($lookup) ?? StudioBuiltinCatalog::mediaUrl($uri,$engine,$domain);
    }
}
