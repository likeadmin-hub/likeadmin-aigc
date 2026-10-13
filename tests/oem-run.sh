#!/usr/bin/env bash
set -euo pipefail
oem_root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
oem_args=(--rm --network likeadmin-oem-dev -v "$oem_root:/repo:ro" --tmpfs /repo/runtime -w /repo
  -e OEM_DISPOSABLE=1 -e PHP_DATABASE_HOSTNAME=oem-mysql -e PHP_DATABASE_DATABASE=oem_dev
  -e PHP_DATABASE_USERNAME=root -e PHP_DATABASE_PASSWORD= -e PHP_CACHE_DRIVER=file)
docker run "${oem_args[@]}" likeadmin-install-worker-test:local php tests/oem-policy.php
for oem_test in oem-foundation.integration.php oem-orders-payment.integration.php oem-gateway.integration.php oem-prepay.integration.php; do
  docker run "${oem_args[@]}" likeadmin-install-worker-test:local php "tests/$oem_test"
done
