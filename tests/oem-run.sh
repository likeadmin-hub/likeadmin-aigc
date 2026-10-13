#!/usr/bin/env bash
set -euo pipefail
oem_root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
oem_database=${OEM_TEST_DATABASE:-oem_dev}
[[ "$oem_database" == oem_* ]] || { echo 'Disposable OEM database required' >&2; exit 1; }
oem_args=(--rm --network likeadmin-oem-dev -v "$oem_root:/repo:ro" --tmpfs /repo/runtime --tmpfs /repo/public/uploads -w /repo
  -e OEM_DISPOSABLE=1 -e PHP_DATABASE_HOSTNAME=oem-mysql -e PHP_DATABASE_DATABASE="$oem_database"
  -e PHP_DATABASE_USERNAME=root -e PHP_DATABASE_PASSWORD= -e PHP_CACHE_DRIVER=file)
docker run "${oem_args[@]}" likeadmin-install-worker-test:local php tests/oem-policy.php
docker run "${oem_args[@]}" likeadmin-install-worker-test:local php tests/oem-domain-probe.php
docker run "${oem_args[@]}" likeadmin-install-worker-test:local php tests/oem-migration-plan.php
for oem_test in oem-foundation.integration.php oem-orders-payment.integration.php oem-gateway.integration.php oem-wechat-notification.integration.php oem-prepay.integration.php oem-review.integration.php oem-provision.integration.php oem-logo.integration.php oem-refund.integration.php oem-domain.integration.php oem-maintenance.integration.php; do
  docker run "${oem_args[@]}" likeadmin-install-worker-test:local php "tests/$oem_test"
done
