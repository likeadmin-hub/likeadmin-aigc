#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
snapshot=$(docker exec bt mktemp -d /tmp/market-markup.XXXXXX)
trap 'docker exec bt rm -rf "$snapshot"' EXIT
COPYFILE_DISABLE=1 tar --no-xattrs -cf - app/common/service/power app/common/service/app/AppSalePriceService.php app/common/model/power app/common/model/ai/AiConsumptionLog.php tests/market-markup upgrade/20261022_market_price_rules.sql | docker exec -i bt tar -xf - -C "$snapshot"
for test in policy database; do
 [ -f "tests/market-markup/$test.php" ] || continue
 docker exec -w /www/wwwroot/likeadmin_aigc_saas/server bt /www/server/php/82/bin/php -r '
 $root=$argv[1];require "vendor/autoload.php";
 spl_autoload_register(function($class)use($root){$p=$root."/".str_replace("\\","/",$class).".php";if(is_file($p))require $p;},true,true);
 require $root."/tests/market-markup/".$argv[2].".php";' "$snapshot" "$test"
done
