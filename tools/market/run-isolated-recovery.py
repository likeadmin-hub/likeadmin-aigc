#!/usr/bin/env python3
"""Owner-only local C03 orchestration. No real DB/config/runtime/daemon mounts.

Run only after owner review, from server local develop containing the latest feature:
  python3 tools/market/run-isolated-recovery.py             # two image smoke cases
  python3 tools/market/run-isolated-recovery.py --full-matrix # smoke gate, then 19 more

The build context is the Dockerfile on stdin, never the source directory. A failed
smoke never expands the matrix. All container operations after creation use full,
immutable CIDs, verified owner labels and roles. No unowned container is exec'd.
"""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import subprocess
import sys
import tempfile


SERVER = Path(__file__).resolve().parents[2]
DOCKERFILE = SERVER / 'tests/fixtures/market-isolated-recovery/Dockerfile'
FEATURE = 'feature/short-drama-next-optimization'
BASE = 'php@sha256:8bfff0e5249f2073504c335b9f7ae54bbe84c3bc9338bfc8d2a04e77d112360b'
OWNER_LABEL = 'local.c03.owner'
ROLE_LABEL = 'local.c03.role'
HEX64 = re.compile(r'^[a-f0-9]{64}$')


class Failure(RuntimeError):
    pass


def require(ok, message):
    if not ok:
        raise Failure(message)


def digest(value):
    if isinstance(value, str):
        value = value.encode()
    return hashlib.sha256(value).hexdigest()


def atomic_json(path, value):
    path = Path(path)
    tmp = path.with_name(path.name + '.' + secrets.token_hex(4) + '.tmp')
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as stream:
        json.dump(value, stream, ensure_ascii=False, indent=2)
        stream.write('\n')
    os.replace(tmp, path)


