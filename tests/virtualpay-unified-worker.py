#!/usr/bin/env python3
"""Run the real launcher only in a disposable directory with fake PHP workers."""
import os,signal,subprocess,tempfile,time,shutil,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def until(fn,label):
 end=time.monotonic()+8
 while time.monotonic()<end:
  if fn():return
  time.sleep(.05)
 raise AssertionError(label)
with tempfile.TemporaryDirectory(prefix='virtual-unified-') as tmp:
 site=Path(tmp)/'site';(site/'scripts').mkdir(parents=True);(site/'runtime').mkdir();(site/'think').touch()
 shutil.copy(ROOT/'scripts/start-ai-task-worker.sh',site/'scripts')
 shutil.copy(ROOT/'scripts/home-agent-php.sh',site/'scripts')
 fake=Path(tmp)/'php';fake.write_text('''#!/usr/bin/env python3
import os,sys,time,signal
from pathlib import Path
if sys.argv[1]=='-r':sys.exit(0)
if sys.argv[1]=='-d':del sys.argv[1:3]
r=Path(sys.argv[1]).parent/'runtime';cmd=sys.argv[2]
with (r/'started').open('a') as f:f.write(f'{os.getpid()} {cmd}\\n')
if cmd=='virtualpay:maintain':
 if not (r/'failed_once').exists():(r/'failed_once').touch();sys.exit(7)
 (r/'active').write_text(str(os.getpid()))
 while not (r/'finish').exists():time.sleep(.03)
 (r/'finished').touch();sys.exit(0)
def stop(*args):
 signal.signal(signal.SIGTERM,signal.SIG_IGN)
 with (r/'stopped').open('a') as f:f.write(f'{os.getpid()} {cmd}\\n')
 sys.exit(0)
signal.signal(signal.SIGTERM,stop)
while True:time.sleep(.05)
''');fake.chmod(0o755)
 sleeper=Path(tmp)/'sleep';sleeper.write_text('#!/bin/sh\nif [ "$1" = 60 ]; then exec /bin/sleep 0.1; fi\nexec /bin/sleep "$@"\n');sleeper.chmod(0o755)
 env=dict(os.environ,PHP_BIN=str(fake),PATH=tmp+':'+os.environ['PATH'])
 p=subprocess.Popen(['/bin/dash' if '--dash' in sys.argv else '/bin/sh',str(site/'scripts/start-ai-task-worker.sh')],env=env,start_new_session=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 def entries():return (site/'runtime/started').read_text().splitlines() if (site/'runtime/started').exists() else []
 expected={'ai:task-worker','short-drama:episode-worker','short-drama:planning-worker','short-drama:canvas-agent-worker','canvas:subagent-worker','decoration:agent-worker','brand:worker','home-agent:worker'}
 try:
  until(lambda:(site/'runtime/active').exists(),'unified entry did not recover virtual maintenance after a failed iteration')
  rows=entries();ai=[r for r in rows if not r.endswith('virtualpay:maintain')]
  assert {r.split()[1] for r in ai}==expected and len(ai)==8,'existing workers restarted or omitted'
  assert len([r for r in rows if r.endswith('virtualpay:maintain')])==2,'failed iteration did not retry once'
  time.sleep(.25);assert entries()==rows,'maintenance iterations overlapped'
  if '--group' in sys.argv:os.killpg(p.pid,signal.SIGTERM)
  else:p.send_signal(signal.SIGTERM)
  time.sleep(.3)
  assert p.poll() is None,'launcher did not wait for active maintenance to drain'
  assert not (site/'runtime/finished').exists(),'maintenance was not active at shutdown'
  (site/'runtime/finish').touch();out,err=p.communicate(timeout=8)
  assert p.returncode==143,(p.returncode,out,err)
  assert (site/'runtime/finished').exists(),'active maintenance was killed before completion'
  assert entries()==rows,('shutdown started another iteration', rows,entries())
  until(lambda:(site/'runtime/stopped').exists(),'workers did not stop')
  assert len((site/'runtime/stopped').read_text().splitlines())==8,'existing shutdown contract changed'
 finally:
  (site/'runtime/finish').touch()
  if p.poll() is None:
   os.killpg(p.pid,signal.SIGTERM);p.communicate(timeout=8)
print('PASS unified virtual maintenance startup/retry/non-overlap/graceful drain and eight existing workers unchanged')
