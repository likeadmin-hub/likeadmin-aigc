#!/usr/bin/env python3
"""Execute the real unified shell entry in an isolated checkout, without AI/DB calls."""
import os
from pathlib import Path
import shutil
import signal
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
EXPECTED = {'ai:task-worker', 'short-drama:episode-worker', 'short-drama:planning-worker',
            'short-drama:canvas-agent-worker', 'decoration:agent-worker', 'brand:worker', 'home-agent:worker'}

def until(check, label):
    deadline = time.monotonic() + 8
    while time.monotonic() < deadline:
        if check():
            return
        time.sleep(.05)
    raise AssertionError(label)

with tempfile.TemporaryDirectory(prefix='aigc-worker-entry-') as directory:
    site = Path(directory) / 'site'
    (site / 'scripts').mkdir(parents=True)
    (site / 'runtime').mkdir()
    (site / 'think').write_text('fixture')
    shutil.copy(ROOT / 'scripts/start-ai-task-worker.sh', site / 'scripts')
    shutil.copy(ROOT / 'scripts/home-agent-php.sh', site / 'scripts')
    fake = Path(directory) / 'php-fixture'
    fake.write_text('''#!/usr/bin/env python3
import os, signal, sys, time
from pathlib import Path
if sys.argv[1]=='-r':sys.exit(0)
if sys.argv[1]=='-d':del sys.argv[1:3]
root=Path(sys.argv[1]).parent
command=sys.argv[2]
if command=='virtualpay:maintain': sys.exit(0)
with (root/'runtime/started').open('a') as f: f.write(f'{os.getpid()} {command}\\n')
def stop(*args):
    with (root/'runtime/stopped').open('a') as f: f.write(command+'\\n')
    sys.exit(0)
signal.signal(signal.SIGTERM,stop)
signal.signal(signal.SIGINT,stop)
while True:
    if command=='decoration:agent-worker' and (root/'runtime/fail').exists(): sys.exit(7)
    time.sleep(.05)
''')
    fake.chmod(0o755)
    env = dict(os.environ, PHP_BIN=str(fake))

    def commands(name):
        p = site / 'runtime' / name
        return set(p.read_text().splitlines()) if p.exists() else set()

    def started():
        return {line.split()[1] for line in commands('started')}

    def launch():
        return subprocess.Popen(['/bin/sh', str(site / 'scripts/start-ai-task-worker.sh')],
                                env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                start_new_session=True)

    other = Path(directory) / 'other'
    (other / 'runtime').mkdir(parents=True)
    (other / 'think').write_text('fixture')
    neighbour = subprocess.Popen([str(fake), str(other / 'think'), 'decoration:agent-worker'])
    owner = launch()
    try:
        until(lambda: started() == EXPECTED, 'unified entry did not start all current queues')
        owner.send_signal(signal.SIGTERM)
        out, err = owner.communicate(timeout=8)
        assert owner.returncode == 143, (owner.returncode, out, err)
        assert commands('stopped') == EXPECTED, 'shutdown did not terminate every owned queue'
        assert neighbour.poll() is None, 'shutdown killed another project worker'
        (site / 'runtime/stopped').unlink()
        owner = launch()
        until(lambda: len(commands('started')) == len(EXPECTED) * 2, 'restart failed to relaunch current queues')
        (site / 'runtime/fail').touch()
        out, err = owner.communicate(timeout=8)
        assert owner.returncode == 1, (owner.returncode, out, err)
        assert commands('stopped') == EXPECTED - {'decoration:agent-worker'}, 'child failure left sibling queues alive'
        assert neighbour.poll() is None, 'child failure killed another project worker'
    finally:
        if owner.poll() is None:
            os.killpg(owner.pid, signal.SIGTERM)
            owner.communicate(timeout=8)
        neighbour.terminate()
        neighbour.wait(timeout=8)
print('PASS: current-queue startup, stop, restart, child-failure cleanup and other-project isolation')
