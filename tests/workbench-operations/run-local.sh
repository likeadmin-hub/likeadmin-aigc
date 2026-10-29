#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
testdir=$(docker exec bt mktemp -d /tmp/operations-test.XXXXXX)
trap 'docker exec bt rm -rf "$testdir"' EXIT
# Snapshot current source: Docker Desktop's shared-filesystem cache can expose partial old files.
COPYFILE_DISABLE=1 tar --no-xattrs -cf - -C app/common/service/workbench . | docker exec -i bt tar -xf - -C "$testdir"
for file in tests/workbench-operations/range.php tests/workbench-operations/database.php tests/workbench-operations/aggregate.php; do
 docker exec -i -w /www/wwwroot/likeadmin_aigc_saas/server bt /www/server/php/82/bin/php -r 'require "runtime-loader.php"; $dir=$argv[1]; spl_autoload_register(function($c)use($dir){$prefix="app\\common\\service\\workbench\\";if(str_starts_with($c,$prefix))require $dir."/".substr($c,strlen($prefix)).".php";},true,true); $s=stream_get_contents(STDIN); $s=preg_replace("~require dirname\\(__DIR__,2\\).[^;]+;~", "", $s); eval("?>".$s);' "$testdir" < "$file"
done
docker exec -w /www/wwwroot/likeadmin_aigc_saas/server bt /www/server/php/82/bin/php tests/workbench-operations/indexes.php