class OwnedRun:
    def __init__(self, full_matrix):
        os.umask(0o077)
        self.owner = secrets.token_hex(12)
        self.root = Path(tempfile.mkdtemp(prefix='b81-c03-' + self.owner + '-', dir='/tmp'))
        (self.root / 'host-owner').write_text(self.owner)
        self.evidence = Path('/tmp/b81-c03-' + self.owner + '-evidence')
        self.evidence.mkdir(mode=0o700)
        self.report_path = Path('/tmp/b81-c03-' + self.owner + '-report.json')
        self.run_root = self.root / 'control'
        self.run_root.mkdir(mode=0o700)
        self.config = self.root / 'safe-config'
        self.config.mkdir(mode=0o700)
        self.media = self.root / 'media'
        self.media.mkdir(mode=0o700)
        self.runtime = self.root / 'runtime'
        self.runtime.mkdir(mode=0o700)
        self.volume = 'c03-mysql-' + self.owner
        self.names = {role: 'c03-b81-' + self.owner + '-' + role for role in ('mysql', 'php')}
        self.cids = {}
        self.images = {}
        self.created_volume = False
        self.secrets = [secrets.token_hex(24), secrets.token_hex(24)]
        self.mounts = []
        self.baseline = None
        self.feature_sha = None
        self.head_sha = None
        self.report = {
            'owner': self.owner, 'full_matrix_requested': full_matrix, 'cases': [],
            'scope': 'One-host isolated MySQL process recovery and owned HTTP wall-clock expiry only',
            'real_provider_calls': 0, 'original_database_connections': 0,
            'unowned_container_execs': 0, 'host_network_faults': 0,
            'physical_multinode_verified': False, 'host_power_loss_verified': False,
            'daemon1667_targeted': False, 'daemon1667_liveness_independently_verified': False,
            'volume_name': self.volume,
            'passed': False, 'cleanup': {},
        }

    def redact(self, value):
        text = str(value)
        for secret in self.secrets:
            text = text.replace(secret, '[owned-secret-redacted]')
        return text

    def progress(self, stage, **facts):
        print(json.dumps({'owner': self.owner, 'stage': stage, **facts}, ensure_ascii=False), flush=True)

    def call(self, args, *, input=None, timeout=60, check=True, cwd=None):
        try:
            result = subprocess.run(args, input=input, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    text=True, timeout=timeout, cwd=cwd)
        except subprocess.TimeoutExpired as error:
            raise Failure('Command timed out; stage output fingerprint=' + digest(str(error.stdout) + str(error.stderr))) from None
        if check and result.returncode != 0:
            # Never emit commands, raw inspect objects, env files or process environments.
            diagnostic = self.redact(result.stderr or result.stdout)
            raise Failure('Command failed code=' + str(result.returncode) + ' diagnostic=' + diagnostic[-1800:])
        return result

    def docker(self, *args, **options):
        return self.call(['docker', *args], **options)

    def git(self, *args, check=True):
        return self.call(['git', '-C', str(SERVER), *args], check=check).stdout.strip()

    def execution_gate(self):
        require(Path.cwd().resolve() == SERVER.resolve(), 'Invoke from the existing server checkout')
        require(self.git('branch', '--show-current') == 'develop', 'Execution is allowed only on local develop')
        require(self.git('status', '--porcelain', '--untracked-files=no') == '', 'Tracked working tree must be clean')
        feature = self.git('rev-parse', FEATURE)
        head = self.git('rev-parse', 'HEAD')
        ancestor = self.call(['git', '-C', str(SERVER), 'merge-base', '--is-ancestor', feature, head], check=False)
        require(ancestor.returncode == 0, 'Latest feature must already be integrated into local develop')
        if self.head_sha is not None:
            require((head, feature) == (self.head_sha, self.feature_sha), 'Source moved while the isolated run was active')
        else:
            self.head_sha, self.feature_sha = head, feature
            self.report.update({'develop_sha': head, 'feature_sha': feature})

    def local_docker_gate(self):
        require(shutil.which('docker') is not None, 'Docker CLI is unavailable')
        host_override = os.environ.get('DOCKER_HOST', '')
        require(not host_override or host_override.startswith('unix://'), 'Remote DOCKER_HOST is forbidden')
        context = json.loads(self.docker('context', 'inspect').stdout)
        require(len(context) == 1 and context[0]['Endpoints']['docker']['Host'].startswith('unix://'), 'Require a local Unix-socket Docker context')
        # Resolve local cached images by immutable IDs. No image pull is attempted.
        for role, ref in [('mysql', 'mysql:8.0'), ('base', BASE)]:
            item = json.loads(self.docker('image', 'inspect', ref).stdout)[0]
            require(re.fullmatch(r'sha256:[a-f0-9]{64}', item['Id']) is not None, 'Missing immutable image ID')
            self.images[role] = item['Id']
        self.baseline = self.unowned_snapshot()
        self.report['cached_image_ids'] = self.images.copy()

    def unowned_snapshot(self):
        ids = self.docker('container', 'ls', '-aq', '--no-trunc').stdout.split()
        rows = []
        if ids:
            for obj in json.loads(self.docker('container', 'inspect', *ids).stdout):
                if obj.get('Config', {}).get('Labels', {}).get(OWNER_LABEL) == self.owner:
                    continue
                rows.append({'id': obj['Id'], 'image': obj['Image'], 'status': obj['State']['Status'],
                             'started_at': obj['State']['StartedAt'], 'restart_count': obj['RestartCount']})
        return sorted(rows, key=lambda row: row['id'])

    def safe_mount(self, source, target, readonly=True):
        source = Path(source)
        require(source.exists() and not source.is_symlink(), 'A declared source mount is missing or is a symlink')
        require(',' not in str(source) and ',' not in target, 'Invalid mount path')
        require(target != '/source/.env' and not target.startswith('/var/run'), 'Forbidden mount target')
        self.mounts.append({'source': str(source.resolve()), 'target': target, 'readonly': readonly})

    def prepare_sources(self):
        self.execution_gate()
        # Only common application code and vendor dependencies are directory mounts.
        # Application code must contain tracked files only; no ignored local credentials.
        tracked = set(self.git('ls-files', '-z', '--', 'app/common').split('\0'))
        common = SERVER / 'app/common'
        for path in common.rglob('*'):
            if path.is_file() or path.is_symlink():
                rel = str(path.relative_to(SERVER))
                require(rel in tracked and not path.is_symlink(), 'Untracked or symlinked common application source; review before mounting')
        vendor = SERVER / 'vendor'
        for path in vendor.rglob('*'):
            if path.is_symlink():
                require(path.resolve().is_relative_to(vendor.resolve()), 'Vendor symlink escapes the dependency tree')
            require(path.name not in ('.env', 'configSecrets'), 'Local credentials may not be mounted through dependencies')
        self.safe_mount(common, '/source/app/common')
        self.safe_mount(vendor, '/source/vendor')
        for name in ('AppService.php', 'ExceptionHandle.php', 'Request.php', 'common.php', 'provider.php', 'service.php', 'event.php'):
            self.safe_mount(SERVER / 'app' / name, '/source/app/' + name)
        tests = ['Support/MarketWorkerFixture.php', 'Support/MarketIsolatedMysqlFixture.php',
                 'Support/MarketDbCommitBarrier.php', 'Support/MarketTimedSourceFixture.php',
                 'market-isolated-mysql-recovery.php', 'market-timed-source-recovery.php']
        hashes = {}
        for name in tests:
            path = SERVER / 'tests' / name
            self.safe_mount(path, '/source/tests/' + name)
            hashes[name] = digest(path.read_bytes())
        # Mount this single tracked installer source file, never public/ itself.
        installer = SERVER / 'public/install/db/like.sql'
        require(installer.is_file(), 'Expected source installer SQL is missing')
        self.safe_mount(installer, '/source/schema/like.sql')
        self.report['fixture_source_sha256'] = hashes
        self.report['installer_sha256'] = digest(installer.read_bytes())
        (self.run_root / 'supervisor-owner').write_text(self.owner)
        secret = {'database': 'x_cn', 'username': 'c03_' + self.owner[:12], 'password': self.secrets[0]}
        atomic_json(self.run_root / 'connection.json', secret)
        mysql_env = self.root / 'mysql.env'
        mysql_env.write_text('MYSQL_DATABASE=x_cn\nMYSQL_USER=' + secret['username'] + '\nMYSQL_PASSWORD=' + self.secrets[0]
                             + '\nMYSQL_ROOT_PASSWORD=' + self.secrets[1] + '\n')
        os.chmod(mysql_env, 0o600)
        source = (SERVER / 'tests/Support/MarketIsolatedMysqlFixture.php').read_text()
        matches = re.findall(r"return <<<'SH'\n(.*?)\nSH;", source, flags=re.S)
        require(len(matches) == 1 and 'docker-entrypoint.sh mysqld' in matches[0], 'Cannot identify the reviewed supervisor literal')
        self.supervisor = self.root / 'c03-mysql-supervisor.sh'
        self.supervisor.write_text(matches[0] + '\n')
        os.chmod(self.supervisor, 0o600)
        # All environment-dependent configuration is newly generated for this DB.
        # No original config/, .env, public/, runtime/, extend/ or data/ mount exists.
        (self.config / 'database.php').write_text("<?php\n$s=json_decode(file_get_contents('/c03/run/connection.json'),true,16,JSON_THROW_ON_ERROR);return ['default'=>'mysql','auto_timestamp'=>true,'datetime_format'=>'Y-m-d H:i:s','connections'=>['mysql'=>['type'=>'mysql','hostname'=>'127.0.0.1','hostport'=>3306,'database'=>'x_cn','username'=>$s['username'],'password'=>$s['password'],'charset'=>'utf8mb4','prefix'=>'la_','deploy'=>0,'rw_separate'=>false,'fields_strict'=>true,'break_reconnect'=>false,'trigger_sql'=>false,'fields_cache'=>false]]];\n")
        (self.config / 'app.php').write_text("<?php return ['app_namespace'=>'','with_route'=>false,'default_timezone'=>'Asia/Shanghai','show_error_msg'=>false];\n")
        (self.config / 'cache.php').write_text("<?php return ['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>'/source/runtime/cache/','prefix'=>'c03_','expire'=>0]]];\n")
        (self.config / 'log.php').write_text("<?php return ['default'=>'file','channels'=>['file'=>['type'=>'File','path'=>'/source/runtime/log/','single'=>true,'apart_level'=>[]]]];\n")
        (self.config / 'filesystem.php').write_text("<?php return ['default'=>'local','disks'=>['local'=>['type'=>'local','root'=>'/source/runtime/storage']]];\n")
        self.safe_mount(self.config, '/source/config')
        self.safe_mount(self.runtime, '/source/runtime', False)
        self.safe_mount(self.run_root, '/c03/run', False)
        self.safe_mount(self.media, '/c03/media', False)

    def build_image(self):
        self.execution_gate()
        require(DOCKERFILE.is_file() and not DOCKERFILE.is_symlink(), 'Reviewed standalone Dockerfile is missing')
        content = DOCKERFILE.read_text()
        require(content.splitlines()[0] == 'FROM ' + BASE, 'Dockerfile base differs from reviewed digest')
        require(not re.search(r'^\s*(ADD|COPY)\b', content, re.M | re.I), 'Tool build may not copy a filesystem context')
        tag = 'local/c03-php82-b81:' + self.owner
        self.progress('building_owned_tool_image')
        built = self.docker('build', '--pull=false', '--label', OWNER_LABEL + '=' + self.owner,
                            '--label', ROLE_LABEL + '=tool', '--tag', tag, '-', input=content, timeout=1200, check=False)
        (self.evidence / 'tool-build.log').write_text(self.redact(built.stdout + built.stderr))
        require(built.returncode == 0, 'Owned tool image build failed; sanitized log retained')
        item = json.loads(self.docker('image', 'inspect', tag).stdout)[0]
        require(item['Config']['Labels'].get(OWNER_LABEL) == self.owner, 'Built image owner differs')
        self.images['php'] = item['Id']
        self.report['tool_image_id'] = item['Id']
        self.report['tool_dockerfile_sha256'] = digest(content)

    @staticmethod
    def bind_source_matches(actual, expected):
        # Docker Desktop reports this exact alias for a host bind. Do not strip
        # prefixes, normalize actual paths, or accept sibling/prefix matches.
        return isinstance(actual, str) and actual in (expected, '/host_mnt' + expected)

    def inspect_owned_identity(self, role):
        cid = self.cids[role]
        require(HEX64.fullmatch(cid) is not None, 'Invalid immutable CID')
        obj = json.loads(self.docker('container', 'inspect', cid).stdout)[0]
        labels = obj.get('Config', {}).get('Labels') or {}
        require(obj['Id'] == cid and obj['Name'] == '/' + self.names[role]
                and labels.get(OWNER_LABEL) == self.owner and labels.get(ROLE_LABEL) == role,
                'Container identity or owner label differs')
        require(obj['Image'] == self.images[role], 'Container image differs')
        return obj

    def inspect_declared_mounts(self, role, obj):
        mounts = obj['Mounts']
        if role == 'mysql':
            volumes = [m for m in mounts if m['Type'] == 'volume']
            require(len(volumes) == 1 and volumes[0].get('Name') == self.volume
                    and volumes[0]['Destination'] == '/var/lib/mysql' and volumes[0]['RW'] is True, 'Private data volume differs')
            declared = {'/control': (str(self.run_root.resolve()), False),
                        '/controller/c03-mysql-supervisor.sh': (str(self.supervisor.resolve()), True)}
            require(len(mounts) == 3, 'Unexpected MySQL mount count')
        else:
            declared = {m['target']: (m['source'], m['readonly']) for m in self.mounts}
            # --tmpfs is normally represented only in HostConfig.Tmpfs. If the
            # engine also lists it in Mounts, accept only the declared /tmp.
            extras = [m for m in mounts if m['Type'] != 'bind']
            require(all(m['Type'] == 'tmpfs' and m['Destination'] == '/tmp'
                        and m['RW'] is True for m in extras) and len(extras) <= 1, 'Unexpected runner non-bind mount')
        binds = [m for m in mounts if m['Type'] == 'bind']
        actual_targets = [m['Destination'] for m in binds]
        require(len(binds) == len(declared) and len(set(actual_targets)) == len(binds)
                and set(actual_targets) == set(declared), 'Declared bind mount target set differs')
        for mount in binds:
            expected_source, readonly = declared[mount['Destination']]
            require(self.bind_source_matches(mount['Source'], expected_source)
                    and mount['RW'] is (not readonly), 'Declared bind source or access differs')

    def inspect_owned_for_cleanup(self, role):
        # Recovery deliberately has its own fresh identity/image/mount check.
        # A failed execution preflight never authorizes removal by name alone.
        obj = self.inspect_owned_identity(role)
        self.inspect_declared_mounts(role, obj)
        return obj

    def inspect_owned(self, role):
        obj = self.inspect_owned_identity(role)
        self.inspect_declared_mounts(role, obj)
        if role == 'mysql':
            require(obj['HostConfig']['NetworkMode'] == 'none', 'MySQL must have no external network')
            require(not obj['HostConfig']['Privileged'] and not obj['HostConfig']['PortBindings'], 'Unexpected MySQL host privileges or published ports')
        else:
            expected = 'container:' + self.cids['mysql']
            require(obj['HostConfig']['NetworkMode'] == expected and obj['HostConfig']['PidMode'] == expected, 'Runner does not share the exact owned namespace')
            require(obj['HostConfig']['ReadonlyRootfs'] and not obj['HostConfig']['Privileged'], 'Unexpected writable or privileged runner')
        return obj

    def record_resources(self):
        self.report['container_ids'] = self.cids.copy()
        # Keep enough private metadata for owner recovery even when a strict
        # structural check fails immediately after docker create.
        atomic_json(self.root / 'recovery-owned.json', {'owner': self.owner, 'container_ids': self.cids,
            'container_names': self.names, 'image_ids': self.images, 'volume_name': self.volume,
            'runner_declared_mounts': self.mounts, 'mysql_control_root': str(self.run_root.resolve()),
            'mysql_supervisor': str(self.supervisor.resolve()) if hasattr(self, 'supervisor') else None})
        atomic_json(self.report_path, self.report)

    def inspect_volume(self):
        value = json.loads(self.docker('volume', 'inspect', self.volume).stdout)[0]
        labels = value.get('Labels') or {}
        require(value['Name'] == self.volume and value['Driver'] == 'local'
                and labels.get(OWNER_LABEL) == self.owner and labels.get(ROLE_LABEL) == 'mysql-data', 'Private volume ownership differs')

    def recover_creation_identity(self, role):
        result = self.docker('container', 'inspect', self.names[role], check=False)
        if result.returncode == 0:
            value = json.loads(result.stdout)[0]
            labels = value.get('Config', {}).get('Labels') or {}
            if labels.get(OWNER_LABEL) == self.owner and labels.get(ROLE_LABEL) == role and HEX64.fullmatch(value['Id']):
                self.cids[role] = value['Id']
                self.record_resources()

    def create_container(self, role, arguments):
        self.execution_gate()
        require(self.docker('container', 'inspect', self.names[role], check=False).returncode != 0, 'Unique container name already exists')
        try:
            cid = self.docker('create', '--name', self.names[role], '--label', OWNER_LABEL + '=' + self.owner,
                              '--label', ROLE_LABEL + '=' + role, *arguments).stdout.strip()
            require(HEX64.fullmatch(cid) is not None, 'Docker did not return one full CID')
            self.cids[role] = cid
            self.record_resources()
        finally:
            if role not in self.cids:
                self.recover_creation_identity(role)
        self.inspect_owned(role)

    def start_resources(self):
        self.execution_gate()
        require(self.docker('volume', 'inspect', self.volume, check=False).returncode != 0, 'Unique volume already exists')
        try:
            self.docker('volume', 'create', '--label', OWNER_LABEL + '=' + self.owner,
                        '--label', ROLE_LABEL + '=mysql-data', self.volume)
        finally:
            probe = self.docker('volume', 'inspect', self.volume, check=False)
            if probe.returncode == 0:
                data = json.loads(probe.stdout)[0]
                self.created_volume = (data.get('Labels') or {}).get(OWNER_LABEL) == self.owner
        self.inspect_volume()
        self.create_container('mysql', ['--pull=never', '--network=none', '--memory=1g', '--cpus=2',
            '--env-file', str(self.root / 'mysql.env'),
            '--mount', 'type=volume,source=' + self.volume + ',target=/var/lib/mysql',
            '--mount', 'type=bind,source=' + str(self.run_root) + ',target=/control',
            '--mount', 'type=bind,source=' + str(self.supervisor) + ',target=/controller/c03-mysql-supervisor.sh,readonly',
            '--entrypoint', '/bin/sh', self.images['mysql'], '/controller/c03-mysql-supervisor.sh'])
        self.execution_gate()
        self.inspect_owned('mysql')
        self.docker('start', self.cids['mysql'])
        options = ['--pull=never', '--network=container:' + self.cids['mysql'], '--pid=container:' + self.cids['mysql'],
            '--read-only', '--cap-drop=ALL', '--cap-add=KILL', '--security-opt=no-new-privileges',
            '--memory=512m', '--cpus=2', '--tmpfs', '/tmp:rw,nosuid,nodev,size=256m,mode=1777',
            '--workdir', '/source', '--env', 'C03_RUN_ROOT=/c03/run', '--env', 'C03_INSTALL_SQL=/source/schema/like.sql',
            '--env', 'TMPDIR=/tmp']
        for mount in self.mounts:
            options += ['--mount', 'type=bind,source=' + mount['source'] + ',target=' + mount['target'] + (',readonly' if mount['readonly'] else '')]
        options += ['--entrypoint', 'php', self.images['php'], '-r', 'while (true) { sleep(3600); }']
        self.create_container('php', options)
        atomic_json(self.run_root / 'owner.json', {'owner': self.owner, 'mysql_container_id': self.cids['mysql'],
            'php_container_id': self.cids['php'], 'volume': self.volume, 'namespace_contract': 'owned-mysql-network-and-pid'})
        self.execution_gate()
        self.inspect_owned('php')
        self.docker('start', self.cids['php'])
        self.report['volume_name'] = self.volume

    def owned_exec(self, *args, timeout=120, check=True):
        self.execution_gate()
        self.inspect_owned('mysql')
        self.inspect_owned('php')
        return self.docker('exec', self.cids['php'], *args, timeout=timeout, check=check)

    def preflight(self):
        self.progress('owned_runner_preflight')
        code = r'''$required=['curl','fileinfo','mbstring','posix','pdo_mysql','pcntl','gd','zip'];foreach($required as $e){if(!extension_loaded($e))exit(31);}if(!function_exists('proc_open')||is_file('/source/.env')||is_dir('/source/public')||!is_executable('/usr/bin/ffprobe'))exit(32);$s=json_decode(file_get_contents('/c03/run/connection.json'),true);$until=microtime(true)+80;do{try{$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=x_cn;charset=utf8mb4',$s['username'],$s['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>2]);if($pdo->query('SHOW TABLES')->fetchAll()!==[])exit(33);$ok=true;break;}catch(Throwable $e){usleep(250000);}}while(microtime(true)<$until);if(empty($ok))exit(34);echo json_encode(['php'=>PHP_VERSION,'extensions'=>$required,'empty_private_database'=>true,'real_env_absent'=>true,'real_public_absent'=>true]);'''
        result = self.owned_exec('php', '-d', 'disable_functions=', '-r', code, timeout=100)
        self.report['runner_preflight'] = json.loads(result.stdout)
        # This is owned media creation, never an uploaded/provider file.
        self.owned_exec('ffmpeg', '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-f', 'lavfi',
                        '-i', 'color=c=black:s=32x32:r=4', '-t', '1', '-an', '-c:v', 'libx264',
                        '-pix_fmt', 'yuv420p', '-threads', '1', '-movflags', '+faststart', '/c03/media/synthetic.mp4')
        probe = self.owned_exec('ffprobe', '-v', 'error', '-show_entries', 'format=duration:stream=codec_type,width,height',
                                '-of', 'json', '/c03/media/synthetic.mp4')
        self.report['synthetic_media_probe'] = json.loads(probe.stdout)
        self.report['synthetic_media_sha256'] = digest((self.media / 'synthetic.mp4').read_bytes())
        self.report['ffprobe_version'] = self.owned_exec('ffprobe', '-version').stdout.splitlines()[0]

    def case(self, family, kind, mode, smoke=False):
        self.execution_gate()
        self.progress('case_start', family=family, kind=kind, mode=mode, smoke=smoke)
        script = '/source/tests/' + ('market-isolated-mysql-recovery.php' if family == 'db' else 'market-timed-source-recovery.php')
        result = self.owned_exec('php', '-d', 'disable_functions=', script, kind, mode, timeout=240, check=False)
        name = family + '-' + kind + '-' + mode
        safe_output = self.redact(result.stdout)
        (self.evidence / (name + '.log')).write_text(safe_output + '\nSTDERR:\n' + self.redact(result.stderr))
        try:
            report = json.loads(safe_output)
        except (ValueError, TypeError):
            report = {'passed': False, 'invalid_json_output_sha256': digest(safe_output)}
        row = {'family': family, 'kind': kind, 'mode': mode, 'smoke': smoke, 'exit_code': result.returncode, 'result': report}
        atomic_json(self.evidence / (name + '.json'), row)
        self.report['cases'].append(row)
        atomic_json(self.report_path, self.report)
        require(result.returncode == 0 and report.get('passed') is True and report.get('cleanup_complete') is True,
                'Owned case failed; stop before expanding matrix: ' + name)
        self.progress('case_pass', family=family, kind=kind, mode=mode)

    def run_cases(self, full_matrix):
        smoke = [('db', 'image', 'before-receipt-commit'), ('source', 'image', 'lease-expired-then-renew')]
        for family, kind, mode in smoke:
            self.case(family, kind, mode, smoke=True)
        self.report['two_image_smokes_passed'] = True
        if full_matrix:
            db_modes = ['before-receipt-commit', 'after-receipt-commit', 'after-asset-commit']
            source_modes = ['lease-expired-then-renew', 'truncate-then-expire-then-renew',
                            'fixed-query-expired-no-refresh', 'stored-then-lease-expired']
            for family, modes in [('db', db_modes), ('source', source_modes)]:
                for kind in ['image', 'video', 'audio']:
                    for mode in modes:
                        if (family, kind, mode) not in smoke:
                            self.case(family, kind, mode)
        self.execution_gate()
        self.report['passed'] = True

    def cleanup(self):
        proof = self.report['cleanup']
        for role in ('php', 'mysql'):
            try:
                if role not in self.cids:
                    self.recover_creation_identity(role)
                if role in self.cids:
                    try:
                        self.inspect_owned(role)
                    except BaseException as error:
                        proof[role + '_strict_cleanup_preflight_error'] = self.redact(str(error))
                        self.report['passed'] = False
                        self.inspect_owned_for_cleanup(role)
                        proof[role + '_minimal_recovery_identity_image_mounts_verified'] = True
                    if not self.report['passed']:
                        try:
                            log = self.docker('logs', self.cids[role], check=False)
                            (self.evidence / ('failed-' + role + '-container.log')).write_text(self.redact(log.stdout + log.stderr))
                        except BaseException as error:
                            proof[role + '_log_collection_error'] = self.redact(str(error))
                    self.docker('rm', '-f', self.cids[role])
            except BaseException as error:
                proof[role + '_cleanup_error'] = self.redact(str(error))
        try:
            all_cids = set(self.docker('container', 'ls', '-aq', '--no-trunc').stdout.split())
            labelled = set(self.docker('container', 'ls', '-aq', '--no-trunc', '--filter',
                                       'label=' + OWNER_LABEL + '=' + self.owner).stdout.split())
            remaining = (set(self.cids.values()) & all_cids) | labelled
            proof['exact_owned_containers_absent'] = not remaining
            if remaining:
                proof['retained_owner_container_ids'] = sorted(remaining)
            require(proof['exact_owned_containers_absent'], 'An owned CID remains; retain its private volume')
            if self.created_volume:
                self.inspect_volume()
                self.docker('volume', 'rm', self.volume)
            names = set(self.docker('volume', 'ls', '--format', '{{.Name}}').stdout.splitlines())
            proof['exact_owned_volume_absent'] = self.volume not in names
        except BaseException as error:
            proof['volume_cleanup_error'] = self.redact(str(error))
        try:
            if self.baseline is not None:
                after = self.unowned_snapshot()
                proof['unowned_containers_state_unchanged'] = after == self.baseline
                proof['unowned_before_sha256'] = digest(json.dumps(self.baseline, sort_keys=True))
                proof['unowned_after_sha256'] = digest(json.dumps(after, sort_keys=True))
            proof['source_still_develop_and_unchanged'] = False
            self.execution_gate()
            proof['source_still_develop_and_unchanged'] = True
        except BaseException as error:
            proof['invariant_error'] = self.redact(str(error))
        # A failed cleanup must retain the 0700 owner root and its 0600
        # connection/owner/recovery manifests. Never delete a live bind source.
        try:
            resources_gone = proof.get('exact_owned_containers_absent') is True and proof.get('exact_owned_volume_absent') is True
            if resources_gone and (self.root / 'host-owner').is_file() and (self.root / 'host-owner').read_text() == self.owner:
                shutil.rmtree(self.root)
                proof['private_host_root_removed'] = not self.root.exists()
            elif self.root.is_dir():
                self.record_resources()
                os.chmod(self.root, 0o700)
                for relative in ['host-owner', 'mysql.env', 'recovery-owned.json', 'control/owner.json',
                                 'control/connection.json', 'control/supervisor-owner']:
                    path = self.root / relative
                    if path.is_file() and not path.is_symlink():
                        os.chmod(path, 0o600)
                proof['private_host_root_removed'] = False
                proof['private_host_root_retained_for_owner'] = str(self.root)
                proof['necessary_owned_manifests_retained'] = True
        except BaseException as error:
            proof['host_root_cleanup_error'] = self.redact(str(error))
        proof['complete'] = all(proof.get(key) is True for key in
            ['exact_owned_containers_absent', 'exact_owned_volume_absent', 'unowned_containers_state_unchanged',
             'source_still_develop_and_unchanged', 'private_host_root_removed'])
        if not proof['complete']:
            self.report['passed'] = False
        self.report['tool_image_retained_for_owner'] = self.images.get('php')
        self.report['evidence_directory'] = str(self.evidence)
        atomic_json(self.report_path, self.report)
        self.progress('complete', passed=self.report['passed'], cleanup_complete=proof['complete'], report=str(self.report_path))


def interrupted(signum, frame):
    raise Failure('Owner process interrupted by signal ' + str(signum))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--full-matrix', action='store_true')
    args = parser.parse_args()
    signal.signal(signal.SIGTERM, interrupted)
    owned = OwnedRun(args.full_matrix)
    try:
        owned.execution_gate()
        owned.local_docker_gate()
        owned.prepare_sources()
        owned.build_image()
        owned.start_resources()
        owned.preflight()
        owned.run_cases(args.full_matrix)
    except BaseException as error:
        owned.report['passed'] = False
        owned.report['failure'] = {'class': type(error).__name__, 'message': owned.redact(str(error))}
        owned.progress('failed', failure_class=type(error).__name__, message=owned.redact(str(error)))
    finally:
        owned.cleanup()
    return 0 if owned.report['passed'] else 1


if __name__ == '__main__':
    sys.exit(main())
