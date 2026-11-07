# Local PHPUnit

The runtime vendor tree may exclude Composer development packages. Run
`python3 scripts/setup-phpunit.py` from the server repository to install the
PHPUnit version pinned by `composer.lock`. The official PHAR is checked against
its pinned SHA256 before it becomes executable. `--source /path/to/file.phar`
supports an already downloaded copy with the same checksum.

The installer creates `vendor/bin/phpunit` as a relative symlink to
`.composer-runtime/test-tools/`. It preserves an existing runner and all
application dependencies. These generated files must stay untracked.

Use PHP 8.2 with the extensions required by `composer.json`:

```sh
php vendor/bin/phpunit --do-not-cache-result
```

Run tests from local `develop` after merging a repair checkpoint. Database
fixtures must use an isolated local database; never load customer or production
credentials into a test runner. For pure tests in a container, use a read-only
source mount, a temporary writable `runtime` directory and no network access.
An absent optional fixture is a reported skip, not a completed integration test.
