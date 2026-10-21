#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
snapshot=$(docker exec bt mktemp -d /tmp/decorate-portable.XXXXXX)
trap 'docker exec bt rm -rf "$snapshot"' EXIT
# Fresh snapshots avoid Docker Desktop shared-file stale/truncated reads.
COPYFILE_DISABLE=1 tar --no-xattrs -cf - app/common/service/decorate/DecorationPortableDocument.php app/common/service/decorate/DecorationPortableMedia.php app/common/service/decorate/DecorationPortableAssets.php app/common/service/decorate/DecorationPortablePackage.php app/common/service/decorate/DecorateTemplateService.php app/common/service/decorate/agent/DecorationAgentAssetReferences.php app/tenantapi/logic/decorate/DecoratePageLogic.php app/common/service/storage/StorageOperationTimeout.php app/common/service/storage/engine/Aliyun.php app/common/service/storage/engine/Qcloud.php app/common/service/storage/engine/Qiniu.php app/common/service/storage/SvgSanitizer.php app/common/service/open_platform/OpenPolicy.php app/common/service/open_platform/OpenException.php app/common/service/open_platform/OpenSafeHttp.php app/common/service/decorate/agent/catalog/mobile-catalog.json tests/decoration | docker exec -i bt tar -xf - -C "$snapshot"
for test in portable-assets.php storage-timeout.php portable-private-export.php portable-package.php; do
 docker exec -w /www/wwwroot/likeadmin_aigc_saas/server bt /www/server/php/82/bin/php -r 'require "vendor/autoload.php"; $dir=$argv[1]; spl_autoload_register(function($c)use($dir){$f=$dir."/".str_replace("\\","/",$c).".php";if(str_starts_with($c,"app\\")&&is_file($f))require $f;},true,true);require $dir."/tests/decoration/".$argv[2];' "$snapshot" "$test"
done
if [ "${PORTABLE_INTEGRATION:-0}" = 1 ]; then
 docker exec -e AIGC_SERVER_ROOT=/www/wwwroot/likeadmin_aigc_saas/server -w /www/wwwroot/likeadmin_aigc_saas/server bt /www/server/php/82/bin/php -r 'require "vendor/autoload.php";$dir=$argv[1];spl_autoload_register(function($c)use($dir){$f=$dir."/".str_replace("\\","/",$c).".php";if(str_starts_with($c,"app\\")&&is_file($f))require $f;},true,true);require $dir."/tests/decoration/portable-template.integration.php";' "$snapshot"
fi
