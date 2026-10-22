#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
snapshot=$(docker exec bt mktemp -d /tmp/market-markup.XXXXXX)
trap 'docker exec bt rm -rf "$snapshot"' EXIT
COPYFILE_DISABLE=1 tar --no-xattrs -cf - app/common/service/power app/common/service/app app/common/service/ai app/common/service/open_platform/OpenMarketBilling.php app/common/model/power app/common/model/ai/AiConsumptionLog.php app/common/model/ai/AiAppTask.php app/common/service/point/PointService.php app/platformapi/controller/power app/tenantapi/controller/power app/platformapi/http/middleware/AuthMiddleware.php app/tenantapi/http/middleware/AuthMiddleware.php tests/market-markup upgrade/20261022_market_price_rules.sql | docker exec -i bt tar -xf - -C "$snapshot"
git diff --name-only HEAD -- '*.php' | docker exec -i bt /www/server/php/82/bin/php -r '$root=$argv[1];$n=0;foreach(explode("\n",trim(stream_get_contents(STDIN))) as $p){if($p===""||!is_file($root."/".$p))continue;exec(escapeshellarg(PHP_BINARY)." -l ".escapeshellarg($root."/".$p),$output,$code);if($code)throw new RuntimeException(implode("\n",$output));$n++;}echo "PASS syntax: $n changed PHP files\n";' "$snapshot"
for test in policy database settlement; do
 [ -f "tests/market-markup/$test.php" ] || continue
 docker exec -w /www/wwwroot/likeadmin_aigc_saas/server bt /www/server/php/82/bin/php -r '
 $root=$argv[1];require "vendor/autoload.php";
 spl_autoload_register(function($class)use($root){$p=$root."/".str_replace("\\","/",$class).".php";if(is_file($p))require $p;},true,true);
 require $root."/tests/market-markup/".$argv[2].".php";' "$snapshot" "$test"
done
