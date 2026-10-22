#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
snapshot=$(docker exec bt mktemp -d /tmp/virtualpay-key.XXXXXX)
trap 'docker exec bt rm -rf "$snapshot"' EXIT
COPYFILE_DISABLE=1 tar --no-xattrs -cf - app/common/service/virtualpay/VirtualPayKeySetup.php app/common/service/virtualpay/VirtualPayVault.php app/common/service/virtualpay/VirtualPayConfigService.php app/common/command/VirtualPayKey.php config/console.php public/install/YxEnv.php tests/virtualpay-vault-key.php tests/virtualpay-key-setup.php tests/virtualpay-vault-setup.integration.php | docker exec -i bt tar -xf - -C "$snapshot"
docker exec bt /www/server/php/82/bin/php "$snapshot/tests/virtualpay-vault-key.php"
docker exec bt /www/server/php/82/bin/php "$snapshot/tests/virtualpay-key-setup.php"
docker exec -e AIGC_SERVER_ROOT=/www/wwwroot/likeadmin_aigc_saas/server -w /www/wwwroot/likeadmin_aigc_saas/server bt /www/server/php/82/bin/php -r 'require "vendor/autoload.php";$snapshot=$argv[1];spl_autoload_register(function($class)use($snapshot){$file=$snapshot."/".str_replace("\\","/",$class).".php";if(str_starts_with($class,"app\\")&&is_file($file))require $file;},true,true);require $snapshot."/tests/virtualpay-vault-setup.integration.php";' "$snapshot"
