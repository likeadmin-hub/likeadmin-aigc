#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
snapshot=$(docker exec bt mktemp -d /tmp/membership-access.XXXXXX)
trap 'docker exec bt rm -rf "$snapshot"' EXIT
COPYFILE_DISABLE=1 tar --no-xattrs -cf - app/common/service/app app/common/service/membership app/common/model/membership app/api/http/middleware/AppAccessMiddleware.php tests/membership-access | docker exec -i bt tar -xf - -C "$snapshot"
docker exec -w /www/wwwroot/likeadmin_aigc_saas/server bt /www/server/php/82/bin/php -r '$root=$argv[1];require "vendor/autoload.php";spl_autoload_register(function($class)use($root){$p=$root."/".str_replace("\\","/",$class).".php";if(is_file($p))require $p;},true,true);require $root."/tests/membership-access/run.php";' "$snapshot" | tee /tmp/membership-access-result.log
rg -q '^PASS ' /tmp/membership-access-result.log
