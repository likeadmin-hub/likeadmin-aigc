#!/usr/bin/env python3
"""Actual signed 392 archive through genuine 391 services in an internal disposable network.

This creates only private Docker resources and a temporary genuine Git snapshot.
No existing database, paid upstream, production grant, source branch or ZIP is changed.
"""
import argparse
import hashlib
import io
import json
import os
import pathlib
import shutil
import stat
import subprocess
import tarfile
import tempfile
import time
import uuid
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
GENUINE391 = 'a99837c01d9e78ced1e7ac56bf872f509c57ea6d'
OLD_SERVICE_SHA = '3857377ab20a5806c280dad2ace544fe96f0e2be493ae953c291825dcba32844'

def digest(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for part in iter(lambda: f.read(1024 * 1024), b''):
            h.update(part)
    return h.hexdigest()

def require(value, label):
    if not value:
        raise RuntimeError(label)

def run(*args, data=None, quiet=False):
    result = subprocess.run(args, input=data, text=True, capture_output=True)
    if result.stdout and not quiet:
        print(result.stdout, end='' if result.stdout.endswith('\n') else '\n', flush=True)
    if result.returncode:
        raise RuntimeError(result.stderr.strip() or 'Command failed: ' + args[0])
    return result.stdout

def verify_zip(path):
    with zipfile.ZipFile(path) as z:
        entries = [item for item in z.infolist() if not item.is_dir()]
        names = [item.filename for item in entries]
        require(len(names) == len(set(names)), 'Duplicate ZIP entries')
        signature = json.loads(z.read('signature.json'))
        hashes = dict(signature.get('sha256', {}))
        for item in signature.get('files', []):
            if item['path'] in hashes:
                require(hashes[item['path']] == item['sha256'], 'Signature maps disagree')
            hashes[item['path']] = item['sha256']
        for item in entries:
            name = pathlib.PurePosixPath(item.filename)
            require(not name.is_absolute() and '..' not in name.parts and '\\' not in item.filename, 'Unsafe ZIP path')
            require(not stat.S_ISLNK(item.external_attr >> 16), 'ZIP symlink prohibited')
            if item.filename == 'signature.json':
                continue
            require(hashes.get(item.filename) == hashlib.sha256(z.read(item)).hexdigest(), 'ZIP file signature differs: ' + item.filename)
        require(set(hashes) == set(names) - {'signature.json'}, 'Signature inventory differs')
        return json.loads(z.read('update.json'))

def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--package', '--archive', dest='archive', type=pathlib.Path, required=True, help='Actual verified final system_1.0.392.zip (fresh v2 output)')
    p.add_argument('--baseline-archive', type=pathlib.Path, required=True, help='Previously published final system_1.0.391.zip')
    p.add_argument('--production', type=pathlib.Path, required=True, help='Verified production Composer391 directory')
    p.add_argument('--php-image', default=os.environ.get('INSTALL_PARITY_PHP_IMAGE', 'likeadmin-install-worker-test:local'))
    p.add_argument('--report', type=pathlib.Path, default=pathlib.Path('/tmp/release-391-to-392-fixture.json'))
    args = p.parse_args()
    archive, oldzip, production = args.archive.resolve(), args.baseline_archive.resolve(), args.production.resolve()
    report = {'passed': False, 'baseline_commit': GENUINE391, 'stage': 'source-validation', 'observations': []}
    name = 'aigc-391-392-' + uuid.uuid4().hex[:10]
    network, php, volume = name + '-network', name + '-php', name + '-site'
    resources = []
    try:
        require(archive.is_file() and oldzip.is_file(), 'Both actual release archives are required')
        require((production / 'vendor/services.php').is_file(), 'Production391 vendor is incomplete')
        require(run('git', '-C', str(ROOT), 'rev-parse', GENUINE391 + '^{commit}', quiet=True).strip() == GENUINE391, 'Wrong genuine391 source')
        old = verify_zip(oldzip)
        require(old.get('runtime_bootstrap', {}).get('target_version') == '1.0.391', 'Old ZIP is not final atomic391')
        new = verify_zip(archive)
        require(new.get('base_version') == '1.0.391' and new.get('target_version') == '1.0.392' and new.get('version') == '1.0.392', 'Wrong actual update span')
        require('runtime_bootstrap' not in new and new.get('package_mode') == 'incremental', '392 must be ordinary incremental')
        run('node', str(ROOT / 'scripts/verify-system-update-package.mjs'), str(archive))
        report['baseline_archive_sha256'], report['archive_sha256'] = digest(oldzip), digest(archive)
        with zipfile.ZipFile(archive) as z:
            coverage = json.loads(z.read('release-change-manifest.json'))
            require(coverage['base_ref'] == GENUINE391, 'Actual392 package source baseline differs')
            rows = coverage['migrations']
            require(len(rows) == 13 and sum(r['execution_owner'] == 'app/runtime' for r in rows) == 10 and sum(r['execution_owner'] == 'system' for r in rows) == 3, 'Expected exact10 app/3 system provenance')
            require(set(r['package_path'] for r in rows) == set(new['sql_order']), 'SQL order/provenance mismatch')
        # Images must already be available; this harness never downloads runtimes.
        run('docker', 'image', 'inspect', 'mysql:8.0', quiet=True)
        run('docker', 'image', 'inspect', args.php_image, quiet=True)
        with tempfile.TemporaryDirectory(prefix='genuine391-release-') as temporary:
            baseline = pathlib.Path(temporary) / 'baseline'
            baseline.mkdir()
            data = subprocess.check_output(['git', '-C', str(ROOT), 'archive', GENUINE391])
            with tarfile.open(fileobj=io.BytesIO(data)) as tar:
                for item in tar.getmembers():
                    path = pathlib.PurePosixPath(item.name)
                    require(not path.is_absolute() and '..' not in path.parts and (item.isdir() or item.isfile()), 'Unsafe Git archive member')
                tar.extractall(baseline)
            require(digest(baseline / 'app/common/service/update/SystemPackageUpdateService.php') == OLD_SERVICE_SHA, 'Genuine391 updater bytes differ')
            with zipfile.ZipFile(oldzip) as z:
                for relative in ['app/common/service/update/SystemPackageUpdateService.php', 'app/common/service/update/ComposerRuntimeUpdateService.php', 'runtime-loader.php', 'scripts/composer-runtime-runner.php']:
                    require(z.read('bootstrap-payload/files/' + relative) == (baseline / relative).read_bytes(), 'Released391 and Git bytes disagree: ' + relative)
                (baseline / 'config/runtime_migration_baseline.php').write_bytes(z.read('bootstrap-payload/files/config/runtime_migration_baseline.php'))
            for file in ['composer.json', 'composer.lock']:
                require((baseline / file).read_bytes() == (production / file).read_bytes(), 'Production391 dependency manifest differs')
            if (baseline / 'vendor').exists():
                shutil.rmtree(baseline / 'vendor')
            require(not any(item.is_symlink() for item in (production / 'vendor').rglob('*')), 'Production vendor symlinks prohibited')
            shutil.copytree(production / 'vendor', baseline / 'vendor', ignore=shutil.ignore_patterns('.DS_Store', '.phpunit.result.cache'))
            report['stage'] = 'private-resources'
            run('docker', 'network', 'create', '--internal', network, quiet=True); resources.append(('network', network))
            run('docker', 'run', '-d', '--name', name, '--network', network, '--network-alias', 'mysql', '-e', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', 'mysql:8.0', '--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci', quiet=True); resources.append(('container', name))
            for _ in range(60):
                if subprocess.run(['docker', 'exec', name, 'mysqladmin', '-h127.0.0.1', 'ping', '--silent'], capture_output=True).returncode == 0:
                    break
                time.sleep(1)
            else:
                raise RuntimeError('Private MySQL startup timeout')
            db = 'fixture_release_391_392'
            run('docker', 'exec', '-i', name, 'mysql', '-h127.0.0.1', '-uroot', '-N', 'mysql', data='CREATE DATABASE ' + db + ';', quiet=True)
            report['stage'] = 'genuine391-schema'
            run('docker', 'exec', '-i', name, 'mysql', '-h127.0.0.1', '-uroot', '--default-character-set=utf8mb4', db, data=(baseline / 'public/install/db/like.sql').read_text(), quiet=True)
            run('docker', 'volume', 'create', volume, quiet=True); resources.append(('volume', volume))
            run('docker', 'run', '-d', '--name', php, '--network', network, '--hostname', 'system-release-fixture', '-v', str(ROOT) + ':/repo:ro', '-v', str(baseline) + ':/baseline:ro', '-v', str(archive) + ':/release392.zip:ro', '-v', volume + ':/fixture', '-w', '/repo', '-e', 'SYSTEM_SERVICE_DISPOSABLE=1', '-e', 'PHP_DATABASE_DATABASE=' + db, '-e', 'PHP_CACHE_DRIVER=file', args.php_image, 'sleep', 'infinity', quiet=True); resources.append(('container', php))
            def phase(mode):
                report['stage'] = mode
                output = run('docker', 'exec', php, 'php', 'tests/runtime-updater/system-service.release-391.php', mode)
                records = [json.loads(line[7:]) for line in output.splitlines() if line.startswith('RESULT ')]
                require(len(records) == 1 and records[0].get('passed'), 'No verified phase result: ' + mode)
                report['observations'].append(records[0])
                return records[0]
            phase('prepare'); phase('apply'); first = phase('observe'); second = phase('observe')
            require(first['migration_rows_sha256'] == second['migration_rows_sha256'] and first['document_rows_sha256'] == second['document_rows_sha256'], 'Repeated fresh observation replayed migration/document writes')
            report['stage'] = 'independent-site-entry'
            output = run('docker', 'exec', php, 'php', '/fixture/site/think', 'list', '--raw', quiet=True)
            require('short-drama:' in output and 'Exception' not in output, 'Actual site CLI failed')
            run('docker', 'exec', '-d', php, 'php', '-S', '127.0.0.1:9014', '-t', '/fixture/site/public', '/fixture/site/public/index.php', quiet=True)
            phase('http')
            report['passed'], report['stage'] = True, 'complete'
    except Exception as error:
        report['error'] = str(error)
        print('FAIL ' + report['stage'] + ': ' + str(error), flush=True)
        if ('container', php) in resources:
            inspected = subprocess.run(['docker', 'exec', php, 'php', 'tests/runtime-updater/system-service.release-391.php', 'inspect'], capture_output=True, text=True)
            report['failure_observer'] = inspected.stdout.strip()
        raise
    finally:
        for kind, value in reversed(resources):
            command = ['docker', 'rm', '-f', '-v', value] if kind == 'container' else ['docker', kind, 'rm', value]
            result = subprocess.run(command, capture_output=True, text=True)
            if result.returncode:
                report.setdefault('cleanup_errors', []).append(value + ': ' + result.stderr.strip())
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
        print('Report ' + str(args.report) + '; disposed private resources; no host ports/business database.', flush=True)

if __name__ == '__main__':
    main()
