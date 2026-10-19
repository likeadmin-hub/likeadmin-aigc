#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
# Test a current source snapshot; Docker Desktop's shared directory cache can be stale.
docker exec bt mkdir -p /tmp/point-alert-code
COPYFILE_DISABLE=1 tar --no-xattrs -cf - -C app/common/service/point_alert . | docker exec -i bt tar -xf - -C /tmp/point-alert-code
for file in tests/point-alert/policy.php tests/point-alert/database.php tests/point-alert/concurrency.php; do
 docker exec -i -w /www/wwwroot/likeadmin_aigc_saas/server bt /www/server/php/82/bin/php -r 'require "vendor/autoload.php"; spl_autoload_register(function($c){$prefix="app\\common\\service\\point_alert\\";if(str_starts_with($c,$prefix))require "/tmp/point-alert-code/".substr($c,strlen($prefix)).".php";},true,true); $s=stream_get_contents(STDIN); $s=preg_replace("~require dirname\\(__DIR__,2\\).[^;]+;~", "", $s); eval("?>".$s);' < "$file"
done
